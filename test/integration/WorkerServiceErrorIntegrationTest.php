<?php

use Amp\NativeReactor,
    Amp\Dispatch\PhpDispatcher,
    Amp\Dispatch\CallResult;

class WorkerServiceErrorIntegrationTest extends PHPUnit_Framework_TestCase {
    
    function testWorkerServiceThrowsOnBadFunctionCall() {
        $reactor = new NativeReactor;
        $dispatcher = new PhpDispatcher($reactor);
        $onResult = function(CallResult $callResult) use ($reactor) {
            $this->assertTrue($callResult->isError());
            $reactor->stop();
        };
        
        $reactor->once(function() use ($dispatcher, $onResult) {
            $dispatcher->call($onResult, 'some_nonexistent_function_salfjkflasf');
        });
        
        $reactor->run();
    }
}
