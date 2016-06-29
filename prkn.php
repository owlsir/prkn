<?php

declare(ticks=1);

class Test {

    /**
     * run as a daemon
     * @var bool
     */
    public static $daemonize = false;

    /**
     * A file path is used to convert to a System V IPC key;
     * @var string
     */
    public static $ftokPath = '/tmp/prkn';

    /**
     * Turn on failed message log.
     * @var bool
     */
    public static $onFailRecord = true;

    /**
     * A file path to record failed message.
     * @var string
     */
    public static $msgFailRrecordPath = './log/msg_fail_record.rec';

    /**
     * Turn on log.
     * @var bool
     */
    public static $onLog = true;

    /**
     * Log path.
     * @var string
     */
    public static $logFile = './log/prkn.log';

    /**
     * Whether the master process is running.
     * @var bool
     */
    protected static $masterRunning;

    /**
     * number of worker processes;
     * @var int
     */
    public static $workerCount = 4;

    /**
     * The optional flags allows you to pass flags to the low-level msgrcv system call when the program receive messages from message queue.
     * @var int
     */
    public static $msgRecFlag = 0;

    /**
     * How the message is sent.
     * @var bool
     */
    public static $queueSerialize = true;

    /**
     * You can prevent  blockingby setting the optional blocking parameter to FALSE,
     * in which case msg_send() will immediately return FALSE if the message is too big for the queue,
     * and set the optional errorcode to MSG_EAGAIN, indicating that you should try to send your message again a little later on.
     * @var bool
     */
    public static $queueBlocking = false;

    /**
     * Preemptive task distribution for worker processes.
     * @var bool
     */
    public static $preemptive = true;

    /**
     * System V IPC key.
     * @var int
     */
    protected static $msgKey ;

    /**
     * Resource handle that can be used to access the System V message queue.
     * @var resource
     */
    protected static $queue;

    /**
     * Stored proxy processes.
     * @var array
     */
    protected static $proxyProcessIds = [];

    /**
     * Stored worker processes.
     * @var array
     */
    protected static $workerProcessIds = [];

    /**
     * Sends(msg_send()) a message of type msgtype ($numSign+1).
     * @var int
     */
    protected static $numSign = 0;

    /**
     * Wheter to stop.
     * @var bool
     */
    protected static $commandStop = false;

    /**
     * File path for stored master process id.
     * @var string
     */
    protected static $pidPath = __FILE__.'.pid';

    /**
     * Run as a daemon
     */
    protected static function daemon() {
        $pid = pcntl_fork();

        if ($pid == -1) {
            exit("fork(1) fail.\n");
        } else if ($pid) {
            exit;
        }

        posix_setsid();

        $pid = pcntl_fork();

        if ($pid == -1) {
            exit("fork(2) fail.\n");
        } else if ($pid) {
            exit;
        }

    }

