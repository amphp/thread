<?php

use Amp\Async\Dispatcher,
    Amp\Async\CallResult,
    Amp\ReactorFactory;

date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

require dirname(__DIR__) . '/autoload.php';

$phpBinary    = PHP_BINARY;
$workerScript = dirname(__DIR__) . '/workers/php/worker.php';
$userInclude  = __DIR__ . '/support_files/my_async_functions.php';
$workerCmd    = $phpBinary . ' ' . $workerScript . ' ' . $userInclude;


// Create the process dispatcher using the worker command we created above
$reactor = (new ReactorFactory)->select();
$dispatcher = new Dispatcher($reactor);
$dispatcher->start($poolSize = 4, $workerCmd);


$afterSleep = function() use ($reactor) { $reactor->stop(); };

// Asynchronously call `sleep(5)` and exit the event loop when it returns
$reactor->once($delay = 0, function() use ($dispatcher, $afterSleep) {
    $dispatcher->call($afterSleep, 'sleep', 5);
});


// Output something every second to demonstrate that the sleep() call is asynchronous
$reactor->repeat($interval = 1, function() {
    echo "tick ", time(), "\n";
});


$onRot13Result = function(CallResult $result) {
    echo "str_rot13() result: ", $result->getResult(), "\n";
};

// Asynchronously call `str_rot13('my string')` as soon as the reactor starts (because we can)
$reactor->once($delay = 0, function() use ($dispatcher, $onRot13Result) {
    $dispatcher->call($onRot13Result, 'str_rot13', 'my string');
});


// Release the hounds!
$reactor->run();

