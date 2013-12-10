<?php

require_once __DIR__ . '/../vendor/autoload.php';

function multiply($x, $y) {
    return $x * $y;
}

function exception() {
    throw new Exception('test');
}