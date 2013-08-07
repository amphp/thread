<?php

/**
 * @TODO Explain the demo
 */

use Alert\Reactor, Alert\ReactorFactory, Amp\CallResult, Amp\IoDispatcher;

require dirname(__DIR__) . '/autoload.php';

function main(Reactor $reactor, IoDispatcher $dispatcher) {
    
    // Stop the program after five seconds have elapsed
    $reactor->once(function() use ($reactor) {
        $reactor->stop();
    }, $delayInSeconds = 5);
    
    // Tick off seconds to demonstrate the asynchronous nature of the program
    $reactor->repeat(function() {
        echo "tick ", time(), PHP_EOL;
    }, $intervalInSeconds = 1);
    
    // A callback to receive the results of our asynchronous str_rot13() call
    $onAsyncCallResult = function(CallResult $callResult) {
        echo $callResult->isSuccess()
            ? "str_rot13() result: " . $callResult->getResult() . PHP_EOL
            : $callResult->getError();
    };
    
    // Dispatch this async call as soon as the program starts
    $reactor->immediately(function() use ($dispatcher, $onAsyncCallResult) {
        $dispatcher->call($onAsyncCallResult, 'str_rot13', 'my string');
    });
    
}

// Select the best available event reactor for our system.
$reactor = (new ReactorFactory)->select();

// Schedule the main() function to execute as soon as the reactor event loop starts.
$reactor->immediately(function() use ($reactor) {
    $dispatcher = new IoDispatcher($reactor);
    main($reactor, $dispatcher);
});

// The reactor *is* our task scheduler. Nothing will happen until we tell it to run!
$reactor->run();
