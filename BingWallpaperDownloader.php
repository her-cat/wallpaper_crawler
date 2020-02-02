<?php

use Predis\Client;
use Predis\ClientInterface;

class BingWallpaperDownloader
{
    /**
     * Redis 实例
     * @var ClientInterface
     */
    protected $redisClient;

    /**
     * 当前进程的 ID, 0:主进程，1:提取壁纸地址进程
     * @var int
     */
    protected $workerId = 0;

    /**
     * 主进程 PID
     * @var int
     */
    protected static $maserPid = 0;

    /**
     * 空闲时间
     * @var int
     */
    protected static $freeTime = 0;

    /**
     * 配置信息
     * @var array
     */
    protected static $options = [
        'daemonize'     => false,
        'worker_num'    => 3,
        'max_free_time' => 60,
        'save_dir'      => __DIR__.'/wallpaper',
        'queue_key'     => 'wallpaper_url_queue',
        'redis' => [
            'scheme' => 'tcp',
            'host'   => '127.0.0.1',
            'port'   => 6379,
        ],
    ];

    /**
     * 子进程的 PID
     * @var array
     */
    protected static $workers = [];

    /**
     * BingWallpaperDownloader constructor.
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        self::$options = \array_merge(self::$options, $options);
    }

    /**
     * 入口函数
     */
    public function run()
    {
        // 检查运行环境
        $this->checkEnv();
        // daemonize 化
        $this->daemonize();
        // 安装信号处理器
        $this->installSignalHandler();
        // 初始化 Redis
        $this->initRedis();
        // 初始化进程
        $this->initWorkers();
        // 监听子进程状态
        $this->monitor();
    }

    /**
     * 检查运行环境
     */
    protected function checkEnv()
    {
        if ('//' == \DIRECTORY_SEPARATOR) {
            exit('目前只支持 linux 系统'.PHP_EOL);
        }

        if (!\extension_loaded( 'pcntl') ) {
            exit('缺少 pcntl 扩展'.PHP_EOL);
        }

        if (!\extension_loaded( 'posix') ) {
            exit('缺少 posix 扩展'.PHP_EOL);
        }

        if (version_compare(PHP_VERSION, 7.1, '<')) {
            declare(ticks = 1);
        } else {
            // 启用异步信号处理
            \pcntl_async_signals(true);
        }
    }

    /**
     * 守护进程
     */
    protected function daemonize()
    {
        if (self::$options['daemonize'] !== true) {
            return;
        }

        // 设置当前进程创建的文件权限为 777
        umask(0);

        $pid = \pcntl_fork();
        if ($pid < 0) {
            $this->log('创建守护进程失败');
            exit;
        } else if ($pid > 0) {
            // 主进程退出
            exit(0);
        }

        // 将当前进程作为会话首进程
        if (\posix_setsid() < 0) {
            $this->log('设置会话首进程失败');
            exit;
        }

        // 两次 fork 保证形成的 daemon 进程绝对不会成为会话首进程
        $pid = \pcntl_fork();
        if ($pid < 0) {
            $this->log('创建守护进程失败');
            exit;
        } else if ($pid > 0) {
            // 主进程退出
            exit(0);
        }
    }

    /**
     * 初始化 Redis
     */
    protected function initRedis()
    {
        $this->redisClient = new Client(self::$options['redis']);
    }

    /**
     * 安装信号处理器
     */
    protected function installSignalHandler()
    {
        // 捕获 SIGINT 信号，终端中断
        \pcntl_signal(SIGINT, [$this, 'stopAllWorkers'], false);

        // 捕获 SIGPIPE 信号，忽略掉所有管道事件
        \pcntl_signal(\SIGPIPE, \SIG_IGN, false);
    }

    /**
     * 初始化 Workers
     */
    protected function initWorkers()
    {
        self::$maserPid = \posix_getpid();

        $this->forkWorker(1, [$this, 'extractWallpaperUrl']);

        $workerNum = (int) self::$options['worker_num'];

        for ($i = 0; $i < $workerNum; $i++) {
            $this->forkWorker($i + 2, [$this, 'downloadWallpaper']);
        }
    }

    /**
     * 创建子进程
     * @param $workerId
     * @param $callback
     */
    protected function forkWorker($workerId, $callback)
    {
        $pid = \pcntl_fork();

        if ($pid > 0) {
            // 父进程记录子进程 PID
            self::$workers[$workerId] = $pid;
        } elseif ($pid === 0) {
            // 子进程处理业务逻辑
            $this->workerId = $workerId;

            if ($callback instanceof \Closure) {
                $callback();
            } else if (isset($callback[1]) && is_object($callback[0])) {
                \call_user_func($callback);
            }

            exit(0);
        } else {
            $this->log('进程创建失败');
            exit;
        }
    }

