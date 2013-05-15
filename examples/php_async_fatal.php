<?php

/**
 * The Amp dispatcher transparently recovers from fatal errors in your worker processes. If you
 * manage to kill a worker in your async functions the relevant CallResult will be an error response.
 * Note that any calls queued for execution by the individual worker process that dies will also 
 * return error results.
 * 
 * Information about the fatal error will be written to the main process's STDERR stream. You will
 * not receive debug info for the fatal error in the CallResult returned to any relevant callbacks.
 * 
 * In the following example we dispatch a function call that we know will generate a fatal E_ERROR
 * and crash the worker. To demonstrate that the dispatcher recovers from this event, we make a
 * call to a different function (one that won't error out) when we get the error result from the
 * initial fatal invocation.
 */

use Amp\Async\PhpDispatcher,
    Amp\Async\CallResult,
    Amp\ReactorFactory;

require dirname(__DIR__) . '/autoload.php';

$asyncFunctions  = __DIR__ . '/support_files/my_async_functions.php';
$reactor = (new ReactorFactory)->select();
$dispatcher = new PhpDispatcher($reactor, $asyncFunctions, $poolSize = 1);
$dispatcher->start();

// ------------------------------------------------------------------------------------------------>

$onResult = function(CallResult $r) use ($reactor, $dispatcher) {
    $isError = $r->isError() ? 'YES (see error message above)' : 'NO';
    echo "\nDid my_fatal_function() fail? $isError\n\n";
    
    $onHelloResult = function($result) use ($reactor) {
        echo "my_hello_function() (dispatched after fatal result): ", $result->getResult(), "\n\n";
        $reactor->stop();
    };
    $dispatcher->call($onHelloResult, 'my_hello_function', 'World');    
};

$reactor->once(function() use ($dispatcher, $onResult) {
    $dispatcher->call($onResult, 'my_fatal_function');
});

// ------------------------------------------------------------------------------------------------>

$reactor->run();

