<?php

use Alert\NativeReactor,
    Amp\PthreadsDispatcher,
    Amp\TaskResult;

require __DIR__ . '/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

function test() {
    return 'test';
}

function fatal() {
    $obj->nonexistent();
}

function throws() {
    throw new Exception('test');
}

$reactor = new NativeReactor;
$dispatcher = new PthreadsDispatcher($reactor);
$dispatcher->start(1);
$reactor->once(function() use ($reactor, $dispatcher) {
    $dispatcher->call('test', function(TaskResult $result) use ($reactor) {
        if ($result->succeeded()) {
            var_dump($result->getResult());
        } else {
            var_dump($result->getError()->getMessage());
        }
        $reactor->stop();
    });
}, 0.1);

$reactor->run();