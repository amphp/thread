<?php

/**
 * @TODO Explain the demo
 */

require __DIR__ . '/../vendor/autoload.php';

use Alert\ReactorFactory, Amp\CallResult, Amp\IoDispatcher;

$reactor = (new ReactorFactory)->select();
$myAsyncFunctions = __DIR__ . '/support/my_async_functions.php';
$dispatcher = new IoDispatcher($reactor, $myAsyncFunctions);

// What to do when our asynchronous call returns
$onResult = function(CallResult $callResult) use ($reactor) {
    var_dump($callResult->getResult());
    
    // Yield control back from the reactor's event loop
    $reactor->stop();
};

// Schedule our asynchronous call to be dispatched as soon as the event loop starts
$reactor->immediately(function() use ($dispatcher, $onResult) {
    $dispatcher->call($onResult, 'my_hello_function', 'World');
});

// Won't return until we stop the reactor's event loop.
$reactor->run();
