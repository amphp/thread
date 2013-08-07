<?php

/**
 * The Amp dispatcher transparently recovers from fatal errors in your worker processes. If you
 * manage to kill a worker in your async functions the relevant CallResult will be an error response.
 * Any other calls queued for execution by the worker process that died are subsequently reallocated
 * to a new worker. This is a relatively expensive operation because AMP must spawn a new process
 * each time a worker errors out. While Amp can recover from such errors, you should endeavor to
 * avoid fatal errors in your code altogether.
 *
 * Information about the fatal error is written to the main process's STDERR stream.
 *
 * In the following example we dispatch a function call that we know will generate a fatal E_ERROR
 * and crash the worker. To demonstrate that the dispatcher recovers from this event, we make a
 * call to a different function (one that won't error out) as well.
 */

use Amp\IoDispatcher, Amp\CallResult, Alert\ReactorFactory;

require dirname(__DIR__) . '/autoload.php';

$asyncFunctions  = __DIR__ . '/support/my_async_functions.php';
$reactor = (new ReactorFactory)->select();
$dispatcher = new IoDispatcher($reactor, $asyncFunctions, $workerProcessesToSpawn = 1);

// ------------------------------------------------------------------------------------------------>

$onFatalResult = function(CallResult $r) {
    $isWin = (stripos(PHP_OS, 'win') === 0);
    $msg = "\n^^ STDERR output passes through from the worker process\n\n";
    echo $isWin ? $msg : "\033[1;33m{$msg}\033[0m";
    $errMsg = $r->getError()->getMessage() . "\n";
    echo $isWin ? $errMsg : "\033[1;31m{$errMsg}\033[0m";
};

$onHelloResult = function($result) use ($reactor) {
    echo "my_hello_function() (dispatched after fatal): ", $result->getResult(), "\n\n";
    $reactor->stop();
};

$reactor->immediately(function() use ($dispatcher, $onFatalResult, $onHelloResult) {
    $dispatcher->call($onFatalResult, 'my_fatal_function');
    $dispatcher->call($onHelloResult, 'my_hello_function', 'World');
});

// ------------------------------------------------------------------------------------------------>

$reactor->run();
