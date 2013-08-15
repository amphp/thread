<?php

/**
 * If an async function throws an exception during invocation it is caught by the worker and the
 * `CallResult::isError()` will return TRUE. If your onResult callback invokes `CallResult::getResult()`
 * on an error result an exception will be thrown in your main process. This behavior exists to prevent
 * you from failing to realize there was a problem with the async invocation.
 *
 * Before operating on an asynchronous result you should always determine whether or not it actually
 * succeeded using `CallResult::isSuccess()` or `CallResult::isError()`. In the event of an error you
 * can access debug information by invoking `CallResult::getError()`.
 */

use Amp\IoDispatcher, Amp\CallResult, Alert\ReactorFactory;

require __DIR__ . '/../vendor/autoload.php';

$reactor = (new ReactorFactory)->select();
$asyncFunctions  = __DIR__ . '/support/my_async_functions.php';
$dispatcher = new IoDispatcher($reactor, $asyncFunctions, $workerProcessesToSpawn = 1);

// ------------------------------------------------------------------------------------------------>
$onResult = function(CallResult $r) use ($reactor) {
    if ($r->isError()) {
        echo "\nASYNC CALL ERROR\n";
        echo "----------------\n";
        echo $r->getError(), "\n\n";
    }
    $reactor->stop();
};

$reactor->immediately(function() use ($dispatcher, $onResult) {
    $dispatcher->call($onResult, 'my_exception_function');
});
// ------------------------------------------------------------------------------------------------>

$reactor->run();
