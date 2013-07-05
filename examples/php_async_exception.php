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

use Amp\MultiProcess\PhpDispatcher,
    Amp\MultiProcess\CallResult,
    Amp\ReactorFactory;

require dirname(__DIR__) . '/autoload.php';

$asyncFunctions  = __DIR__ . '/support_files/my_async_functions.php';
$reactor = (new ReactorFactory)->select();
$dispatcher = new PhpDispatcher($reactor, $asyncFunctions, $workerProcessesToSpawn = 1);

// ------------------------------------------------------------------------------------------------>

$onResult = function(CallResult $r) use ($reactor) {
    if ($r->isError()) {
        echo "\nASYNC CALL ERROR:\n";
        echo "\n---------------------------\n";
        echo $r->getError();
        echo "\n---------------------------\n\n";
    }
    $reactor->stop();
};

$reactor->once(function() use ($dispatcher, $onResult) {
    $dispatcher->call($onResult, 'my_exception_function');
});

// ------------------------------------------------------------------------------------------------>

$reactor->run();

