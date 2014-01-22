<?php

require_once __DIR__ . '/../autoload.php';

function multiply($x, $y) {
    return $x * $y;
}

function exception() {
    throw new Exception('test');
}

function fatal() {
    $nonexistentObj->nonexistentMethod();
}

class FatalStackable extends \Stackable {
    public function run() {
        $nonexistentObj->nonexistentMethod();
    }
}

class ThrowingStackable extends \Stackable {
    public function run() {
        throw new Exception('test');
    }
}

class TestAutoloaderStackable extends \Stackable {
    function run() {
        spl_autoload_register(function() {
            require_once __DIR__ . '/AutoloadableClass.php';
        });
    }
}

class TestStreamStackable extends \Stackable {
    function run() {
        $this->worker->registerResult(\Amp\Thread::STREAM_START, 1);
        $this->worker->registerResult(\Amp\Thread::STREAM_DATA, 2);
        $this->worker->registerResult(\Amp\Thread::STREAM_DATA, 3);
        $this->worker->registerResult(\Amp\Thread::STREAM_END, 4);
    }
}

class TestStreamApp {

    private $reactor;
    private $dispatcher;
    private $resolver;

    function __construct(
        Alert\Reactor $reactor = NULL,
        Amp\PthreadsDispatcher $dispatcher = NULL,
        Amp\Resolver $resolver = NULL
    ) {
        $this->reactor = $reactor ?: (new \Alert\ReactorFactory)->select();
        $this->dispatcher = $dispatcher ?: new \Amp\PthreadsDispatcher($this->reactor);
        $this->resolver = $resolver ?: new \Amp\Resolver;
    }

    function test() {
        $this->dispatcher->execute(new TestStreamStackable)->onComplete(function($future) {
            $this->stream($future->value());
        });
    }

    private function stream(\Amp\FutureStream $stream) {
        $this->resolver->resolve($this->iterateOverStream($stream));
    }

    private function iterateOverStream($stream) {
        foreach ($stream as $future) {
            if (!$future instanceof \Amp\Future) {
                $valueToOutput = $future;
            } elseif ($future->isPending()) {
                $valueToOutput = (yield $future);
            } else {
                $valueToOutput = $future->value();
            }

            echo $valueToOutput, "\n";
        }

        $this->reactor->stop();
    }

}

class NestedFutureTest {

    private $reactor;
    private $dispatcher;

    function __construct(\Alert\Reactor $reactor, \Amp\PthreadsDispatcher $dispatcher = NULL) {
        $this->reactor = $reactor;
        $this->dispatcher = $dispatcher ?: new \Amp\PthreadsDispatcher($reactor);
    }

    function test() {
        $promise = new \Amp\Promise;
        $future = $promise->future();

        $nestedFuture = $this->dispatcher->call('strlen', 'zanzibar');
        $promise->succeed($nestedFuture);

        return $future;
    }
}

