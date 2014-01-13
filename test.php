<?php

use Alert\NativeReactor;
use Amp\ThreadedDispatcher;

require __DIR__ .'/vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

function fatal() {
    $obj->nonexistent();
}

function test() {
    return 'test';
}

$reactor = new NativeReactor;
$dispatcher = new ThreadedDispatcher($reactor);
$dispatcher->start(1);

// Will invoke fatal() inside the worker thread. But the
// shutdown function is never invoked. Changing the call
// from 'fatal' to 'test' demonstrates that the thread's
// shutdown function is called when the fatal doesn't
// occur.

$reactor->once(function() use ($reactor, $dispatcher) {
    $dispatcher->call('test', function($result) use ($reactor) {
        var_dump($result->succeeded());
        $reactor->stop();
    });
}, 0.1);

$reactor->run();