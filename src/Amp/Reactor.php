<?php

namespace Amp;

interface Reactor {
    
    const MICRO_RESOLUTION = 1000000;
    const TIMEOUT = 1;
    const READ = 2;
    const WRITE = 3;
    
    function run();
    function tick();
    function stop();
    
    function onReadable($stream, callable $callback, $timeout = -1);
    function onWritable($stream, callable $callback, $timeout = -1);
    
    function schedule(callable $callback, $delayInSeconds = 0, $iterations = -1);
    function once(callable $callback, $delayInSeconds = 0);
    
    function enable(Subscription $subscription);
    function disable(Subscription $subscription);
    function cancel(Subscription $subscription);
    
}

