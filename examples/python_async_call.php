<?php

use Amp\MultiProcess\Dispatcher,
    Amp\MultiProcess\CallResult,
    Amp\ReactorFactory;

require dirname(__DIR__) . '/autoload.php';

$workerCmd = '/usr/bin/python ' . __DIR__ . '/support_files/python_async_demo.py';

$reactor = (new ReactorFactory)->select();
$dispatcher = new Dispatcher($reactor, $workerCmd, $poolSize = 1);
$dispatcher->start();

$lastResult;

// ------------------------------------------------------------------------------------------------>

// Count how many times our function returns asynchronously before we stop the reactor
$count = 0;
$onResult = function(CallResult $result) use ($reactor, &$lastResult, &$count) {
    $count++;
    $lastResult = $result;
};

// ------------------------------------------------------------------------------------------------>

// Stop the program after the reactor runs for 0.25 seconds
$reactor->once(function() use ($reactor) {
    $reactor->stop();
}, $delayInSeconds = 0.25);

// ------------------------------------------------------------------------------------------------>

// Call our hello_world function as many times as possible before we stop the reactor
$reactor->schedule(function() use ($dispatcher, $onResult) {
    $dispatcher->call($onResult, 'hello_world');
});

// ------------------------------------------------------------------------------------------------>

// Release the hounds!
$reactor->run();

// How many times did we execute our asynchronous python function during the life of the script?
var_dump($count);

// What did we actually receive in the last result before we stopped?
var_dump($lastResult->getResult());
