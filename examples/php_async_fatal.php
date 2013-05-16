<?php

/**
 * The Amp dispatcher transparently recovers from fatal errors in your worker processes. If you
 * manage to kill a worker in your async functions the relevant CallResult will be an error response.
 * Any other calls queued for execution by the worker process that died are subsequently reallocated
 * to a new worker. Obviously, this is an expensive operation. While Amp can recover from such
 * errors, you should endeavor to avoid fatal errors in your code altogether.
 * 
 * Information about the fatal error will be written to the main process's STDERR stream. You will
 * not receive debug info for the fatal error in the CallResult returned to any relevant callbacks.
 * 
 * In the following example we dispatch a function call that we know will generate a fatal E_ERROR
 * and crash the worker. To demonstrate that the dispatcher recovers from this event, we make a
 * call to a different function (one that won't error out) as well.
 */

use Amp\MultiProcess\PhpDispatcher,
    Amp\MultiProcess\CallResult,
    Amp\ReactorFactory;

require dirname(__DIR__) . '/autoload.php';

$asyncFunctions  = __DIR__ . '/support_files/my_async_functions.php';
$reactor = (new ReactorFactory)->select();
$dispatcher = new PhpDispatcher($reactor, $asyncFunctions, $poolSize = 1);
$dispatcher->start();

// ------------------------------------------------------------------------------------------------>



$onFatalResult = function(CallResult $r) use ($reactor) {
    $isError = $r->isError() ? 'YES (see error message above)' : 'NO';
    echo "\nDid my_fatal_function() die as expected? $isError\n\n";
};

$onHelloResult = function($result) use ($reactor) {
    echo "my_hello_function() (dispatched after fatal): ", $result->getResult(), "\n\n";
    $reactor->stop();
};

$reactor->once(function() use ($dispatcher, $onFatalResult, $onHelloResult) {
    $dispatcher->call($onFatalResult, 'my_fatal_function');
    $dispatcher->call($onHelloResult, 'my_hello_function', 'World');
});

// ------------------------------------------------------------------------------------------------>

$reactor->run();

