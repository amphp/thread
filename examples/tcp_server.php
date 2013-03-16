<?php

use Amp\Reactor\ReactorFactory,
    Amp\TcpServer;

date_default_timezone_set('GMT');

require dirname(__DIR__) . '/autoload.php';

/**
 * Select the best available reactor for your system
 */
$reactor = (new ReactorFactory)->select();

$timeServer = new TcpServer($reactor, '127.0.0.1', 1338);

/**
 * Bind the server to the address::port we specified in the constructor and tell it how to respond
 * when a new client connects.
 */
$timeServer->listen(function($clientSock, $peerName, $serverName) {
    $msg = 'The GMT time is ' . date('Y-m-d H:i:s') . "\n";
    
    // Client sockets accepted by the server are non-blocking by default. We turn blocking on
    // for this example to make our fwrite() operation atomic.
    stream_set_blocking($clientSock, FALSE);
    fwrite($clientSock, $msg);
    fclose($clientSock);
});

/**
 * Fire up the event reactor
 */
$reactor->run();

