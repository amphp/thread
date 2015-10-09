<?php

namespace Amp\Thread\Test;

use Amp\NativeReactor;
use Amp\Thread\Dispatcher;

class DispatcherTest extends \PHPUnit_Framework_TestCase {
    public function setUp() {
        \Amp\reactor(new NativeReactor);
    }

    /**
     * @dataProvider provideBadOptionKeys
     * @expectedException \DomainException
     */
    public function testSetOptionThrowsOnUnknownOption($badOptionName) {
        $dispatcher = new Dispatcher;
        $dispatcher->setOption($badOptionName, 42);
    }

    public function provideBadOptionKeys() {
        return [
            [45678],
            ['unknownName']
        ];
    }

    public function testNativeFunctionDispatch() {
        $dispatcher = new Dispatcher;
        $value = \Amp\wait($dispatcher->call('strlen', 'zanzibar!'));
        $this->assertEquals(9, $value);
    }

    public function testUserlandFunctionDispatch() {
        $dispatcher = new Dispatcher;
        $value = \Amp\wait($dispatcher->call('Amp\Thread\Test\multiply', 6, 7));
        $this->assertEquals($value, 42);
    }

    /**
     * @expectedException \Amp\Thread\DispatchException
     */
    public function testErrorResultReturnedIfInvocationThrows() {
        $dispatcher = new Dispatcher;
        \Amp\wait($dispatcher->call('exception')); // should throw
    }

    /**
     * @expectedException \Amp\Thread\DispatchException
     */
    public function testErrorResultReturnedIfInvocationFatals() {
        $dispatcher = new Dispatcher;
        \Amp\wait($dispatcher->call('fatal')); // should throw
    }

    public function testNextTaskDequeuedOnCompletion() {
        $dispatcher = new Dispatcher;
        $count = 0;

        // Make sure the second call gets queued
        $dispatcher->setOption(Dispatcher::OPT_POOL_SIZE_MAX, 1);
        $dispatcher->call('usleep', 50000)->when(function() use (&$count) {
            $count++;
        });

        $dispatcher->call('strlen', 'zanzibar')->when(function($error, $result) use (&$count) {
            $count++;
            fwrite(STDERR, $error);
            $this->assertTrue(is_null($error));
            $this->assertEquals(8, $result);
            $this->assertEquals(2, $count);
            \Amp\stop();
        });

        \Amp\run();
    }

    public function testCount() {
        \Amp\reactor()->run(function() {
            $dispatcher = new Dispatcher;

            // Make sure repeated calls get queued behind the first call
            $dispatcher->setOption(Dispatcher::OPT_POOL_SIZE_MAX, 1);

            // Something semi-slow that will cause subsequent calls to be queued
            $dispatcher->call('usleep', 50000);
            $this->assertEquals(1, $dispatcher->count());

            $dispatcher->call('strlen', 'zanzibar');
            $this->assertEquals(2, $dispatcher->count());

            $promise = $dispatcher->call('strlen', 'zanzibar');
            $this->assertEquals(3, $dispatcher->count());

            $promise->when(function($error, $result) use ($dispatcher) {
                \Amp\stop();
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
        \Amp\reactor()->run(function() {
            $dispatcher = new Dispatcher;
            $dispatcher->addStartTask(new TestAutoloaderCollectable);
            $dispatcher->setOption(Dispatcher::OPT_POOL_SIZE_MAX, 1);
            $promise = $dispatcher->call('Amp\Thread\Test\AutoloadableClassFixture::test');
            $promise->when(function($error, $result) {
                $this->assertEquals(42, $result);
                \Amp\stop();
            });
        });
    }

    public function testStreamingResult() {
        $this->expectOutputString("1\n2\n3\n4\n");
        \Amp\reactor()->run('Amp\Thread\Test\testUpdate');
    }
}
