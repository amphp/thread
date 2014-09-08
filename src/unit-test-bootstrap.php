<?php

use After\Promise, After\Future;

require __DIR__ . '/../vendor/autoload.php';

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
        $this->worker->updateProgress(1);
        $this->worker->updateProgress(2);
        $this->worker->updateProgress(3);
        $this->worker->updateProgress(4);
        $this->worker->registerResult(\Amp\Thread::SUCCESS, null);
    }
}

function testUpdate($reactor) {
    $dispatcher = new Amp\Dispatcher($reactor);
    $promise = $dispatcher->execute(new TestStreamStackable);
    $promise->watch(function($update) {
        echo "$update\n";
    });
    $promise->when(function($error, $result) use ($reactor) {
        assert($result === null);
        $reactor->stop();
    });
}
