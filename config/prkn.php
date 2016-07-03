<?php

return [

    // Run as a daemon
    'daemonize' => false,
    // The file path is used to convert to a System V IPC key
    'ftokPath' => '/tmp/prkn',

    //  msg_send($queue, $numSign, $message, $queueSerialize, $queueBlocking, $errCode);

    // How the message is sent.(msg_send())
    'queueSerialize' => true,
    // You can prevent  blockingby setting the optional blocking parameter to FALSE,
    // in which case msg_send() will immediately return FALSE if the message is too big for the queue,
    // and set the optional errorcode to MSG_EAGAIN, indicating that you should try to send your message again a little later on.
    'queueBlocking' => false,

    // msg_receive($queue, $desrireMsgType, $mesgtype, 100, $message, $queueSerialize, $msgRecFlag);

    // Number of worker processes
    'workerCount' => 4,
    // The optional flags allows you to pass flags to the low-level msgrcv system call when the program receive messages from message queue.
    'msgRecFlag' => 0,
    // Preemptive task distribution for worker processes.
    // $desrireMsgType = $preemptive ? 0 : number;
    //'preemptive' => true,

    // Subscribe to channel.
    // Support only one channel for a while.
    'channel' => ['__keyevent@0__:expired'],
];


