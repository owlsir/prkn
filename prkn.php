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
     * System V shared memory key.
     * @var int
     */
    protected static $shmKey;

    /**
     * Shared memory segment identifier.
     * @var resource
     */
    protected static $shmId;

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
     * The variable of master processes in shared memory.
     */
    const MASTER_PROCESS_VAR_KEY = 101;

    /**
     * The variable of proxy processes in shared memory.
     */
    const PROXY_PROCESS_VAR_KEY = 102;

    /**
     * The variable of worker processes in shared memory.
     */
    const WORKER_PROCESS_VAR_KEY = 103;

    /**
     * The variable of number sign in shared memory.
     */
    const DISPATH_NUM_SIGN = 201;

    /**
     * The variable of command in shared memory.
     */
    const COMMAND_STOP_VAR_KEY = 301;


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
     * Init shared memory and message queue.
     */
    protected static function shareMemoryAndMessageQueue() {

        // Checks whether specific key exists.
        shm_has_var(self::$shmId, self::PROXY_PROCESS_VAR_KEY) && shm_remove_var(self::$shmId, self::PROXY_PROCESS_VAR_KEY);
        shm_put_var(self::$shmId, self::PROXY_PROCESS_VAR_KEY, []);
        shm_has_var(self::$shmId, self::WORKER_PROCESS_VAR_KEY) && shm_remove_var(self::$shmId, self::WORKER_PROCESS_VAR_KEY);
        shm_put_var(self::$shmId, self::WORKER_PROCESS_VAR_KEY, []);
        !shm_has_var(self::$shmId, self::DISPATH_NUM_SIGN) && shm_put_var(self::$shmId, self::DISPATH_NUM_SIGN, 0);

        // Create or attach to a message queue.
        self::$msgKey = $msgKey = ftok(__FILE__, 'm');
        self::$queue = $msgId = msg_get_queue($msgKey);
    }

    /**
     * Append proxy process id to variable of proxy in shared memory.
     * @param int $proxyId
     */
    protected static function appendProcessToShm($variable ,$proxyId) {
        $proxyIds = shm_get_var(self::$shmId, $variable);
        $proxyIds[] = $proxyId;
        shm_put_var(self::$shmId, $variable, $proxyIds);
    }

    /**
     * Create proxy process of subscribe client.
     */
    protected static function subscribeProcess() {
        // when the proxy process number is less than 2 and the stop command is 0, the child process is created.
        while (count(shm_get_var(self::$shmId, self::PROXY_PROCESS_VAR_KEY))<2 && !shm_get_var(self::$shmId, self::COMMAND_STOP_VAR_KEY)) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                exit("fork(2) fail.\n");
            } else if (!$pid) {
                self::redisSubscribe(posix_getpid());
                exit;
            }

            self::appendProcessToShm(self::PROXY_PROCESS_VAR_KEY, $pid);

        }
    }

    /**
     * Create redis subscribe client.
     * @param $pid proxy process id
     */
    protected static function redisSubscribe($pid) {
        $redis = new Redis();
        $redis->connect('127.0.0.1');
        $shmId = self::$shmId;
        $redis->subscribe(['__keyevent@0__:expired'], function($pattern, $channel, $message) use ($pid) {
            $proxyIds = shm_get_var(self::$shmId, self::PROXY_PROCESS_VAR_KEY);
            if ($pid == $proxyIds[0] || !posix_kill($proxyIds[0], 0)) {
                $errCode = 0;
                $numSign = shm_get_var(self::$shmId, self::DISPATH_NUM_SIGN);
                // need do something when the queue is blocking.
                var_dump($numSign+1);
                msg_send(self::$queue, $numSign+1, $message, self::$queueSerialize, self::$queueBlocking, $errCode);
                // update num sign in shared memory. $numSign is desired message type of worker process .
                $newNumSign = ($numSign+1)%self::$workerCount;
                shm_put_var(self::$shmId, self::DISPATH_NUM_SIGN, $newNumSign);
            }
        });
    }

    /**
     * Create worker processes.
     */
    protected static function workerProcess() {
        while (count(shm_get_var(self::$shmId, self::WORKER_PROCESS_VAR_KEY)) < self::$workerCount) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                exit("fork(2) fail.\n");
            } else if (!$pid) {
                $workerProcessId = posix_getpid();
                while(true) {
                    $workerProcessIds = shm_get_var(self::$shmId, self::WORKER_PROCESS_VAR_KEY);
                    $desrireMsgType = self::$preemptive ? 0 : array_search($workerProcessId, $workerProcessIds, true)+1;

                    msg_receive(self::$queue, $desrireMsgType, $mesgtype, 100, $message, self::$queueSerialize, self::$msgRecFlag);

                    // ... do something ...
                    // file_put_contents('1.txt', $workerProcessId."--".$message."\n", FILE_APPEND);

                }
                exit;
            }

            self::appendProcessToShm(self::WORKER_PROCESS_VAR_KEY, $pid);

        }
    }

    /**
     *  Installs signal handler
     */
    protected static function registerQuitSignal() {
        pcntl_signal(SIGCHLD, [__CLASS__, 'sigchldCallback']);
    }

    /**
     * SIGCHLD signal callback handler.
     * When a child process dies, its parent is notified with a signal called SIGCHLD.
     */
    protected static function sigchldCallback() {
        self::checkAliveAndDelProcessId(self::PROXY_PROCESS_VAR_KEY);
        self::checkAliveAndDelProcessId(self::WORKER_PROCESS_VAR_KEY);
    }

    /**
     * Check all processes are alive and remove dead process id from shared memory.
     * @param int $variable
     */
    protected static function checkAliveAndDelProcessId($variable) {
        $processIds = shm_get_var(self::$shmId, $variable);
        foreach ($processIds as $key => $processId) {
            !posix_kill($processId, 0) && array_splice($processIds, $key, 1);
        }
        shm_put_var(self::$shmId, $variable, $processIds);
    }

    /**
     * Master process.
     * Monitor child processes.
     */
    protected static function masterProcess() {
        while (true) {
            self::subscribeProcess();
            self::workerProcess();
            pcntl_waitpid(0, $status, WNOHANG);
            usleep(1);
        }
    }


    protected static function command() {
        global $argv;
        $command = ['start', 'stop', 'restart', 'status'];

        (!isset($argv[1]) || !in_array($argv[1], $command)) && exit('Usage: '.$argv[0].' {'.join("|", $command).'}');

        if (isset($argv[2]) && $argv[2]=='-d') self::$daemonize = true;

        switch ($argv[0]) {
            case 'start':
                self::$masterRunning && self::cliOutputExit('The program aleady running');
                break;
            case 'stop':
                shm_put_var(self::$shmId, self::COMMAND_STOP_VAR_KEY, 1);

                // kill 
                $proxyProcessIds = shm_get_var(self::$shmId, self::PROXY_PROCESS_VAR_KEY);
                foreach ($proxyProcessIds as $key => $processId) {
                    exec('kill -9 '.$processId);
                }




                shm_remove(self::$shmId);







                break;
            case 'restart':

                break;
            case 'status':

                break;
        }


        self::$daemonize = isset($argv[2]) && $argv[2]=='-d' ? true : false;






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
        !file_exists(self::$ftokPath) && !touch(self::$ftokPath) && self::cliOutputExit('Failed to create tmp file.');
        self::$onFailRecord && !file_exists(self::$msgFailRrecordPath) && touch(self::$msgFailRrecordPath) && self::cliOutputExit('Failed to create the failed message log');

        // creates or open a shared memory segment.
        self::$shmKey = $shmKey = ftok(__FILE__, 's');
        self::$shmId = $shmId = shm_attach($shmKey);
        // shared momery of master process
        $masterProcessId = shm_has_var($shmId, self::MASTER_PROCESS_VAR_KEY) ? shm_get_var($shmId, self::MASTER_PROCESS_VAR_KEY) : false;
        // check the master process is alive.
        self::$masterRunning = $shmId ? posix_kill($masterProcessId, 0) : false;

        // initialize command stop status.
        shm_put_var($shmId, self::COMMAND_STOP_VAR_KEY, 0);
    }

    /**
     * Start program.
     */
    public static function run() {
        self::init();
        self::$daemonize && self::daemon();
        self::shareMemoryAndMessageQueue();
        self::registerQuitSignal();
        self::masterProcess();
    }

}

Test::$preemptive = false;
Test::run();





















