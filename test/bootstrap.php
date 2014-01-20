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
