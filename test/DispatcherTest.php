<?php

namespace Amp\Thread\Test;

use Amp\Promise;
use Amp\Future;
use Amp\NativeReactor;
use Amp\Thread\Dispatcher;

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
        $dispatcher = new Dispatcher(new NativeReactor);
        $value = $dispatcher->call('strlen', 'zanzibar!')->wait();
        $this->assertEquals(9, $value);
    }

    public function testUserlandFunctionDispatch() {
        $dispatcher = new Dispatcher(new NativeReactor);
        $value = $dispatcher->call('Amp\Thread\Test\multiply', 6, 7)->wait();
        $this->assertEquals($value, 42);
    }

    /**
     * @expectedException \Amp\Thread\DispatchException
     */
    public function testErrorResultReturnedIfInvocationThrows() {
        $dispatcher = new Dispatcher(new NativeReactor);
        $dispatcher->call('exception')->wait(); // should throw
    }

    /**
     * @expectedException \Amp\Thread\DispatchException
     */
    public function testErrorResultReturnedIfInvocationFatals() {
        $dispatcher = new Dispatcher(new NativeReactor);
        $dispatcher->call('fatal')->wait(); // should throw
    }

    public function testNextTaskDequeuedOnCompletion() {
        $reactor = new NativeReactor;
        $dispatcher = new Dispatcher($reactor);

        $count = 0;

        // Make sure the second call gets queued
        $dispatcher->setOption(Dispatcher::OPT_POOL_SIZE_MAX, 1);
        $dispatcher->call('usleep', 50000)->when(function() use (&$count) {
            $count++;
        });

        $dispatcher->call('strlen', 'zanzibar')->when(function($error, $result) use ($reactor, &$count) {
            $count++;
            fwrite(STDERR, $error);
            $this->assertTrue(is_null($error));
            $this->assertEquals(8, $result);
            $this->assertEquals(2, $count);
            $reactor->stop();
        });

        $reactor->run();
    }

    public function testCount() {
        (new NativeReactor)->run(function($reactor) {
            $dispatcher = new Dispatcher($reactor);

            // Make sure repeated calls get queued behind the first call
            $dispatcher->setOption(Dispatcher::OPT_POOL_SIZE_MAX, 1);

            // Something semi-slow that will cause subsequent calls to be queued
            $dispatcher->call('usleep', 50000);
            $this->assertEquals(1, $dispatcher->count());

            $dispatcher->call('strlen', 'zanzibar');
            $this->assertEquals(2, $dispatcher->count());

            $promise = $dispatcher->call('strlen', 'zanzibar');
            $this->assertEquals(3, $dispatcher->count());

            $promise->when(function($error, $result) use ($reactor, $dispatcher) {
                $reactor->stop();
                $this->assertTrue(is_null($error));
                $this->assertEquals(8, $result);
                $count = $dispatcher->count();
                if ($count !== 0) {
                    $this->fail(
                        sprintf('Zero expected for dispatcher count; %d returned', $count)
                    );
                }
            });
        });
    }

    public function testNewWorkerIncludes() {
        (new NativeReactor)->run(function($reactor) {
            $dispatcher = new Dispatcher($reactor);
            $dispatcher->addStartTask(new TestAutoloaderStackable);
            $dispatcher->setOption(Dispatcher::OPT_POOL_SIZE_MAX, 1);
            $promise = $dispatcher->call('Amp\Thread\Test\AutoloadableClassFixture::test');
            $promise->when(function($error, $result) use ($reactor) {
                $this->assertEquals(42, $result);
                $reactor->stop();
            });
        });
    }

    public function testStreamingResult() {
        $this->expectOutputString("1\n2\n3\n4\n");
        (new NativeReactor)->run('Amp\Thread\Test\testUpdate');
    }
}
