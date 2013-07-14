<?php

namespace Amp;

interface Reactor extends Observable {
    
    const TIMEOUT = 1;
    const READ = 2;
    const WRITE = 3;
    const MICRO_RESOLUTION = 1000000;
    
    /**
     * Start the event reactor and assume program flow control
     */
    function run();
    
    /**
     * Execute a single event loop iteration
     */
    function tick();
    
    /**
     * Stop the event reactor
     */
    function stop();
    
    /**
     * Schedule a callback for immediate invocation in the next event loop iteration
     * 
     * @param callable $callback Any valid PHP callable
     */
    function immediately(callable $callback);
    
    /**
     * Schedule a callback to execute once with an optional delay
     * 
     * @param callable $callback Any valid PHP callable
     * @param float $delay An optional delay (in seconds) before the callback will be invoked
     */
    function once(callable $callback, $delay = 0);
    
    /**
     * Schedule a recurring callback
     * 
     * @param callable $callback Any valid PHP callable
     * @param float $delay An optional delay (in seconds) to observe between callback executions
     * @param int $iterations How many times should the callback repeat?
     */
    function schedule(callable $callback, $delay = 0, $iterations = -1);
    
    /**
     * Watch a stream resource for readable data and invoke the callback when data becomes available
     * 
     * @param resource $ioStream A stream resource to watch for readable data
     * @param callable $callback Any valid PHP callable
     * @param float $timeout An optional timeout after which the callback will be invoked if no activity occurred
     */
    function onReadable($stream, callable $callback, $timeout = -1);
    
    /**
     * Watch for a stream resource to become writable
     * 
     * @param resource $ioStream A stream resource to watch for writability
     * @param callable $callback Any valid PHP callable
     * @param float $timeout An optional timeout after which the callback will be invoked if no activity occurred
     */
    function onWritable($stream, callable $callback, $timeout = -1);
    
    /**
     * Enable a previously disabled event/stream subscription
     * 
     * @param Subscription $subscription
     */
    function enable(Subscription $subscription);
    
    /**
     * Temporarily disable an active event or stream subscription
     * 
     * @param Subscription $subscription
     */
    function disable(Subscription $subscription);
    
    /**
     * Permanently cancel an event or stream subscription
     * 
     * @param Subscription $subscription
     */
    function cancel(Subscription $subscription);
    
    /**
     * Is the calling context executing inside the event loop?
     */
    function isRunning();
    
}
