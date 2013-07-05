<?php

/**
 * First, a word of caution: *Timeouts are a poor way to handle errors!*
 * 
 * If your async functions are timing out you should fix your functions -- not adjust your timeouts.
 * That said, if a call times out it will terminate with a harmless error result. For all intents and
 * purposes a timeout is like a fatal error in your code. When a call times out the relevant worker
 * is shutdown and any other queued calls are reallocated to a new/existing worker. This is an
 * expensive operation. It's preferable to fix the cause of the timeout instead.
 */

use Amp\MultiProcess\PhpDispatcher,
    Amp\MultiProcess\CallResult,
    Amp\MultiProcess\TimeoutException,
    Amp\ReactorFactory;

require dirname(__DIR__) . '/autoload.php';

$asyncFunctions  = __DIR__ . '/support_files/my_async_functions.php';

$reactor = (new ReactorFactory)->select();
$dispatcher = new PhpDispatcher($reactor, $asyncFunctions, $workerProcessesToSpawn = 1);

// ------------------------------------------------------------------------------------------------>

$count = 0;
$onHelloResult = function(CallResult $callResult) use ($reactor, &$count) {
    echo $callResult->getResult(), "\n";
    if (++$count === 3) {
        $reactor->stop();
    }
};

$onSleepResult = function(CallResult $callResult) {
    if ($callResult->isError()) {
        echo "sleep() error (as expected): ", get_class($callResult->getError()), "\n";
    } else {
        echo "Something went terribly wrong with this example :(", "\n";
    }
};

$reactor->once(function() use ($dispatcher, $onSleepResult, $onHelloResult) {
    $dispatcher->setCallTimeout(1); // <-- timeout calls that haven't returned after 1 second
    $dispatcher->call($onSleepResult, 'sleep', 42);
    
    $dispatcher->setCallTimeout(30); // <-- don't timeout our other calls that are queued behind sleep(42)!
    $dispatcher->call($onHelloResult, 'my_hello_function', 'Arthur Dent');
    $dispatcher->call($onHelloResult, 'my_hello_function', 'Ford Prefect');
    $dispatcher->call($onHelloResult, 'my_hello_function', 'Zaphod Beeblebrox');
});

// ------------------------------------------------------------------------------------------------>

$reactor->run();

