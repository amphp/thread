<?php

namespace Amp\Thread\Test;

use Amp\Promise;
use Amp\Future;
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

class FatalStackable extends \Stackable {
    public function run() {
        $nonexistentObj->nonexistentMethod();
    }
}

class ThrowingStackable extends \Stackable {
    public function run() {
        throw new \Exception('test');
    }
}

class TestAutoloaderStackable extends \Stackable {
    public function run() {
        spl_autoload_register(function() {
            require_once __DIR__ . '/AutoloadableClassFixture.php';
        });
    }
}

class TestStreamStackable extends \Stackable {
    public function run() {
        $this->worker->update(1);
        $this->worker->update(2);
        $this->worker->update(3);
        $this->worker->update(4);
        $this->worker->registerResult(Thread::SUCCESS, null);
    }
}

function testUpdate($reactor) {
    $dispatcher = new Dispatcher($reactor);
    $promise = $dispatcher->execute(new TestStreamStackable);
    $promise->watch(function($update) {
        echo "$update\n";
    });
    $promise->when(function($error, $result) use ($reactor) {
        assert($result === null);
        $reactor->stop();
    });
}
