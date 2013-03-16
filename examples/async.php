<?php

use Amp\Messaging\ProcessPool,
    Amp\Reactor\ReactorFactory;

date_default_timezone_set('GMT');

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support_files/AsyncFunctionCall.php';

$reactor = (new ReactorFactory)->select();
$processPool = new ProcessPool($reactor);

$phpBinary    = '/usr/bin/php';
$workerScript = dirname(__DIR__) . '/workers/procedures.php';
$userInclude  = __DIR__ . '/support_files/custom_async_functions.php';
$workerCmd    = $phpBinary . ' ' . $workerScript . ' ' . $userInclude;

/**
 * Fire up 5 worker processes using the worker command we created above
 */
$processPool->start($workerCmd, $workers = 5);

/**
 * Every three seconds asynchronously call `str_rot13("my test string")`. The processing will
 * be handled by an available worker in the pool. If all the workers are busy, the task will be
 * queued until a worker is available. The timeout, if specified, applies from the time the call
 * is dispatched regardless of whether or not it is initially queued awaiting an available process.
 */
$repeatInterval = 2 * $reactor->getResolution();
$reactor->repeat($repeatInterval, function() use ($processPool) {
    $procedure = 'str_rot13';
    $args = 'my test string';
    $call = new AsyncFunctionCall($procedure, $args);
    
    $processPool->dispatch($call, $timeout = 0);
});

/**
 * Asynchronously call a custom userland function once per second. The built-in "procedures.php"
 * worker script takes a single command line argument specifying a userland PHP file for inclusion
 * at process boot time. By defining our own functions in the user include we can asynchronously
 * call any user function in addition to any native function.
 */
$repeatInterval = 1 * $reactor->getResolution();
$reactor->repeat($repeatInterval, function() use ($processPool) {
    $procedure = 'my_hello_world_function';
    $args = 'Dr. Zhivago';
    $call = new AsyncFunctionCall($procedure, $args);
    
    $processPool->dispatch($call, $timeout = 0);
});

/**
 * Stop the whole charade after 10 seconds
 */
$stopAfter = 10 * $reactor->getResolution();
$reactor->once($stopAfter, function() use ($reactor) {
   $reactor->stop();
});

$reactor->run();

