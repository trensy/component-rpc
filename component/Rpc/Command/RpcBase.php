<?php
/**
 * Trensy Framework
 *
 * PHP Version 7
 *
 * @author          kaihui.wang <hpuwang@gmail.com>
 * @copyright      trensy, Inc.
 * @package         trensy/framework
 * @version         1.0.7
 */

namespace Trensy\Component\Rpc\Command;

use Trensy\Config\Config;
use Trensy\Component\Rpc\RpcSerialization;
use Trensy\Component\Rpc\RpcServer;
use Trensy\Support\Arr;
use Trensy\Support\Dir;
use Trensy\Support\ElapsedTime;
use Trensy\Support\Exception;
use Trensy\Support\Log;

class RpcBase
{

    public static function operate($cmd, $output, $input)
    {
        ElapsedTime::setStartTime(ElapsedTime::SYS_START);
        $root = Dir::formatPath(ROOT_PATH);
        $config = Config::get("server.rpc");
        $appName = Config::get("server.name");

        if (!$appName) {
            Log::sysinfo("server.name not config");
            exit(0);
        }

        if (!$config) {
            Log::sysinfo("rpc config not config");
            exit(0);
        }

        if (!isset($config['server'])) {
            Log::sysinfo("rpc.server config not config");
            exit(0);
        }

        if ($input->hasOption("daemonize")) {
            $daemonize = $input->getOption('daemonize');
            $config['server']['daemonize'] = $daemonize == 0 ? 0 : 1;
        }

        if (!isset($config['server']['host'])) {
            Log::sysinfo("rpc.server.host config not config");
            exit(0);
        }

        if (!isset($config['server']['port'])) {
            Log::sysinfo("rpc.server.port config not config");
            exit(0);
        }
        
        try{
            self::doOperate($cmd, $config, $root, $appName, $output);
        }catch (\Exception $e){
            Log::error(Exception::formatException($e));
        }
    }


    public static function doOperate($command, array $config, $root, $appName, $output)
    {
        $defaultConfig = [
            'daemonize' => 0,
            //worker数量，推荐设置和cpu核数相等
            'worker_num' => 2,
            //reactor数量，推荐2
            'reactor_num' => 2,
            "dispatch_mode" => 2,
            "gzip" => 4,
            "static_expire_time" => 86400,
            "task_worker_num" => 5,
            "task_fail_log" => "/tmp/trensy/task_fail_log",
            "task_retry_count" => 2,
            "serialization" => 1,
            "mem_reboot_rate" => 0.8,
            //以下配置直接复制，无需改动
            'open_length_check' => 1,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
            'package_max_length' => 2000000,
            "pid_file" => "/tmp/trensy/pid",
            'open_tcp_nodelay' => 1,
        ];

        $config['server'] = Arr::merge($defaultConfig, $config['server']);

        if(isset($config['server']['log_file']) && !is_dir(dirname($config['server']['log_file']))){
            mkdir(dirname($config['server']['log_file']), "0777", true);
        }
        
        
        $serverName = $appName . "-rpc-master";
        exec("ps axu|grep " . $serverName . "|grep -v grep|awk '{print $2}'", $masterPidArr);
        $masterPid = $masterPidArr ? current($masterPidArr) : null;

        if ($command === 'start' && $masterPid) {
            Log::sysinfo("[$serverName] already running");
            return;
        }

        if ($command !== 'start' && $command !== 'restart' && !$masterPid) {
            Log::sysinfo("[$serverName] not run");
            return;
        }
        // execute command.
        switch ($command) {
            case 'status':
                if ($masterPid) {
                    Log::sysinfo("$serverName already running");
                } else {
                    Log::sysinfo("$serverName run");
                }
                break;
            case 'start':
                self::start($config, $root, $appName);
                break;
            case 'stop':
                self::stop($appName);
                Log::sysinfo("$serverName stop success ");
                break;
            case 'restart':
                $result = self::stop($appName);
                if($result){
                    self::start($config, $root, $appName);
                }
                break;
            case 'reload':
                self::reload($appName);
                Log::sysinfo("$serverName reload success ");
                break;
            default :
                return "";
        }
    }


    protected static function reload($appName)
    {
        $killStr = $appName . "-rpc-manage";
        exec("ps axu|grep " . $killStr . "|grep -v grep|awk '{print $2}'|xargs kill -USR1", $out, $result);
        return $result;
    }

    protected static function stop($appName)
    {
        $killStr = $appName . "-rpc";
        exec("ps axu|grep " . $killStr . "|grep -v grep|awk '{print $2}'|xargs kill -9", $out, $result);
        self::waitRunCmd("ps axu|grep " . $killStr . "|grep -v grep|awk '{print $2}'");
        return true;
    }

    protected static function waitRunCmd($cmd)
    {
        exec($cmd, $out, $result);
        if($out){
            sleep(1);
            self::waitRunCmd($cmd);
        }
        return true;
    }

    protected static function start($config, $root, $appName)
    {
        $swooleServer = new \swoole_server($config['server']['host'], $config['server']['port']);
        $routeSerialize = new RpcSerialization($config['server']['serialization'], $config['server']['package_body_offset']);
        $obj = new RpcServer($swooleServer, $routeSerialize, $config, $root, $appName);
        $obj->start();
    }
}