    /**
     * Create proxy process of subscribe client.
     */
    protected static function subscribeProcess() {
        // when the proxy process number is less than 2 and the stop command is 0, the child process is created.
        while (count(self::$proxyProcessIds)<2 && !self::$commandStop) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                exit("fork(2) fail.\n");
            } else if (!$pid) {
                self::redisSubscribe();
                exit;
            }
            self::$proxyProcessIds[] = $pid;
            usleep(1000);
        }
    }

    /**
     * Create redis subscribe client.
     * @param int $pid proxy process id
     */
    protected static function redisSubscribe() {
        $redis = new Redis();
        $redis->connect('127.0.0.1');
        $redis->subscribe(['__keyevent@0__:expired'], function($pattern, $channel, $message) {
            if (posix_getpid() == self::$proxyProcessIds[0] || !posix_kill(self::$proxyProcessIds[0], 0)) {
                $errCode = 0;
                // need do something when the queue is blocking.
                $numSign = self::$numSign+1;
                msg_send(self::$queue, self::$numSign, $message, self::$queueSerialize, self::$queueBlocking, $errCode);
                // update num sign in shared memory. $numSign is desired message type of worker process .
                self::$numSign = $numSign%self::$workerCount;
            }
        });
    }

    /**
     * Create worker processes.
     */
    protected static function workerProcess() {
        while (count(self::$workerProcessIds) < self::$workerCount && !self::$commandStop) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                exit("fork(2) fail.\n");
            } else if (!$pid) {
                pcntl_signal(SIGUSR2, [__CLASS__, 'workerStopCallBack']);
                $workerProcessId = posix_getpid();
                while(true) {
                    $desrireMsgType = self::$preemptive ? 0 : array_search($workerProcessId, self::$workerProcessIds, true)+1;
                    msg_receive(self::$queue, $desrireMsgType, $mesgtype, 100, $message, self::$queueSerialize, self::$msgRecFlag);

                    // ... do something ...
                    // file_put_contents('1.txt', $workerProcessId."--".$message."\n", FILE_APPEND);


                    if (self::$commandStop) break;
                    usleep(1);
                }
                exit;
            }
            self::$workerProcessIds[] = $pid;
        }
    }

    /**
     * The worker process call this function when receive sigusr2 signal.
     */
    protected static function workerStopCallBack() {
        self::$commandStop = true;
    }


    /**
     *  Installs signal handler
     */
    protected static function registerQuitSignal() {
        pcntl_signal(SIGCHLD, [__CLASS__, 'checkAliveAndDelProcessId']);
        pcntl_signal(SIGUSR1, [__CLASS__, 'stopAllCallBack']);
    }

    /**
     * Stop all processes
     */
    protected static function stopAllCallBack() {
        // stop sign
        self::$commandStop = true;

        // kill proxy processes.
        foreach (self::$proxyProcessIds as $key => $processId) {
            exec('kill -9 '.$processId);
        }

        // stop worker processes.
        foreach (self::$workerProcessIds as $processId) {
            posix_kill($processId, SIGUSR2);
        }

        if (self::$msgRecFlag === 0) {
            // blocking
            foreach (self::$workerProcessIds as $key => $processId) {
                exec('kill -9 '.$processId);
            }
        } else {
            while (true) {
                $workerProcessIds = self::$workerProcessIds;
                if (!is_array($workerProcessIds) || empty($workerProcessIds)) break;
                pcntl_waitpid(0, $status, WNOHANG);
                self::checkAliveAndDelProcessId();
                usleep(1000);
            }
        }

        // clear message queue.
        msg_remove_queue(self::$queue);

        // stop master process.
        exit;
    }

    /**
     * SIGCHLD signal callback handler.
     * When a child process dies, its parent is notified with a signal called SIGCHLD.
     */
    protected static function sigchldCallback() {

    }

    /**
     * Check all processes are alive and remove dead process id from shared memory.
     * @param int $variable
     */
    protected static function checkAliveAndDelProcessId() {
        $proxyProcessIds = self::$proxyProcessIds;
        foreach ($proxyProcessIds as $key => $processId) {
            !posix_kill($processId, 0) && array_splice($proxyProcessIds, array_search($processId, $proxyProcessIds), 1);
        }
        self::$proxyProcessIds = $proxyProcessIds;

        $workerProcessIds = self::$workerProcessIds;
        foreach ($workerProcessIds as $key => $processId) {
            !posix_kill($processId, 0) && array_splice($workerProcessIds, array_search($processId, $workerProcessIds), 1);
        }
        self::$workerProcessIds = $workerProcessIds;
    }

    /**
     * Master process.
     * Monitor child processes.
     */
    protected static function masterProcess() {
        file_put_contents(self::$pidPath, posix_getpid());
        while (true) {
            self::subscribeProcess();
            self::workerProcess();
            pcntl_waitpid(0, $status, WNOHANG);
            usleep(1000);
        }
    }

    /**
     * Create a file based on the path.
     * @param string $path file path
     * @return bool
     */
    protected static function createFile($path) {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) return false;
        return touch($path);
    }

    /**
     * The arguments passed to the script.
     */
    protected static function command() {
        global $argv;
        $command = ['start', 'stop'];
        (!isset($argv[1]) || !in_array($argv[1], $command)) && self::cliOutputExit('Usage: '.$argv[0].' {'.join("|", $command).'}', true);
        if (isset($argv[2]) && $argv[2]=='-d') self::$daemonize = true;
        switch ($argv[1]) {
            case 'start':
                self::$masterRunning && self::cliOutputExit('The prkn aleady running.', true);
                break;
            case 'stop':
                !self::$masterRunning && self::cliOutputExit("Prkn does not run.", true);
                self::cliOutputExit("Prkn is stopping.");
                $masterProcessId = file_get_contents(self::$pidPath);
                posix_kill($masterProcessId, SIGUSR1);
                while (true) {
                    if (!posix_kill($masterProcessId, 0)) break;
                    usleep(1000);
                }
                self::cliOutputExit("Prkn stop success.", true);
                break;
            default:
                self::cliOutputExit('Usage: '.$argv[0].' {'.join("|", $command).'}', true);
                break;
        }
    }

    /**
     * Command line output and exit.
     * @param string $message
     * @param bool $exit
     */
    protected static function cliOutputExit($message, $exit=false) {
        echo $message."\n";
        $exit && exit;
    }

    /**
     * Initializa.
     */
    protected static function init() {
        !file_exists(self::$ftokPath) && !self::createFile(self::$ftokPath) && self::cliOutputExit('Failed to create tmp file.', true);
        // self::$onFailRecord && !file_exists(self::$msgFailRrecordPath) && !self::createFile(self::$msgFailRrecordPath) && self::cliOutputExit('Failed to create the failed message log', true);
        !file_exists(self::$pidPath) && !self::createFile(self::$pidPath) && self::cliOutputExit('Failed to create pid file.', true);

        // get master process id
        $masterProcessId = file_get_contents(self::$pidPath);
        // check the master process is alive.
        self::$masterRunning = $masterProcessId ? posix_kill($masterProcessId, 0) : false;

        // Create or attach to a message queue.
        self::$msgKey = ftok(__FILE__, 'm');
        self::$queue = msg_get_queue(self::$msgKey);
    }

    /**
     * Start program.
     */
    public static function run() {
        self::init();
        self::command();
        self::$daemonize && self::daemon();
        self::registerQuitSignal();
        self::masterProcess();
    }

}

//Test::$msgRecFlag = MSG_IPC_NOWAIT;
Test::$preemptive = false;
Test::run();



