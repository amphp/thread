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
