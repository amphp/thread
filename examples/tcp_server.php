<?php

use Amp\ReactorFactory,
    Amp\TcpServer;

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

require dirname(__DIR__) . '/autoload.php';

class TimeServer extends TcpServer {
    
    protected function onClient($socket) {
        $msg = "\n--- The time is " . date('Y-m-d H:i:s') . " ---\n\n";
        $unsentBytes = strlen($msg);
        
        while ($unsentBytes) {
            $bytesSent = fwrite($socket, $msg);
            $unsentBytes -= $bytesSent;
            if ($unsentBytes) {
                $msg = substr($bytesSent, $msg);
            }
        }
        
        fclose($socket);
    }
}

$reactor = (new ReactorFactory)->select();
$timeServer = (new TimeServer($reactor))->defineBinding('127.0.0.1:1337')->start();

