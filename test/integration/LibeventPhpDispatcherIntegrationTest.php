<?php

use Amp\LibeventReactor,
    Amp\MultiProcess\PhpDispatcher,
    Amp\MultiProcess\CallResult,
    Amp\MultiProcess\ResourceException,
    Amp\MultiProcess\WorkerSession,
    Amp\MultiProcess\WorkerSessionFactory;

class LibeventPhpDispatcherIntegrationTest extends PHPUnit_Framework_TestCase {
    
    private function skipIfMissingExtLibevent() {
        if (!extension_loaded('libevent')) {
            $this->markTestSkipped(
                'libevent extension not available'
            );
        }
    }
    
    function testNativeFunctionDispatch() {
        $this->skipIfMissingExtLibevent();
        $functions  = dirname(__DIR__) . '/fixture/dispatch_integration_test_functions.php';
        
        $reactor = new LibeventReactor;
        $dispatcher = new PhpDispatcher($reactor, $functions);
        $dispatcher->setCallTimeout(1);
        $dispatcher->setGranularity(1);
        $dispatcher->start();
        
        // cover repeated start
        $dispatcher->start();
        
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
        $this->skipIfMissingExtLibevent();
        $functions  = dirname(__DIR__) . '/fixture/dispatch_integration_test_functions.php';
        
        $reactor = new LibeventReactor;
        $dispatcher = new PhpDispatcher($reactor, $functions);
        $dispatcher->setGranularity(1);
        $dispatcher->start();
        
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
        $this->skipIfMissingExtLibevent();
        $functions  = dirname(__DIR__) . '/fixture/dispatch_integration_test_functions.php';
        
        $reactor = new LibeventReactor;
        $dispatcher = new PhpDispatcher($reactor, $functions);
        $dispatcher->setCallTimeout(1);
        $dispatcher->start();
        
        $onResult = function(CallResult $callResult) use ($reactor) {
            $this->assertTrue($callResult->isError());
            $this->assertInstanceOf('Amp\MultiProcess\TimeoutException', $callResult->getError());
            $reactor->stop();
        };
        
        $reactor->once(function() use ($dispatcher, $onResult) {
            $dispatcher->call($onResult, 'sleep', 5);
        }, $delay = 0.25);
        
        $reactor->run();
        
        $dispatcher->__destruct();
    }
    
    /**
     * This test calls a worker function that dies on its second invocation, severing the connection
     * between the main dispatcher process and the worker subprocess. The result is a ResourceException
     * in the dispatcher. The worker should be unloaded and respawned while the second call dispatch
     * should result in a CALL_ERROR result.
     */
    function testBrokenPipeOnRead() {
        $this->skipIfMissingExtLibevent();
        $functions  = dirname(__DIR__) . '/fixture/dispatch_die_on_second_invocation.php';
        
        $reactor = new LibeventReactor;
        $dispatcher = new PhpDispatcher($reactor, $functions);
        $dispatcher->start();
        
        $count = 0;
        $onResult = function(CallResult $callResult) use ($reactor, &$count) {
            if (++$count == 1) {
                $this->assertEquals('woot', $callResult->getResult());
            } else {
                $this->assertTrue($callResult->isError());
                $reactor->stop();
            }
        };
        
        $reactor->schedule(function() use ($dispatcher, $onResult) {
            $dispatcher->call($onResult, 'dispatch_die_on_second_invocation');
        }, $delay = 0, $iterations = 2);
        
        $reactor->run();
    }
    
    function testErrorReturnOnUncaughtWorkerFunctionException() {
        $this->skipIfMissingExtLibevent();
        $functions  = dirname(__DIR__) . '/fixture/dispatch_integration_test_functions.php';
        
        $reactor = new LibeventReactor;
        $dispatcher = new PhpDispatcher($reactor, $functions);
        $dispatcher->start();
        
        $onResult = function(CallResult $callResult) use ($reactor) {
            $this->assertTrue($callResult->isError());
            $this->assertInstanceOf('Amp\MultiProcess\WorkerException', $callResult->getError());
            $reactor->stop();
        };
        
        $reactor->once(function() use ($dispatcher, $onResult) {
            $dispatcher->call($onResult, 'dispatch_call_error_function');
        });
        
        $reactor->run();
    }
    
    
}

