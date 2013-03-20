<?php

use Amp\Async\Processes\ProcessDispatcher,
    Amp\Async\CallResult,
    Amp\ReactorFactory;

@date_default_timezone_set(date_default_timezone_get());

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support_files/MyAsyncFunctionCall.php';

$phpBinary    = '/usr/bin/php'; // Or something like C:/php/php.exe in windows
$workerScript = dirname(__DIR__) . '/workers/process_worker.php';
$userInclude  = __DIR__ . '/support_files/my_async_functions.php';
$workerCmd    = $phpBinary . ' ' . $workerScript . ' ' . $userInclude;


// Create the process dispatcher using the worker command we created above
$reactor = (new ReactorFactory)->select();
$dispatcher = new ProcessDispatcher($reactor, $workerCmd);
$dispatcher->start();


// Asynchronously call `sleep(5)` and exit the program when it returns
$afterSleep = function() use ($reactor) { $reactor->stop(); };
$reactor->once($delay = 0, function() use ($dispatcher, $afterSleep) {
    $dispatcher->call($afterSleep, 'sleep', 5);
});


// Output something every second to demonstrate that the sleep() call is asynchronous
$reactor->repeat($interval = 1, function() {
    echo "tick ", time(), "\n";
});


// Asynchronously call `str_rot13('my string')` as soon as the reactor starts (because we can)
$onRot13Result = function(CallResult $result) {
    echo "str_rot13() result: ", $result->getResult(), "\n";
};
$reactor->once($delay = 0, function() use ($dispatcher, $onRot13Result) {
    $dispatcher->call($onRot13Result, 'str_rot13', 'my string');
});


// Release the hounds!
$reactor->run();

