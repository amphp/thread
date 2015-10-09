<?php

namespace Amp\Thread\Test;

use Amp\Thread\Task;
use Amp\Thread\Thread;
use Amp\Thread\Dispatcher;

function multiply($x, $y) {
    return $x * $y;
}

function exception() {
    throw new \Exception('test');
}

function fatal() {
    $nonexistentObj->nonexistentMethod();
}

class FatalThreaded extends \Threaded implements \Collectable {
    public function run() {
        $nonexistentObj->nonexistentMethod();
    }

    public function isGarbage(): bool {
        return $this->isTerminated();
    }
}

class ThrowingCollectable extends \Threaded implements \Collectable {
    public function run() {
        throw new \Exception('test');
    }

    public function isGarbage(): bool {
        return $this->isTerminated();
    }
}

class TestAutoloaderCollectable extends \Threaded implements \Collectable {
    public function run() {
        spl_autoload_register(function() {
            require_once __DIR__ . '/AutoloadableClassFixture.php';
        });
    }

    public function isGarbage(): bool {
        return $this->isTerminated();
    }
}

class TestStreamCollectable extends \Threaded implements \Collectable {
    public function run() {
        $this->worker->update(1);
        $this->worker->update(2);
        $this->worker->update(3);
        $this->worker->update(4);
        $this->worker->resolve(Thread::SUCCESS, null);
    }

    public function isGarbage(): bool {
        return $this->isTerminated();
    }
}

function testUpdate() {
    $dispatcher = new Dispatcher;
    $promise = $dispatcher->execute(new TestStreamCollectable);
    $promise->watch(function($update) {
        echo "$update\n";
    });
    $promise->when(function($error, $result) {
        assert($result === null);
        \Amp\stop();
    });
}
