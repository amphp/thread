<?php

namespace Amp;

use Alert\NativeReactor, Alert\Promise, Alert\Future;

class DispatcherTest extends \PHPUnit_Framework_TestCase {

    /**
     * @dataProvider provideBadOptionKeys
     * @expectedException \DomainException
     */
    public function testSetOptionThrowsOnUnknownOption($badOptionName) {
        $reactor = new NativeReactor;
        $dispatcher = new Dispatcher($reactor);
        $dispatcher->setOption($badOptionName, 42);
    }

    public function provideBadOptionKeys() {
        return [
            [45678],
            ['unknownName']
        ];
    }

    public function testNativeFunctionDispatch() {
        $reactor = new NativeReactor;
        $dispatcher = new Dispatcher($reactor);
        $dispatcher->start();

        $future = $dispatcher->call('strlen', 'zanzibar!');
        $future->onComplete(function($future) use ($reactor) {
            $this->assertEquals(9, $future->getValue());
            $reactor->stop();
        });

        $reactor->run();
    }

    public function testUserlandFunctionDispatch() {
        $reactor = new NativeReactor;
        $reactor->run(function() use ($reactor) {
            $dispatcher = new Dispatcher($reactor);
            $future = $dispatcher->call('multiply', 6, 7);
            $future->onComplete(function($future) use ($reactor) {
                $this->assertEquals($future->getValue(), 42);
                $reactor->stop();
            });
        });
    }

    /**
     * @expectedException \Amp\DispatchException
     */
    public function testErrorResultReturnedIfInvocationThrows() {
        $reactor = new NativeReactor;
        $reactor->run(function() use ($reactor) {
            $dispatcher = new Dispatcher($reactor);
            $future = $dispatcher->call('exception');
            $future->onComplete(function($future) use ($reactor) {
                $this->assertFalse($future->succeeded());

                // Should throw
                $future->getValue();
                $reactor->stop();
            });
        });
    }

    /**
     * @expectedException \Amp\DispatchException
     */
    public function testErrorResultReturnedIfInvocationFatals() {
        $reactor = new NativeReactor;
        $reactor->run(function() use ($reactor) {
            $dispatcher = new Dispatcher($reactor);
            $future = $dispatcher->call('fatal');
            $future->onComplete(function($future) use ($reactor) {
                $this->assertFalse($future->succeeded());
                $future->getValue(); // <-- should throw
                $reactor->stop();
            });
        });
    }

    public function testNextTaskDequeuedOnCompletion() {
        $reactor = new NativeReactor;
        $dispatcher = new Dispatcher($reactor);

        $count = 0;

        // Make sure the second call gets queued
        $dispatcher->setOption(Dispatcher::OPT_POOL_SIZE_MAX, 1);
        $future1 = $dispatcher->call('usleep', 50000);
        $future1->onComplete(function() use (&$count) {
            $count++;
        });

        $future2 = $dispatcher->call('strlen', 'zanzibar');
        $future2->onComplete(function($future) use ($reactor, &$count) {
            $count++;
            $this->assertEquals(8, $future->getValue());
            $this->assertEquals(2, $count);
            $reactor->stop();
        });

        $reactor->run();
    }

    public function testCount() {
        $reactor = new NativeReactor;

        $reactor->run(function() use ($reactor) {
            $dispatcher = new Dispatcher($reactor);

            // Make sure repeated calls get queued behind the first call
            $dispatcher->setOption(Dispatcher::OPT_POOL_SIZE_MAX, 1);

            // Something semi-slow that will cause subsequent calls to be queued
            $dispatcher->call('usleep', 50000);
            $this->assertEquals(1, $dispatcher->count());

            $dispatcher->call('strlen', 'zanzibar');
            $this->assertEquals(2, $dispatcher->count());

            $future = $dispatcher->call('strlen', 'zanzibar');
            $this->assertEquals(3, $dispatcher->count());

            $future->onComplete(function($future) use ($reactor, $dispatcher) {
                $this->assertEquals(8, $future->getValue());
                $count = $dispatcher->count();
                if ($count !== 0) {
                    $reactor->stop();
                    $this->fail(
                        sprintf('Zero expected for dispatcher count; %d returned', $count)
                    );
                } else {
                    $reactor->stop();
                }
            });
        });
    }

    public function testNewWorkerIncludes() {
        $reactor = new NativeReactor;
        $reactor->run(function() use ($reactor) {
            $dispatcher = new Dispatcher($reactor);
            $dispatcher->addStartTask(new \TestAutoloaderStackable);
            $dispatcher->setOption(Dispatcher::OPT_POOL_SIZE_MAX, 1);
            $future = $dispatcher->call('AutoloadableClass::test');
            $future->onComplete(function($future) use ($reactor, $dispatcher) {
                $this->assertEquals(42, $future->getValue());
                $reactor->stop();
            });
        });
    }

    public function testStreamingResult() {
        $this->expectOutputString("1\n2\n3\n4\n");
        $reactor = new NativeReactor;
        $reactor->run([new \TestStreamApp($reactor), 'test']);
    }

    public function testNestedFutureResolution() {
        $reactor = new NativeReactor;
        $reactor->run(function() use ($reactor) {
            $test = new \NestedFutureTest($reactor);
            $test->test()->onComplete(function($future) use ($reactor) {
                $this->assertEquals(8, $future->getValue());
                $reactor->stop();
            });
        });
    }

}
