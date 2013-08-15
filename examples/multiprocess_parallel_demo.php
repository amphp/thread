<?php

/**
 * Technically *ALL* dispatchers are "parallel," so don't be fooled by this example's file name.
 * This script simply demonstrates the parallel nature of the dispatchers explicitly by calling
 * `sleep(1)` four times. If we did this serially the program would require ~4 seconds to complete.
 * Since we're instead dispatching these calls in parallel the program executes in ~1 second.
 */

require __DIR__ . '/../vendor/autoload.php';

use Alert\Reactor, Alert\ReactorFactory, Amp\CallResult, Amp\IoDispatcher;

class MyParallelProgram {
    
    private $reactor;
    private $dispatcher;
    private $startTime;
    private $successfulCallCount = 0;
    
    function __construct(Reactor $reactor, IoDispatcher $dispatcher) {
        $this->reactor = $reactor;
        $this->dispatcher = $dispatcher;
    }
    
    function run() {
        $this->startTime = microtime(TRUE);
        $this->reactor->immediately(function() {
            $onResult = [$this, 'onResult'];
            $this->dispatcher->call($onResult, 'sleep', 1); // <-- returns immediately
            $this->dispatcher->call($onResult, 'sleep', 1); // <-- returns immediately
            $this->dispatcher->call($onResult, 'sleep', 1); // <-- returns immediately
            $this->dispatcher->call($onResult, 'sleep', 1); // <-- returns immediately
        });
        
        // Nothing happens until we start the reactor's event loop
        $this->reactor->run();
    }
    
    function onResult(CallResult $callResult) {
        if ($callResult->isError()) {
            throw $callResult->getError();
            
        } elseif (++$this->successfulCallCount === 4) {
            
            $timeElapsed = microtime(TRUE) - $this->startTime;
            
            echo "Yo dawg, I heard you like callbacks ...\n";
            echo "So I wrote a lib to invoke callbacks from inside callbacks.\n";
            echo "Total async execution time: {$timeElapsed} seconds\n";
            
            // Yield control back from the reactor's event loop
            $this->reactor->stop();
        }
    }
    
}

$reactor = (new ReactorFactory)->select();
$dispatcher = new IoDispatcher($reactor, $userInclude = NULL, $workers = 4);
$program = new MyParallelProgram($reactor, $dispatcher);

// Won't return until we stop the reactor's event loop
$program->run();
