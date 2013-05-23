<?php

namespace Amp;

class LibeventReactor implements Reactor {
    
    private $base;
    private $subscriptions;
    private $repeatIterationMap;
    private $resolution = self::MICRO_RESOLUTION;
    private $gcInterval = 0.75;
    private $garbage = [];
    
    function __construct() {
        $this->base = event_base_new();
        $this->subscriptions = new \SplObjectStorage;
        $this->repeatIterationMap = new \SplObjectStorage;
        $this->registerGarbageCollector();
    }
    
    private function registerGarbageCollector() {
        $garbageEvent = event_new();
        event_timer_set($garbageEvent, [$this, 'collectGarbage'], $garbageEvent);
        event_base_set($garbageEvent, $this->base);
        event_add($garbageEvent, $this->gcInterval * $this->resolution);
    }
    
    private function collectGarbage($nullFd, $flags, $garbageEvent) {
        $this->garbage = [];
        event_add($garbageEvent, $this->gcInterval * $this->resolution);
    }
    
    function tick() {
        event_base_loop($this->base, EVLOOP_ONCE | EVLOOP_NONBLOCK);
    }
    
    function run() {
        event_base_loop($this->base);
    }
    
    function stop() {
        event_base_loopexit($this->base);
    }
    
    function once(callable $callback, $delay = 0) {
        $event = event_new();
        $delay = ($delay > 0) ? ($delay * $this->resolution) : 0;
        
        $subscription = new Subscription($this);
        $wrapper = function() use ($callback, $subscription) {
            $this->cancel($subscription);
            
            try {
                $callback();
            } catch (\Exception $e) {
                $this->stop();
                throw $e;
            }
        };
        
        $this->subscriptions->attach($subscription, [$event, $delay, $wrapper]);
        
        event_timer_set($event, $wrapper);
        event_base_set($event, $this->base);
        event_add($event, $delay);
        
        return $subscription;
    }
    
    function schedule(callable $callback, $delay = 0, $iterations = -1) {
        $event = event_new();
        $delay = ($delay > 0) ? ($delay * $this->resolution) : 0;
        
        $subscription = new Subscription($this);
        $iterations = filter_var($iterations, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => 0
        ]]);
        
        if ($iterations > 0) {
            $this->repeatIterationMap->attach($subscription, $iterations);
        }
        
        $wrapper = function() use ($callback, $event, $delay, $iterations, $subscription) {
            try {
                $callback();
                
                if ($iterations <= 0 || $this->canRepeat($subscription)) {
                    event_add($event, $delay);
                }
            } catch (\Exception $e) {
                $this->stop();
                throw $e;
            }
        };
        
        $this->subscriptions->attach($subscription, [$event, $delay, $wrapper]);
        
        event_timer_set($event, $wrapper);
        event_base_set($event, $this->base);
        event_add($event, $delay);
        
        return $subscription;
    }
    
    private function canRepeat(Subscription $subscription) {
        $remainingIterations = $this->repeatIterationMap->offsetGet($subscription);
        
        if (--$remainingIterations > 0) {
            $this->repeatIterationMap->offsetSet($subscription, $remainingIterations);
            return TRUE;
        } else {
            $this->cancel($subscription);
            return FALSE;
        }
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
        
        $subscription = new Subscription($this);
        $this->subscriptions->attach($subscription, [$event, $timeout, $wrapper]);
        
        return $subscription;
    }
    
    function enable(Subscription $subscription) {
        if ($this->subscriptions->contains($subscription)) {
            list($event, $interval) = $this->subscriptions->offsetGet($subscription);
            event_add($event, $interval);
        }
    }
    
    function disable(Subscription $subscription) {
        if ($this->subscriptions->contains($subscription)) {
            $event = $this->subscriptions->offsetGet($subscription)[0];
            event_del($event);
        }
    }
    
    function cancel(Subscription $subscription) {
        $this->disable($subscription);
        $this->garbage[] = $this->subscriptions->offsetGet($subscription);
        $this->subscriptions->detach($subscription);
        $this->repeatIterationMap->detach($subscription);
        
    }
    
}

