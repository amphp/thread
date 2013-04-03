<?php

use Amp\ReactorFactory,
    Amp\Server\TcpServer;

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

require dirname(__DIR__) . '/autoload.php';

/**
 * Select the best available reactor for your system
 */
$reactor = (new ReactorFactory)->select();

/**
 * Instantiate the server
 */
$timeServer = new TcpServer($reactor, '127.0.0.1', 1337);

/**
 * Bind the server to the address::port we specified in the constructor and tell it how to respond
 * when a new client connects.
 */
$timeServer->listen(function($clientSock, $peerName, $serverName) {
    $msg = 'The time is ' . date('Y-m-d H:i:s') . "\n";
    
    // Client sockets accepted by the server are non-blocking by default. We turn blocking on
    // for this example to make our fwrite() operation atomic.
    stream_set_blocking($clientSock, TRUE);
    fwrite($clientSock, $msg);
    fclose($clientSock);
});

/**
 * Send a message to the console when we start the server
 */
$reactor->once(function() use ($timeServer) {
    $addr = $timeServer->getAddress();
    $port = $timeServer->getPort();
    
    echo "Time server started on {$addr}:{$port}", "\n";
    echo "To retrieve the current time, telnet in like so:", "\n\n";
    echo "\t$ telnet {$addr} {$port}", "\n\n";
});


/**
 * Fire up the event reactor
 */
$reactor->run();

