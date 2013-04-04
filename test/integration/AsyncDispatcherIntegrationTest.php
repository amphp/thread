<?php

use Amp\ReactorFactory,
    Amp\Async\Dispatcher,
    Amp\Async\CallResult;

class AsyncDispatcherIntegrationTest extends PHPUnit_Framework_TestCase {
    
    private function skipIfMissingExtLibevent() {
        if (!extension_loaded('libevent')) {
            $this->markTestSkipped(
                'libevent extension not available'
            );
        }
    }
    
    function testNativeFunctionDispatch() {
        
        $phpBinary    = PHP_BINARY;
        $workerScript = dirname(dirname(__DIR__)) . '/workers/php/worker.php';
        $workerCmd    = $phpBinary . ' ' . $workerScript;
        
        $reactor = (new ReactorFactory)->select();
        $dispatcher = new Dispatcher($reactor);
        $dispatcher->setCallTimeout(1);
        $dispatcher->setGranularity(1);
        $dispatcher->start($poolSize = 1, $workerCmd);
        
        // cover repeated start
        $dispatcher->start($poolSize = 1, $workerCmd);
        
        $onResult = function(CallResult $callResult) use ($reactor) {
            $this->assertEquals(4, $callResult->getResult());
            $reactor->stop();
        };
        
        $reactor->once(function() use ($dispatcher, $onResult) {
            $dispatcher->call($onResult, 'strlen', 'four');
        });
        
        $reactor->run();
    }
    
    function testCustomFunctionDispatch() {
        
        $phpBinary    = PHP_BINARY;
        $workerScript = dirname(dirname(__DIR__)) . '/workers/php/worker.php';
        $userInclude  = dirname(__DIR__) . '/fixture/dispatch_integration_test_functions.php';
        $workerCmd    = $phpBinary . ' ' . $workerScript . ' ' . $userInclude;
        
        $reactor = (new ReactorFactory)->select();
        $dispatcher = new Dispatcher($reactor);
        $dispatcher->setCallTimeout(1);
        $dispatcher->setGranularity(1);
        $dispatcher->start($poolSize = 1, $workerCmd);
        
        $onResult = function(CallResult $callResult) use ($reactor) {
            $this->assertEquals('woot', $callResult->getResult());
            $reactor->stop();
        };
        
        $reactor->once(function() use ($dispatcher, $onResult) {
            $dispatcher->call($onResult, 'integration_dispatch_test', 'woot');
        });
        
        $reactor->run();
    }
    
    function testTimedOutCallResult() {
        
        $phpBinary    = PHP_BINARY;
        $workerScript = dirname(dirname(__DIR__)) . '/workers/php/worker.php';
        $workerCmd    = $phpBinary . ' ' . $workerScript;
        
        $reactor = (new ReactorFactory)->select();
        $dispatcher = new Dispatcher($reactor);
        $dispatcher->setTimeoutCheckInterval(0.1);
        $dispatcher->setCallTimeout(0.5);
        $dispatcher->setMaxCallId(1);
        $dispatcher->start($poolSize = 1, $workerCmd);
        
        $onResult = function(CallResult $callResult) use ($reactor) {
            $this->assertTrue($callResult->isError());
            $this->assertInstanceOf('Amp\Async\TimeoutException', $callResult->getError());
            $reactor->stop();
        };
        
        $reactor->once(function() use ($dispatcher, $onResult) {
            $dispatcher->call($onResult, 'sleep', 1);
        }, $delay = 0.25);
        
        $reactor->run();
        
        $dispatcher->__destruct();
    }
    
    
}
























