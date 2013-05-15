<?php

use Amp\Async\PhpDispatcher,
    Amp\Async\CallResult,
    Amp\ReactorFactory;

require dirname(__DIR__) . '/autoload.php';

$asyncFunctions  = __DIR__ . '/support_files/my_async_functions.php';

$reactor = (new ReactorFactory)->select();
$dispatcher = new PhpDispatcher($reactor, $asyncFunctions, $poolSize = 4);
$dispatcher->start();

// ------------------------------------------------------------------------------------------------>

// Asynchronously call sleep(3) and exit the event loop when it returns. 
$reactor->once(function() use ($dispatcher, $reactor) {
    $afterSleep = function() use ($reactor) { $reactor->stop(); };
    $dispatcher->call($afterSleep, 'sleep', 3);
});

// ------------------------------------------------------------------------------------------------>

// Echo each second in the main process to demonstrate that the sleep() call we made is asynchronous
$reactor->repeat(function() {
    echo "tick ", time(), "\n";
}, $interval = 1);


// ------------------------------------------------------------------------------------------------>

$onResult = function(CallResult $result) {
    if ($result->isSuccess()) {
        echo "str_rot13() result: ", $result->getResult(), "\n";
    } else {
        $exception = $result->getError();
        echo $exception->getMessage(), "\n";
    }
};

// Asynchronously call str_rot13('my string') as soon as the reactor starts
$reactor->once(function() use ($dispatcher, $onResult) {
    $dispatcher->call($onResult, 'str_rot13', 'my string');
});

// ------------------------------------------------------------------------------------------------>

// Asynchronously call my_hello_function() (
$reactor->once(function() use ($dispatcher) {
    $onResult = function($r) { echo 'my_hello_function() result: ', $r->getResult(), "\n"; };
    $dispatcher->call($onResult, 'my_hello_function', 'Arthur Dent');
});

// ------------------------------------------------------------------------------------------------>

// Release the hounds! The event reactor *is* our task scheduler. Nothing happens until we tell the
// event reactor to run.
$reactor->run();

