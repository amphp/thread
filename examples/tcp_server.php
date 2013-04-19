<?php

use Amp\ReactorFactory,
    Amp\TcpServer,
    Amp\Connection;

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

require dirname(__DIR__) . '/autoload.php';

/**
 * Select the best available reactor for your system
 */
$reactor = (new ReactorFactory)->select();

/**
 * Instantiate the server
 */
$timeServer = new TcpServer($reactor, '127.0.0.1:1337');

/**
 * Bind the server to the address::port we specified in the constructor and tell it how to respond
 * when a new client connects.
 */
$timeServer->listen(function(Connection $conn) {
    $msg = "\n--- The time is " . date('Y-m-d H:i:s') . " ---\n\n";
    
    if (!$conn->send($msg)) {
        while (!$conn->send());
    }
    
    $conn->close();
});

/**
 * Send a message to the console when we start the server
 */
$reactor->once(function() use ($timeServer) {
    echo "Time server started ...\n";
});


/**
 * Fire up the event reactor
 */
$reactor->run();

