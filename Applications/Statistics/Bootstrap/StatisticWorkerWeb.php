<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Bootstrap;
use Workerman\Worker;
use Workerman\Lib\Timer;
use Statistics\Config;

use Workerman\Protocols\Http;
use Workerman\Protocols\HttpCache;

/**
 *  WebServer.
 */
class StatisticWorkerWeb extends Worker
{

    /**
     *  最大日志buffer，大于这个值就写磁盘
     * @var integer
     */
    const MAX_LOG_BUFFER_SZIE = 1024000;

    /**
     * 多长时间写一次数据到磁盘
     * @var integer
     */
    const WRITE_PERIOD_LENGTH = 60;

    /**
     * 多长时间清理一次老的磁盘数据
     * @var integer
     */
    const CLEAR_PERIOD_LENGTH = 86400;

    /**
     * 数据多长时间过期
     * @var integer
     */
    const EXPIRED_TIME = 1296000;

    /**
     * 统计数据
     * ip=>modid=>interface=>['code'=>[xx=>count,xx=>count],'suc_cost_time'=>xx,'fail_cost_time'=>xx, 'suc_count'=>xx, 'fail_count'=>xx]
     * @var array
     */
    protected $statisticData = array();

    /**
     * 日志的buffer
     * @var string
     */
    protected $logBuffer = '';

    /**
     * 放统计数据的目录
     * @var string
     */
    protected $statisticDir = 'statistic/statistic/';

    /**
     * 存放统计日志的目录
     * @var string
     */
    protected $logDir = 'statistic/log/';

    /**
     * 提供统计查询的socket
     * @var resource
     */
    protected $providerSocket = null;
    /**
     * Mime.
     *
     * @var string
     */
    protected static $defaultMimeType = 'text/html; charset=utf-8';

    /**
     * Virtual host to path mapping.
     *
     * @var array ['workerman.net'=>'/home', 'www.workerman.net'=>'home/www']
     */
    protected $serverRoot = array();

    /**
     * Mime mapping.
     *
     * @var array
     */
    protected static $mimeTypeMap = array();


    /**
     * Used to save user OnWorkerStart callback settings.
     *
     * @var callback
     */
    protected $_onWorkerStart = null;

    /**
     * Add virtual host.
     *
     * @param string $domain
     * @param string $root_path
     * @return void
     */
    public function addRoot($domain, $root_path)
    {
        $this->serverRoot[$domain] = $root_path;
    }

    /**
     * Construct.
     *
     * @param string $socket_name
     * @param array  $context_option
     */
    public function __construct($socket_name, $context_option = array())
    {
        list(, $address) = explode(':', $socket_name, 2);
        parent::__construct('http:' . $address, $context_option);
        $this->name = 'WorkerWebServer';
    }

    /**
     * Run webserver instance.
     *
     * @see Workerman.Worker::run()
     */
    public function run()
    {
        $this->_onWorkerStart = $this->onWorkerStart;
        $this->onWorkerStart  = array($this, 'onWorkerStart');
        $this->onMessage      = array($this, 'onMessage');
        $this->onWorkerStop      = array($this, 'onWorkerStop');
        parent::run();
    }

    /**
     * Emit when process start.
     *
     * @throws \Exception
     */
    public function onWorkerStart()
    {
        if (empty($this->serverRoot)) {
            throw new \Exception('server root not set, please use WebServer::addRoot($domain, $root_path) to set server root path');
        }
        // Init HttpCache.
        HttpCache::init();
        // Init mimeMap.
        $this->initMimeTypeMap();

        // Try to emit onWorkerStart callback.
        if ($this->_onWorkerStart) {
            try {
                call_user_func($this->_onWorkerStart, $this);
            } catch (\Exception $e) {
                echo $e;
                exit(250);
            } catch (\Error $e) {
                echo $e;
                exit(250);
            }
        }
    }

