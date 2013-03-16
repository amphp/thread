<?php

namespace Amp\Reactor;

interface Reactor {
    
    const TIMEOUT = 1;
    const READ = 2;
    const WRITE = 3;    
    
    function tick();
    function run();
    function stop();
    function once($interval, callable $callback);
    function repeat($interval, callable $callback);
    function onReadable($ioStream, callable $callback, $timeout);
    function onWritable($ioStream, callable $callback, $timeout);
    function cancel(Subscription $subscription);
    function getResolution();
    
}

