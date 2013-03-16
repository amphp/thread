<?php

use Amp\Messaging\ProcessManager,
    Amp\Reactor\ReactorFactory;

date_default_timezone_set('GMT');

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support_files/AsyncFunctionCall.php';

$reactor = (new ReactorFactory)->select();
$processManager = new ProcessManager($reactor);

$phpBinary    = '/usr/bin/php';
$workerScript = dirname(__DIR__) . '/workers/procedures.php';
$userInclude  = __DIR__ . '/support_files/custom_async_functions.php';
$cmd          = $phpBinary . ' ' . $workerScript . ' ' . $userInclude;

/**
 * Fire up 5 worker processes using the command we created
 */
$processManager->start($cmd, $workers = 5);

/**
 * Every three seconds asynchronously call `str_rot13("my test string")`. The processing will
 * be handled by an available worker in the pool. If all the workers are busy, the task will be
 * queued until a worker is available. The timeout, if specified, applies from the time the call
 * is dispatched regardless of whether or not it is initially queued awaiting an available process.
 */
$repeatInterval = 2 * $reactor->getResolution();
$reactor->repeat($repeatInterval, function() use ($processManager) {
    $procedure = 'str_rot13';
    $args = 'my test string';
    $call = new AsyncFunctionCall($procedure, $args);
    
    $processManager->dispatch($call, $timeout = 0);
});

/**
 * Asynchronously call a custom userland function once each second.
 */
$repeatInterval = 1 * $reactor->getResolution();
$reactor->repeat($repeatInterval, function() use ($processManager) {
    $procedure = 'my_hello_world_function';
    $args = 'Frankenstein';
    $call = new AsyncFunctionCall($procedure, $args);
    
    $processManager->dispatch($call, $timeout = 0);
});

/**
 * Stop the whole charade after 10 seconds
 */
$stopAfter = 10 * $reactor->getResolution();
$reactor->once($stopAfter, function() use ($reactor) {
   $reactor->stop();
});

$reactor->run();

