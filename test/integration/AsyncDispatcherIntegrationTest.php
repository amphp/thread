<?php

use Amp\ReactorFactory,
    Amp\Async\Dispatcher,
    Amp\Async\CallResult,
    Amp\Async\ResourceException,
    Amp\Async\WorkerSession,
    Amp\Async\WorkerSessionFactory;

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
    
    /**
     * This test calls a worker function that dies on its second invocation, severing the connection
     * between the main dispatcher process and the worker subprocess. The result is a ResourceException
     * in the dispatcher. The worker should be unloaded and respawned while the second call dispatch
     * should result in a CALL_ERROR result.
     */
    function testBrokenPipeOnRead() {
        $phpBinary    = PHP_BINARY;
        $workerScript = dirname(dirname(__DIR__)) . '/workers/php/worker.php';
        $userInclude  = dirname(__DIR__) . '/fixture/dispatch_die_on_second_invocation.php';
        $workerCmd    = $phpBinary . ' ' . $workerScript . ' ' . $userInclude;
        
        $reactor = (new ReactorFactory)->select();
        $dispatcher = new Dispatcher($reactor);
        $dispatcher->start($poolSize = 1, $workerCmd);
        
        $count = 0;
        $onResult = function(CallResult $callResult) use ($reactor, &$count) {
            if (++$count == 1) {
                $this->assertEquals('woot', $callResult->getResult());
            } else {
                $this->assertTrue($callResult->isError());
                $reactor->stop();
            }
        };
        
        $reactor->repeat(function() use ($dispatcher, $onResult) {
            $dispatcher->call($onResult, 'dispatch_die_on_second_invocation');
        }, $delay = 0, $iterations = 2);
        
        $reactor->run();
    }
    
    function testErrorReturnOnUncaughtWorkerFunctionException() {
        $phpBinary    = PHP_BINARY;
        $workerScript = dirname(dirname(__DIR__)) . '/workers/php/worker.php';
        $userInclude  = dirname(__DIR__) . '/fixture/dispatch_call_error_function.php';
        $workerCmd    = $phpBinary . ' ' . $workerScript . ' ' . $userInclude;
        
        $reactor = (new ReactorFactory)->select();
        $dispatcher = new Dispatcher($reactor);
        $dispatcher->start($poolSize = 1, $workerCmd);
        
        $onResult = function(CallResult $callResult) use ($reactor) {
            $this->assertTrue($callResult->isError());
            $this->assertInstanceOf('Amp\Async\WorkerException', $callResult->getError());
            $reactor->stop();
        };
        
        $reactor->once(function() use ($dispatcher, $onResult) {
            $dispatcher->call($onResult, 'dispatch_call_error_function');
        });
        
        $reactor->run();
    }
    
    
}




















