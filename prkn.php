<?php

declare(ticks=1);

class Test {

    /**
     * run as a daemon
     * @var bool
     */
    public static $daemonize = false;

    /**
     * number of worker processes;
     * @var int
     */
    public static $workerCount = 4;


    public static $msgIpcWait = 0;

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
    public static $queueBlocking = true;

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
     * The variable of proxy processes in shared memory.
     */
    const PROXY_PROCESS_VAR_KEY = 101;

    /**
     * The variable of worker processes in shared memory.
     */
    const WORKER_PROCESS_VAR_KEY = 102;

    /**
     * The variable of number sign in shared memory.
     */
    const DISPATH_NUM_SIGN = 201;


    /**
     * run as a daemon
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
     * init shared memory and message queue.
     */
    protected static function shareMemoryAndMessageQueue() {
        // Creates or open a shared memory segment.
        self::$shmKey = $shmKey = ftok(__FILE__, 's');
        self::$shmId = $shmId = shm_attach($shmKey);
        // Checks whether specific key exists.
        shm_has_var($shmId, self::PROXY_PROCESS_VAR_KEY) && shm_remove_var($shmId, self::PROXY_PROCESS_VAR_KEY);
        shm_put_var($shmId, self::PROXY_PROCESS_VAR_KEY, []);
        shm_has_var($shmId, self::WORKER_PROCESS_VAR_KEY) && shm_remove_var($shmId, self::WORKER_PROCESS_VAR_KEY);
        shm_put_var($shmId, self::WORKER_PROCESS_VAR_KEY, []);
        !shm_has_var($shmId, self::DISPATH_NUM_SIGN) && shm_put_var($shmId, self::DISPATH_NUM_SIGN, 0);

        // Create or attach to a message queue.
        self::$msgKey = $msgKey = ftok(__FILE__, 'm');
        self::$queue = $msgId = msg_get_queue($msgKey);
    }

    /**
     * append proxy process id to variable of proxy in shared memory.
     * @param $proxyId int
     */
    protected static function appendProcessToShm($variable ,$proxyId) {
        $proxyIds = shm_get_var(self::$shmId, $variable);
        $proxyIds[] = $proxyId;
        shm_put_var(self::$shmId, $variable, $proxyIds);
    }

    /**
     * create proxy process of subscribe client.
     */
    protected static function subscribeProcess() {
        while (count(shm_get_var(self::$shmId, self::PROXY_PROCESS_VAR_KEY)) < 2) {
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
     * create redis subscribe client.
     * @param $pid proxy process id
     */
    protected static function redisSubscribe($pid) {
        $redis = new Redis();
        $redis->connect('127.0.0.1');
        $shmId = self::$shmId;
        $redis->subscribe(['__keyevent@0__:expired'], function($pattern, $channel, $message) use ($pid) {
            $proxyIds = shm_get_var(self::$shmId, self::PROXY_PROCESS_VAR_KEY);
            if ($pid == $proxyIds[0] || !posix_kill($proxyIds[1], 0)) {
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

                    msg_receive(self::$queue, $desrireMsgType, $mesgtype, 100, $message, self::$queueSerialize, self::$msgIpcWait);

                    // ... to do
                    file_put_contents('1.txt', $workerProcessId."--".$message."\n", FILE_APPEND);


                    // usleep(1);
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
     * check all processes are alive and remove dead process id from shared memory.
     * @param $variable
     */
    protected static function checkAliveAndDelProcessId($variable) {
        $processIds = shm_get_var(self::$shmId, $variable);
        foreach ($processIds as $key => $processId) {
            !posix_kill($processId, 0) && array_splice($processIds, $key, 1);
        }
        shm_put_var(self::$shmId, $variable, $processIds);
    }

    /**
     * initialize.
     */
    protected static function init() {
        while (true) {
            self::subscribeProcess();
            self::workerProcess();
            pcntl_waitpid(0, $status, WNOHANG);
            usleep(1);
        }
    }

    /**
     * start program.
     */
    public static function run() {
        self::$daemonize && self::daemon();
        self::shareMemoryAndMessageQueue();
        self::registerQuitSignal();
        self::init();
    }

}

Test::$preemptive = false;
Test::run();





