    /**
     * Init mime map.
     *
     * @return void
     */
    public function initMimeTypeMap()
    {
        $mime_file = Http::getMimeTypesFile();
        if (!is_file($mime_file)) {
            $this->log("$mime_file mime.type file not fond");
            return;
        }
        $items = file($mime_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($items)) {
            $this->log("get $mime_file mime.type content fail");
            return;
        }
        foreach ($items as $content) {
            if (preg_match("/\s*(\S+)\s+(\S.+)/", $content, $match)) {
                $mime_type                      = $match[1];
                $workerman_file_extension_var   = $match[2];
                $workerman_file_extension_array = explode(' ', substr($workerman_file_extension_var, 0, -1));
                foreach ($workerman_file_extension_array as $workerman_file_extension) {
                    self::$mimeTypeMap[$workerman_file_extension] = $mime_type;
                }
            }
        }
    }

    /**
     * Emit when http message coming.
     *
     * @param Connection\TcpConnection $connection
     * @return void
     */
    public function onMessage($connection)
    {
        // REQUEST_URI.
        $workerman_url_info = parse_url($_SERVER['REQUEST_URI']);
        if (!$workerman_url_info) {
            Http::header('HTTP/1.1 400 Bad Request');
            $connection->close('<h1>400 Bad Request</h1>');
            return;
        }

        $workerman_path = $workerman_url_info['path'];

        $workerman_path_info      = pathinfo($workerman_path);
        $workerman_file_extension = isset($workerman_path_info['extension']) ? $workerman_path_info['extension'] : '';
        if ($workerman_file_extension === '') {
            $workerman_path           = ($len = strlen($workerman_path)) && $workerman_path[$len - 1] === '/' ? $workerman_path . 'index.php' : $workerman_path . '/index.php';
            $workerman_file_extension = 'php';
        }

        $workerman_root_dir = isset($this->serverRoot[$_SERVER['SERVER_NAME']]) ? $this->serverRoot[$_SERVER['SERVER_NAME']] : current($this->serverRoot);

        $workerman_file = "$workerman_root_dir/$workerman_path";

        if ($workerman_file_extension === 'php' && !is_file($workerman_file)) {
            $workerman_file = "$workerman_root_dir/index.php";
            if (!is_file($workerman_file)) {
                $workerman_file           = "$workerman_root_dir/index.html";
                $workerman_file_extension = 'html';
            }
        }

        // File exsits.
        if (is_file($workerman_file)) {
            // Security check.
            if ((!($workerman_request_realpath = realpath($workerman_file)) || !($workerman_root_dir_realpath = realpath($workerman_root_dir))) || 0 !== strpos($workerman_request_realpath,
                    $workerman_root_dir_realpath)
            ) {
                Http::header('HTTP/1.1 400 Bad Request');
                $connection->close('<h1>400 Bad Request</h1>');
                return;
            }

            $workerman_file = realpath($workerman_file);

            // Request php file.
            if ($workerman_file_extension === 'php') {
                $workerman_cwd = getcwd();
                chdir($workerman_root_dir);
                ini_set('display_errors', 'off');
                ob_start();
                // Try to include php file.
                try {
                    // $_SERVER.
                    $_SERVER['REMOTE_ADDR'] = $connection->getRemoteIp();
                    $_SERVER['REMOTE_PORT'] = $connection->getRemotePort();
                    $messageData = $this->_parseMessageData();
                    $this->_onMessage($connection,$messageData);
                    /*include $workerman_file;*/
                } catch (\Exception $e) {
                    // Jump_exit?
                    if ($e->getMessage() != 'jump_exit') {
                        echo $e;
                    }
                }
                $content = ob_get_clean();
                ini_set('display_errors', 'on');
                $connection->close($content);
                chdir($workerman_cwd);
                return;
            }

            // Send file to client.
            return self::sendFile($connection, $workerman_file);
        } else {
            // 404
            Http::header("HTTP/1.1 404 Not Found");
            $connection->close('<html><head><title>404 File not found</title></head><body><center><h3>404 Not Found</h3></center></body></html>');
            return;
        }
    }

    protected function _parseMessageData(){
        $data = array(
            'module'=>'test',
            'interface'=>'test_interface',
            'cost_time'=>0.1,
            'success'=>'1',
            'time'=>time(),
            'code'=>'200',
            'msg'=>'success'
        );
        return $data;
    }

