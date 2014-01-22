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

        $future = $dispatcher->call('strlen', 'zanzibar!');
        $future->onComplete(function($future) use ($reactor) {
            $this->assertEquals(9, $future->value());
            $reactor->stop();
        });

        $reactor->run();
    }

    public function testUserlandFunctionDispatch() {
        $reactor = new NativeReactor;
        $reactor->run(function() use ($reactor) {
            $dispatcher = new PthreadsDispatcher($reactor);
            $dispatcher->call('multiply', 6, 7)->onComplete(function($future) use ($reactor) {
                $this->assertEquals($future->value(), 42);
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
            $dispatcher = new PthreadsDispatcher($reactor);
            $dispatcher->call('exception')->onComplete(function($future) use ($reactor) {
                $this->assertTrue($future->failed());

                // Should throw
                $future->value();
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
            $dispatcher = new PthreadsDispatcher($reactor);
            $dispatcher->call('fatal')->onComplete(function($future) use ($reactor) {
                $this->assertTrue($future->failed());
                $future->value();
                $reactor->stop();
            });
        });
    }

    public function testNextTaskDequeuedOnCompletion() {
        $reactor = new NativeReactor;
        $dispatcher = new PthreadsDispatcher($reactor);

        $count = 0;

        // Make sure the second call gets queued
        $dispatcher->setOption('poolSize', 1);
        $dispatcher->call('usleep', 50000)->onComplete(function($future) use (&$count) {
            $count++;
        });

        $dispatcher->call('strlen', 'zanzibar')->onComplete(function($future) use ($reactor, &$count) {
            $count++;
            $this->assertEquals(8, $future->value());
            $this->assertEquals(2, $count);
            $reactor->stop();
        });

        $reactor->run();
    }

    public function testCount() {
        $reactor = new NativeReactor;

        $reactor->run(function() use ($reactor) {
            $dispatcher = new PthreadsDispatcher($reactor);

            // Make sure repeated calls get queued behind the first call
            $dispatcher->setOption('poolSize', 1);

            // Something semi-slow that will cause subsequent calls to be queued
            $dispatcher->call('usleep', 50000);
            $this->assertEquals(1, $dispatcher->count());

            $dispatcher->call('strlen', 'zanzibar');
            $this->assertEquals(2, $dispatcher->count());

            $future = $dispatcher->call('strlen', 'zanzibar');
            $this->assertEquals(3, $dispatcher->count());

            $future->onComplete(function($future) use ($reactor, $dispatcher) {
                $this->assertEquals(8, $future->value());
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
            $dispatcher = new PthreadsDispatcher($reactor);
            $dispatcher->setOption('onWorkerStart', new TestAutoloaderStackable);
            $dispatcher->setOption('poolSize', 1);
            $future = $dispatcher->call('AutoloadableClass::test');
            $future->onComplete(function($future) use ($reactor, $dispatcher) {
                $this->assertEquals(42, $future->value());
                $reactor->stop();
            });
        });
    }

    public function testStreamingResult() {
        $this->expectOutputString("1\n2\n3\n4\n");
        $reactor = new NativeReactor;
        $reactor->run([new TestStreamApp($reactor), 'test']);
    }

    public function testNestedFutureResolution() {
        $reactor = new NativeReactor;
        $reactor->run(function() use ($reactor) {
            $test = new NestedFutureTest($reactor);
            $test->test()->onComplete(function($future) use ($reactor) {
                $this->assertEquals(8, $future->value());
                $reactor->stop();
            });
        });
    }

}
