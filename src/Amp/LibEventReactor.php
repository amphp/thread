<?php

namespace Amp;

class LibEventReactor implements Reactor {
    
    const GC_INTERVAL = 1;
    
    private $base;
    private $subscriptions;
    private $resolution = 1000000;
    private $garbage = [];
    
    function __construct() {
        $this->base = event_base_new();
        $this->subscriptions = new \SplObjectStorage;
        $this->registerGarbageCollector();
    }
    
    private function registerGarbageCollector() {
        $garbageEvent = event_new();
        event_timer_set($garbageEvent, [$this, 'collectGarbage'], $garbageEvent);
        event_base_set($garbageEvent, $this->base);
        event_add($garbageEvent, self::GC_INTERVAL * $this->resolution);
    }
    
    private function collectGarbage($nullFd, $flags, $garbageEvent) {
        $this->garbage = [];
        event_add($garbageEvent, self::GC_INTERVAL * $this->resolution);
    }
    
    function tick() {
        event_base_loop($this->base, EVLOOP_ONCE | EVLOOP_NONBLOCK);
    }
    
    function run() {
        event_base_loop($this->base);
        $this->garbage = [];
    }
    
    function stop() {
        event_base_loopexit($this->base);
        $this->garbage = [];
    }
    
    function once($delay, callable $callback) {
        $event = event_new();
        $delay = ($delay > 0) ? ($delay * $this->resolution) : 0;
        
        $subscription = new LibEventSubscription($this, $event, $delay);
        $this->subscriptions->attach($subscription, $event);
        
        $wrapper = function() use ($callback, $subscription) {
            $this->cancel($subscription);
            
            try {
                $callback();
            } catch (\Exception $e) {
                $this->stop();
                throw $e;
            }
        };
        
        event_timer_set($event, $wrapper);
        event_base_set($event, $this->base);
        event_add($event, $delay);
        
        return $subscription;
    }
    
    function repeat($interval, callable $callback) {
        $event = event_new();
        $interval = ($interval > 0) ? ($interval * $this->resolution) : 0;
        
        $wrapper = function() use ($callback, $event, $interval) {
            try {
                $callback();
                event_add($event, $interval);
            } catch (\Exception $e) {
                $this->stop();
                throw $e;
            }
        };
        
        event_timer_set($event, $wrapper);
        event_base_set($event, $this->base);
        event_add($event, $interval);
        
        $subscription = new LibEventSubscription($this, $event, $interval);
        $this->subscriptions->attach($subscription, $event);
        
        return $subscription;
    }
    
    function onReadable($ioStream, callable $callback, $timeout = -1) {
        return $this->subscribe($ioStream, EV_READ | EV_PERSIST, $callback, $timeout);
    }
    
    function onWritable($ioStream, callable $callback, $timeout = -1) {
        return $this->subscribe($ioStream, EV_WRITE | EV_PERSIST, $callback, $timeout);
    }
    
    private function subscribe($ioStream, $flags, callable $callback, $timeout) {
        $event = event_new();
        $timeout = ($timeout >= 0) ? ($timeout * $this->resolution) : -1;
        
        $wrapper = function($ioStream, $triggeredBy) use ($callback) {
            try {
                $callback($ioStream, $triggeredBy);
            } catch (\Exception $e) {
                $this->stop();
                throw $e;
            }
        };
        
        event_set($event, $ioStream, $flags, $wrapper);
        event_base_set($event, $this->base);
        event_add($event, $timeout);
        
        $subscription = new LibEventSubscription($this, $event, $timeout);
        $this->subscriptions->attach($subscription);
        
        return $subscription;
    }
    
    /**
     * Sometimes it's desirable to cancel a subscription from within an event callback. We can't
     * destroy lambda callbacks inside cancel() from inside a subscribed event callback, so instead
     * we store the cancelled subscription in the garbage periodically clean up after ourselves.
     */
    function cancel(Subscription $subscription) {
        $subscription->disable();
        $this->subscriptions->detach($subscription);
        $this->garbage[] = $subscription;
    }
    
}