    /**
     * 业务处理
     * @see Man\Core.SocketWorker::dealProcess()
     */
    public function _onMessage($connection, $data)
    {
        // 解码
        $module = $data['module'];
        $interface = $data['interface'];
        $cost_time = $data['cost_time'];
        $success = $data['success'];
        $time = $data['time'];
        $code = $data['code'];
        $msg = str_replace("\n", "<br>", $data['msg']);
        $ip = $connection->getRemoteIp();

        // 模块接口统计
        $this->collectStatistics($module, $interface, $cost_time, $success, $ip, $code, $msg);
        // 全局统计
        $this->collectStatistics('WorkerMan', 'Statistics', $cost_time, $success, $ip, $code, $msg);

        // 失败记录日志
        if(!$success)
        {
            $this->logBuffer .= date('Y-m-d H:i:s',$time)."\t$ip\t$module::$interface\tcode:$code\tmsg:$msg\n";
            if(strlen($this->logBuffer) >= self::MAX_LOG_BUFFER_SZIE)
            {
                $this->writeLogToDisk();
            }
        }
        echo json_encode(array('errno'=>0,'content'=>'success','data'=>$data));
        return;
    }

    public static function sendFile($connection, $file_name)
    {
        // Check 304.
        $info = stat($file_name);
        $modified_time = $info ? date('D, d M Y H:i:s', $info['mtime']) . ' GMT' : '';
        if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $info) {
            // Http 304.
            if ($modified_time === $_SERVER['HTTP_IF_MODIFIED_SINCE']) {
                // 304
                Http::header('HTTP/1.1 304 Not Modified');
                // Send nothing but http headers..
                $connection->close('');
                return;
            }
        }

        // Http header.
        if ($modified_time) {
            $modified_time = "Last-Modified: $modified_time\r\n";
        }
        $file_size = filesize($file_name);
        $extension = pathinfo($file_name, PATHINFO_EXTENSION);
        $content_type = isset(self::$mimeTypeMap[$extension]) ? self::$mimeTypeMap[$extension] : self::$defaultMimeType;
        $header = "HTTP/1.1 200 OK\r\n";
        $header .= "Content-Type: $content_type\r\n";
        $header .= "Connection: keep-alive\r\n";
        $header .= $modified_time;
        $header .= "Content-Length: $file_size\r\n\r\n";
        $trunk_limit_size = 1024*1024;
        if ($file_size < $trunk_limit_size) {
            return $connection->send($header.file_get_contents($file_name), true);
        }
        $connection->send($header, true);

