<?php

use Alert\LibeventReactor,
    Amp\IoDispatcher,
    Amp\CallResult,
    Amp\ResourceException,
    Amp\WorkerSession,
    Amp\WorkerSessionFactory;

class LibeventIoDispatcherIntegrationTest extends PHPUnit_Framework_TestCase {
    
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
        $dispatcher = new IoDispatcher($reactor, $functions);
        
        $onResult = function(CallResult $callResult) use ($reactor) {
            $this->assertEquals(4, $callResult->getResult());
            $reactor->stop();
        };
        
        $reactor->immediately(function() use ($dispatcher, $onResult) {
            $dispatcher->call($onResult, 'strlen', 'four');
        });
        
        $reactor->run();
    }
    
    function testCustomFunctionDispatch() {
        $this->skipIfMissingExtLibevent();
        $functions  = dirname(__DIR__) . '/fixture/dispatch_integration_test_functions.php';
        
        $reactor = new LibeventReactor;
        $dispatcher = new IoDispatcher($reactor, $functions);
        
        $onResult = function(CallResult $callResult) use ($reactor) {
            $this->assertEquals('woot', $callResult->getResult());
            $reactor->stop();
        };
        
        $reactor->immediately(function() use ($dispatcher, $onResult) {
            $dispatcher->call($onResult, 'integration_dispatch_test', 'woot');
        });
        
        $reactor->run();
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
        $dispatcher = new IoDispatcher($reactor, $functions);
        
        $count = 0;
        $onResult = function(CallResult $callResult) use ($reactor, &$count) {
            if (++$count == 1) {
                $this->assertEquals('woot', $callResult->getResult());
            } else {
                $this->assertTrue($callResult->isError());
                $reactor->stop();
            }
        };
        
        $reactor->immediately(function() use ($dispatcher, $onResult) {
            $dispatcher->call($onResult, 'dispatch_die_on_second_invocation');
        });
        
        $reactor->immediately(function() use ($dispatcher, $onResult) {
            $dispatcher->call($onResult, 'dispatch_die_on_second_invocation');
        });
        
        $reactor->run();
    }
    
    function testErrorReturnOnUncaughtWorkerFunctionException() {
        $this->skipIfMissingExtLibevent();
        $functions  = dirname(__DIR__) . '/fixture/dispatch_integration_test_functions.php';
        
        $reactor = new LibeventReactor;
        $dispatcher = new IoDispatcher($reactor, $functions);
        
        $onResult = function(CallResult $callResult) use ($reactor) {
            $this->assertTrue($callResult->isError());
            $this->assertInstanceOf('Amp\DispatchException', $callResult->getError());
            $reactor->stop();
        };
        
        $reactor->immediately(function() use ($dispatcher, $onResult) {
            $dispatcher->call($onResult, 'dispatch_call_error_function');
        });
        
        $reactor->run();
    }
    
    
}

