<?php

namespace Amp;

interface Reactor {
    
    const TIMEOUT = 1;
    const READ = 2;
    const WRITE = 3;    
    
    function tick();
    function run();
    function stop();
    function once($delay, callable $callback);
    function repeat($interval, callable $callback, $iterations = 0);
    function onReadable($ioStream, callable $callback, $timeout = -1);
    function onWritable($ioStream, callable $callback, $timeout = -1);
    function cancel(Subscription $subscription);
    
}