        // Read file content from disk piece by piece and send to client.
        $connection->fileHandler = fopen($file_name, 'r');
        $do_write = function()use($connection)
        {
            // Send buffer not full.
            while(empty($connection->bufferFull))
            {
                // Read from disk.
                $buffer = fread($connection->fileHandler, 8192);
                // Read eof.
                if($buffer === '' || $buffer === false)
                {
                    return;
                }
                $connection->send($buffer, true);
            }
        };
        // Send buffer full.
        $connection->onBufferFull = function($connection)
        {
            $connection->bufferFull = true;
        };
        // Send buffer drain.
        $connection->onBufferDrain = function($connection)use($do_write)
        {
            $connection->bufferFull = false;
            $do_write();
        };
        $do_write();
    }

    /**
     * 收集统计数据
     * @param string $module
     * @param string $interface
     * @param float $cost_time
     * @param int $success
     * @param string $ip
     * @param int $code
     * @param string $msg
     * @return void
     */
    protected function collectStatistics($module, $interface , $cost_time, $success, $ip, $code, $msg)
    {
        // 统计相关信息
        if(!isset($this->statisticData[$ip]))
        {
            $this->statisticData[$ip] = array();
        }
        if(!isset($this->statisticData[$ip][$module]))
        {
            $this->statisticData[$ip][$module] = array();
        }
        if(!isset($this->statisticData[$ip][$module][$interface]))
        {
            $this->statisticData[$ip][$module][$interface] = array('code'=>array(), 'suc_cost_time'=>0, 'fail_cost_time'=>0, 'suc_count'=>0, 'fail_count'=>0);
        }
        if(!isset($this->statisticData[$ip][$module][$interface]['code'][$code]))
        {
            $this->statisticData[$ip][$module][$interface]['code'][$code] = 0;
        }
        $this->statisticData[$ip][$module][$interface]['code'][$code]++;
        if($success)
        {
            $this->statisticData[$ip][$module][$interface]['suc_cost_time'] += $cost_time;
            $this->statisticData[$ip][$module][$interface]['suc_count'] ++;
        }
        else
        {
            $this->statisticData[$ip][$module][$interface]['fail_cost_time'] += $cost_time;
            $this->statisticData[$ip][$module][$interface]['fail_count'] ++;
        }
    }

    /**
     * 将统计数据写入磁盘
     * @return void
     */
    public function writeStatisticsToDisk()
    {
        $time = time();
        // 循环将每个ip的统计数据写入磁盘
        foreach($this->statisticData as $ip => $mod_if_data)
        {
            foreach($mod_if_data as $module=>$items)
            {
                // 文件夹不存在则创建一个
                $file_dir = Config::$dataPath . $this->statisticDir.$module;
                if(!is_dir($file_dir))
                {
                    umask(0);
                    mkdir($file_dir, 0777, true);
                }
                // 依次写入磁盘
                foreach($items as $interface=>$data)
                {
                    file_put_contents($file_dir. "/{$interface}.".date('Y-m-d'), "$ip\t$time\t{$data['suc_count']}\t{$data['suc_cost_time']}\t{$data['fail_count']}\t{$data['fail_cost_time']}\t".json_encode($data['code'])."\n", FILE_APPEND | LOCK_EX);
                }
            }
        }
        // 清空统计
        $this->statisticData = array();
    }

    /**
     * 将日志数据写入磁盘
     * @return void
     */
    public function writeLogToDisk()
    {
        // 没有统计数据则返回
        if(empty($this->logBuffer))
        {
            return;
        }
        // 写入磁盘
        file_put_contents(Config::$dataPath . $this->logDir . date('Y-m-d'), $this->logBuffer, FILE_APPEND | LOCK_EX);
        $this->logBuffer = '';
    }

    /**
     * 初始化
     * 统计目录检查
     * 初始化任务
     * @see Man\Core.SocketWorker::onStart()
     */
    protected function _onStart()
    {
        // 初始化目录
        umask(0);
        $statistic_dir = Config::$dataPath . $this->statisticDir;
        if(!is_dir($statistic_dir))
        {
            mkdir($statistic_dir, 0777, true);
        }
        $log_dir = Config::$dataPath . $this->logDir;
        if(!is_dir($log_dir))
        {
            mkdir($log_dir, 0777, true);
        }
        // 定时保存统计数据
        Timer::add(self::WRITE_PERIOD_LENGTH, array($this, 'writeStatisticsToDisk'));
        Timer::add(self::WRITE_PERIOD_LENGTH, array($this, 'writeLogToDisk'));
        // 定时清理不用的统计数据
        Timer::add(self::CLEAR_PERIOD_LENGTH, array($this, 'clearDisk'), array(Config::$dataPath . $this->statisticDir, self::EXPIRED_TIME));
        Timer::add(self::CLEAR_PERIOD_LENGTH, array($this, 'clearDisk'), array(Config::$dataPath . $this->logDir, self::EXPIRED_TIME));

    }

    /**
     * 进程停止时需要将数据写入磁盘
     * @see Man\Core.SocketWorker::onStop()
     */
    protected function onWorkerStop()
    {
        $this->writeLogToDisk();
        $this->writeStatisticsToDisk();
    }

    /**
     * 清除磁盘数据
     * @param string $file
     * @param int $exp_time
     */
    public function clearDisk($file = null, $exp_time = 86400)
    {
        $time_now = time();
        if(is_file($file))
        {
            $mtime = filemtime($file);
            if(!$mtime)
            {
                $this->notice("filemtime $file fail");
                return;
            }
            if($time_now - $mtime > $exp_time)
            {
                unlink($file);
            }
            return;
        }
        foreach (glob($file."/*") as $file_name)
        {
            $this->clearDisk($file_name, $exp_time);
        }
    }
}