    /**
     * 提取壁纸下载地址
     */
    protected function extractWallpaperUrl()
    {
        $this->log('提取壁纸地址进程启动...');

        $page = 1;

        do {
            $html = \file_get_contents("https://bing.ioliu.cn/?p={$page}");
            \preg_match_all('/<img([^>]*)\ssrc="([^\s>]+)"/', $html,$matches);

            if (empty($matches[2]) || \count($matches[2]) === 3) {
                $this->log('壁纸地址提取完毕, 当前页码: %s', $page);
                break;
            }

            $urls = \array_unique(\array_filter($matches[2]));

            if (!empty($urls)) {
                // 将壁纸 url 放入队列中
                $this->redisClient->sadd(self::$options['queue_key'], $urls);
            }

            $this->log('提取壁纸数量: %s, 当前页面: %s', count($urls), $page++);
        } while (true);
    }

    /**
     * 下载壁纸
     */
    protected function downloadWallpaper()
    {
        $this->log('下载壁纸进程启动...');

        while (self::$freeTime < self::$options['max_free_time']) {
            $url = $this->redisClient->spop(self::$options['queue_key']);

            if (empty($url)) {
                $this->log('空闲时间: %s/%ss', self::$freeTime++, self::$options['max_free_time']);
                \sleep(1);
                continue;
            }

            try {
                $result = $this->saveWallpaper($url);
                if (!$result) {
                    $this->redisClient->sadd(self::$options['queue_key'], [$url]);
                }
            } catch (\Exception $e) {
                $result = false;
                $this->log('保存壁纸异常: %s', $e->getMessage());
            }

            $this->log('壁纸下载%s, %s', $result ? '成功' : '失败', $url);
        }
    }

    /**
     * 保存壁纸到本地
     * @param $url
     * @return bool
     */
    protected function saveWallpaper($url)
    {
        $saveDir = \rtrim(self::$options['save_dir'], '/');

        if (!\is_dir($saveDir)) {
            \mkdir($saveDir);
        }

        $path = sprintf('%s/%s', $saveDir, \pathinfo($url, PATHINFO_BASENAME));
        if (\file_exists($path)) {
            return true;
        }

        $wallpaper = \fopen($url, 'rb');
        if (!$wallpaper) {
            return false;
        }

        $file = \fopen($path, 'wb');
        if (!$file) {
            \fclose($wallpaper);
            return false;
        }

        try {
            while (!\feof($wallpaper)) {
                \fwrite($file, \fread($wallpaper, 1024 * 8), 1024 * 8);
            }
        } catch (\Exception $e) {
            $this->log('保存壁纸异常: %s', $e->getMessage());
            return false;
        } finally {
            \fclose($wallpaper);
            \fclose($file);
        }

        return true;
    }

    /**
     * 监听子进程状态
     */
    protected function monitor()
    {
        while (true) {
            $pid = $this->acceptSignal();

            if ($pid > 0) {
                $this->log('子进程退出信号, PID: %s', $pid);
                // 翻转 workers 的键值
                $workers = \array_flip(self::$workers);
                $workerId = $workers[$pid];
                // 删除子进程
                unset(self::$workers[$workerId]);
                // 如果没有在运行的子进程则退出主进程
                count(self::$workers) === 0 && exit(0);
            } else {
                $this->log('其它信号, PID: %s', $pid);
                exit(0);
            }
        }
    }

    /**
     * 接收信号
     * @return int
     */
    protected function acceptSignal()
    {
        if (\version_compare(PHP_VERSION, 7.1, '>=')) {
            return \pcntl_wait($status, WUNTRACED);
        }

        // 调用等待信号的处理器
        \pcntl_signal_dispatch();
        $pid = \pcntl_wait($status, WUNTRACED);
        \pcntl_signal_dispatch();

        return $pid;
    }

    /**
     * 停止所有 Worker
     */
    protected function stopAllWorkers()
    {
        if (self::$maserPid !== \posix_getpid()) {
            // 子进程
            unset(self::$workers[$this->workerId]);
            exit(0);
        }

        // 父进程
        foreach (self::$workers as $pid) {
            // 给 worker 进程发送关闭信号
            \posix_kill($pid, SIGINT);
        }
    }

    /**
     * 记录日志
     * @param mixed ...$args
     */
    protected function log(...$args)
    {
        if (\count($args) == 0) {
            return;
        }

        $content = \sprintf(
            "[%s] [worker-%s] %s \r\n",
            \date('Y-m-d H:i:s'),
            $this->workerId,
            \array_shift($args)
        );

        \printf($content, ...$args);
    }
}