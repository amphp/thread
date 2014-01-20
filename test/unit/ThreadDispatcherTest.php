<?php

use Amp\PthreadsDispatcher,
    Alert\NativeReactor;

class PthreadsDispatcherTest extends PHPUnit_Framework_TestCase {

    /**
     * @dataProvider provideBadOptionKeys
     * @expectedException \DomainException
     */
    public function testSetOptionThrowsOnUnknownOption($badOptionName) {
        $reactor = new NativeReactor;
        $dispatcher = new PthreadsDispatcher($reactor);
        $dispatcher->setOption($badOptionName, 42);
    }

    public function provideBadOptionKeys() {
        return [
            ['unknownName'],
            ['someothername']
        ];
    }

    public function testNativeFunctionDispatch() {
        $reactor = new NativeReactor;
        $dispatcher = new PthreadsDispatcher($reactor);
        $dispatcher->start();
        $dispatcher->call('strlen', 'zanzibar!', function($result) use ($reactor) {
            $this->assertEquals($result->getResult(), 9);
            $reactor->stop();
        });
        $reactor->run();
    }

    public function testUserlandFunctionDispatch() {
        $reactor = new NativeReactor;
        $dispatcher = new PthreadsDispatcher($reactor);
        $dispatcher->start();
        $dispatcher->call('multiply', 6, 7, function($result) use ($reactor) {
            $this->assertEquals($result->getResult(), 42);
            $reactor->stop();
        });
        $reactor->run();
    }

    /**
     * @expectedException \Amp\DispatchException
     */
    public function testErrorResultReturnedIfInvocationThrows() {
        $reactor = new NativeReactor;
        $dispatcher = new PthreadsDispatcher($reactor);
        $dispatcher->start();
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
    public function testErrorResultReturnedIfInvocationFatals() {
        $reactor = new NativeReactor;
        $dispatcher = new PthreadsDispatcher($reactor);
        $dispatcher->start();
        $dispatcher->call('fatal', function($result) use ($reactor) {
            $this->assertTrue($result->failed());
            $result->getResult();
            $reactor->stop();
        });
        $reactor->run();
    }

    public function testNextTaskDequeuedOnCompletion() {
        $reactor = new NativeReactor;
        $dispatcher = new PthreadsDispatcher($reactor);
        // Make sure the second call gets queued
        $dispatcher->setOption('poolSize', 1);
        $count = 0;
        $dispatcher->call('usleep', 50000, function($result) use (&$count) {
            $count++;
        });
        $dispatcher->call('strlen', 'zanzibar', function($result) use ($reactor, &$count) {
            $count++;
            $this->assertEquals(8, $result->getResult());
            $this->assertEquals(2, $count);
            $reactor->stop();
        });
        $reactor->run();
    }

    public function testCancel() {
        $reactor = new NativeReactor;
        $dispatcher = new PthreadsDispatcher($reactor);
        $dispatcher->start();

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
        }, $delay = 0.1);

        // Release the hounds
        $reactor->run();
    }

    public function testCount() {
        $reactor = new NativeReactor;
        $dispatcher = new PthreadsDispatcher($reactor);
        // Make sure repeated calls get queued behind the first call
        $dispatcher->setOption('poolSize', 1);
        $dispatcher->start();

        $onStart = function() use ($reactor, $dispatcher) {
            // Something slow that will cause subsequent calls to be queued
            $slowTaskId = $dispatcher->call('sleep', 999, function(){});
            $this->assertEquals(1, $dispatcher->count());

            $dispatcher->call('strlen', 'zanzibar', function($r) {
                $this->assertEquals(8, $r->getResult());
            });
            $this->assertEquals(2, $dispatcher->count());

            $dispatcher->call('strlen', 'zanzibar', function($r) use ($reactor, $dispatcher) {
                $this->assertEquals(8, $r->getResult());
                if (!$dispatcher->count()) {
                    $reactor->stop();
                }
            });
            $this->assertEquals(3, $dispatcher->count());

            $wasFound = $dispatcher->cancel($slowTaskId);
        };

        $reactor->immediately($onStart);
        $reactor->run();
        $this->assertEquals(0, $dispatcher->count());
    }

    public function testForget() {
        //$this->markTestSkipped('phpunit breaks on this test');

        $reactor = new NativeReactor;
        $reactor->run(function() use ($reactor) {
            $dispatcher = new PthreadsDispatcher($reactor);
            $dispatcher->setOption('poolSize', 1)->setOption('taskTimeout', 3);
            $dispatcher->forget(new ThrowingStackable);
            $dispatcher->call('strlen', 'zanzibar', function($r) use ($reactor, $dispatcher) {
                $this->assertEquals(0, $dispatcher->count());
                $this->assertEquals(8, $r->getResult());
                $reactor->stop();
            });
        });
    }

    public function testNewWorkerIncludes() {
        //$this->markTestSkipped('phpunit breaks on this test');

        $reactor = new NativeReactor;
        $reactor->run(function() use ($reactor) {
            $dispatcher = new PthreadsDispatcher($reactor);
            $dispatcher->setOption('onWorkerStart', new TestAutoloaderStackable);
            $dispatcher->setOption('poolSize', 1);
            $dispatcher->call('AutoloadableClass::test', function($r) use ($reactor, $dispatcher) {
                $this->assertEquals(42, $r->getResult());
                $reactor->stop();
            });
        });
    }

}
