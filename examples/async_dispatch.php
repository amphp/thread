<?php

use Amp\Async\Processes\ProcessDispatcher,
    Amp\ReactorFactory;

date_default_timezone_set('GMT');

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/support_files/MyAsyncFunctionCall.php';

$phpBinary    = '/usr/bin/php'; // Or something like C:/php/php.exe in windows
$workerScript = dirname(__DIR__) . '/workers/process_worker.php';
$userInclude  = __DIR__ . '/support_files/my_async_functions.php';
$workerCmd    = $phpBinary . ' ' . $workerScript . ' ' . $userInclude;


/**
 * Create the process dispatcher using the worker command created above
 */
$reactor = (new ReactorFactory)->select();
$dispatcher = new ProcessDispatcher($reactor, $workerCmd);

/**
 * The process dispatcher will spawn five workers in its pool by default. We don't need that many
 * for our example se we'll tell the dispatcher to only spawn one process.
 */
$dispatcher->setMaxWorkers(1);

/**
 * Start the process dispatcher (fill the process pool).
 */
$dispatcher->start();

/**
 * Generate a couple of tasks for asynchronous dispatch
 */
$rot13 = new MyAsyncFunctionCall('str_rot13', 'my test string');
$hello = new MyAsyncFunctionCall('my_hello_world_function', "@Lusitanian's Mom");

/**
 * Alternate asynchronous calls to native str_rot13() and userland my_hello_world_function()
 */
$reactor->repeat($interval = 1, function() use ($dispatcher, $rot13, $hello) {
    $call = (time() % 2 == 0) ? $rot13 : $hello;
    $dispatcher->dispatch($call);
});

/**
 * Stop this spurious example after 10 seconds
 */
$reactor->once($stopAfter = 10, function() use ($reactor) {
   $reactor->stop();
});

/**
 * Release the hounds!
 */
$reactor->run();

