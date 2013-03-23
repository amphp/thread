<?php

use Amp\ReactorFactory,
    Amp\Async\Zmq\ZmqDispatcher;

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

require dirname(__DIR__) . '/autoload.php';


$reactor = (new ReactorFactory)->select();
$dispatcher = new ZmqDispatcher($reactor, 'tcp://127.0.0.1:5555');
$dispatcher->start();

$onResult = function($result) use ($reactor) { var_dump($result->getResult()); $reactor->stop(); };
$reactor->once(0, function() use ($dispatcher, $onResult) {
    $dispatcher->call($onResult, 'str_replace', 'e', 'x', 'eeeeeeeeexxxx');
});

$reactor->run();

