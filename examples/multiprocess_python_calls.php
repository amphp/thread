<?php

/**
 * AMP's interprocess messaging protocol makes it possible to dispatch calls to worker processes
 * operating in languages that aren't PHP. This demo dispatches calls to the same process-based
 * dispatcher used by PHP calls but instead routes them to a custom python script. 
 */

use Amp\UnserializedIoDispatcher, Amp\CallResult, Alert\ReactorFactory;

require __DIR__ . '/../vendor/autoload.php';

define('RUN_TIME_IN_SECONDS', 0.25);

$reactor = (new ReactorFactory)->select();
$workerCmd = '/usr/bin/python ' . __DIR__ . '/support/python_async_demo.py';
$dispatcher = new UnserializedIoDispatcher($reactor, $workerCmd, $workerProcessesToSpawn = 1);


// Count how many times our function returns asynchronously before we stop the reactor
$count = 0;
$onResult = function() use (&$count) { $count++; };

// Stop the program after the reactor runs for 0.25 seconds
$reactor->once(function() use ($reactor) {
    $reactor->stop();
}, $delay = RUN_TIME_IN_SECONDS);

// Call our python hello_world() function as many times as possible before we stop the reactor
$reactor->repeat(function() use ($dispatcher, $onResult) {
    $dispatcher->call($onResult, 'hello_world');
}, $intervalBetweenInvocations = 0);

// Release the hounds!
$reactor->run();

echo "Python hello_world() asynchronously returned {$count} times in ", RUN_TIME_IN_SECONDS, " seconds\n";
