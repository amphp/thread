<?php

use Amp\Async\Dispatcher,
    Amp\Async\CallResult,
    Amp\ReactorFactory;

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

require dirname(__DIR__) . '/autoload.php';

$workerCmd = '/usr/bin/python ' . dirname(__DIR__) . '/workers/python/demo.py';

$reactor = (new ReactorFactory)->select();
$dispatcher = new Dispatcher($reactor);
$dispatcher->serializeWorkload(FALSE); // Non-PHP workers don't want PHP workload serialization!
$dispatcher->start($poolSize = 4, $workerCmd);


$count = 0;
$onResult = function(CallResult $result) use ($reactor, &$count) {
    $count++;
};

// Stop the program after the reactor runs for 0.25 seconds
$reactor->once(0.25, function() use ($reactor) { $reactor->stop(); });

// Call our hello_world function as many times as possible before the reactor is stopped
$reactor->repeat($interval = 0, function() use ($dispatcher, $onResult) {
    $dispatcher->call($onResult, 'hello_world');
});

// Release the hounds!
$reactor->run();

// How many times did we execute our function over the life of the script?
var_dump($count);
