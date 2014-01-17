<?php

use Amp\PthreadsDispatcher,
    Alert\NativeReactor;

class PthreadsDispatcherTest extends PHPUnit_Framework_TestCase {

    /**
     * @dataProvider provideBadOptionKeys
     * @expectedException \DomainException
     */
    function testSetOptionThrowsOnUnknownOption($badOptionName) {
        $reactor = new NativeReactor;
        $dispatcher = new PthreadsDispatcher($reactor);
        $dispatcher->setOption($badOptionName, 42);
    }

    function provideBadOptionKeys() {
        return [
            ['unknownName'],
            ['someothername']
        ];
    }

    function testNativeFunctionDispatch() {
        $reactor = new NativeReactor;
        $dispatcher = new PthreadsDispatcher($reactor);
        $dispatcher->start(1);
        $dispatcher->call('strlen', 'zanzibar!', function($result) use ($reactor) {
            $this->assertEquals($result->getResult(), 9);
            $reactor->stop();
        });
        $reactor->run();
    }

    function testUserlandFunctionDispatch() {
        $reactor = new NativeReactor;
        $dispatcher = new PthreadsDispatcher($reactor);
        $dispatcher->start(1);
        $dispatcher->call('multiply', 6, 7, function($result) use ($reactor) {
            $this->assertEquals($result->getResult(), 42);
            $reactor->stop();
        });
        $reactor->run();
    }

    /**
     * @expectedException \Amp\DispatchException
     */
    function testErrorResultReturnedIfInvocationThrows() {
        $reactor = new NativeReactor;
        $dispatcher = new PthreadsDispatcher($reactor);
        $dispatcher->start(1);
        $dispatcher->call('exception', function($result) use ($reactor) {
            $this->assertTrue($result->failed());
            $result->getResult();
            $reactor->stop();
        });
        $reactor->run();
    }

    /**
     * @expectedException \Amp\DispatchException
     */
    function testErrorResultReturnedIfInvocationFatals() {
        $reactor = new NativeReactor;
        $dispatcher = new PthreadsDispatcher($reactor);
        $dispatcher->start(1);
        $dispatcher->call('fatal', function($result) use ($reactor) {
            $this->assertTrue($result->failed());
            $result->getResult();
            $reactor->stop();
        });
        $reactor->run();
    }

    function testCancel() {
        $reactor = new NativeReactor;
        $dispatcher = new PthreadsDispatcher($reactor);
        $dispatcher->start(1);

        // Store this so we can reference it and cancel the associated task
        $taskId;

        // The task we want to cancel
        $reactor->immediately(function() use ($dispatcher, $reactor, &$taskId) {
            $taskId = $dispatcher->call('sleep', 999, function($taskResult) {
                $this->assertTrue($taskResult->failed());
            });
        });

        // The actual cancellation
        $reactor->once(function() use ($dispatcher, $reactor, &$taskId) {
            $wasCancelled = $dispatcher->cancel($taskId);
            $this->assertTrue($wasCancelled);
            $reactor->stop();
        }, 0.1);

        // Release the hounds
        $reactor->run();
    }

}
