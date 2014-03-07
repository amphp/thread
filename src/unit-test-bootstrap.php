<?php

use Alert\Promise, Alert\Future;

require __DIR__ . '/../vendor/Alert/src/bootstrap.php';

/**
 * Differs from primary autoloader by routing classes suffixed with "Test"
 * to the "test/" directory instead of "lib/" ...
 */
spl_autoload_register(function($class) {
    if (strpos($class, 'Amp\\') === 0) {
        $dir = strcasecmp(substr($class, -4), 'Test') ? 'lib' : 'test';
        $name = substr($class, strlen('Amp'));
        $file = __DIR__ . '/../' . $dir . strtr($name, '\\', DIRECTORY_SEPARATOR) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});








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
            require_once __DIR__ . '/../test/AutoloadableClass.php';
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

    function __construct(Alert\Reactor $reactor = NULL, Amp\Dispatcher $dispatcher = NULL) {
        $this->reactor = $reactor ?: (new \Alert\ReactorFactory)->select();
        $this->dispatcher = $dispatcher ?: new \Amp\Dispatcher($this->reactor);
    }

    function test() {
        $future = $this->dispatcher->execute(new TestStreamStackable);
        $future->onComplete(function($future) {
            $this->stream($future->getValue());
        });
    }

    private function stream(\Amp\FutureStream $stream) {
        while ($stream->valid()) {
            $future = $stream->current();
            if (!$future->isComplete()) {
                return $future->onComplete(function() use ($stream) {
                    $this->stream($stream);
                });
            } else {
                echo $future->getValue(), "\n";
            }
            $stream->next();
        }

        $this->reactor->stop();
    }
}

class NestedFutureTest {

    private $reactor;
    private $dispatcher;

    function __construct(\Alert\Reactor $reactor, \Amp\Dispatcher $dispatcher = NULL) {
        $this->reactor = $reactor;
        $this->dispatcher = $dispatcher ?: new \Amp\Dispatcher($reactor);
    }

    function test() {
        $promise = new Promise;
        $future = $promise->getFuture();

        $nestedFuture = $this->dispatcher->call('strlen', 'zanzibar');
        $promise->succeed($nestedFuture);

        return $future;
    }
}