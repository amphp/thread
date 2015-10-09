<?php

namespace Amp\Thread\Test;

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

class FatalCollectable extends \Collectable {
    public function run() {
        $nonexistentObj->nonexistentMethod();
    }
}

class ThrowingCollectable extends \Collectable {
    public function run() {
        throw new \Exception('test');
    }
}

class TestAutoloaderCollectable extends \Collectable {
    public function run() {
        spl_autoload_register(function() {
            require_once __DIR__ . '/AutoloadableClassFixture.php';
        });
    }
}

class TestStreamCollectable extends \Collectable {
    public function run() {
        $this->worker->update(1);
        $this->worker->update(2);
        $this->worker->update(3);
        $this->worker->update(4);
        $this->worker->registerResult(Thread::SUCCESS, null);
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
