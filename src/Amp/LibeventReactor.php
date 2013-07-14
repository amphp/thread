<?php

namespace Amp;

/**
 * An event reactor utilizing ext/libevent. It's awesome. The End.
 */
class LibeventReactor implements Reactor {
    
    use Subject;
    
    private $eventBase;
    private $subscriptions;
    private $repeatIterationMap;
    private $resolution = self::MICRO_RESOLUTION;
    private $subscriptionEnabler;
    private $enableOnStartQueue = [];
    private $garbage = [];
    private $garbageCollectionEvent;
    private $isGarbageCollectionScheduled = FALSE;
    private $isRunning = FALSE;
    private $stopException;
    
    function __construct() {
        $this->eventBase = event_base_new();
        $this->subscriptions = new \SplObjectStorage;
        $this->repeatIterationMap = new \SplObjectStorage;
        
        $this->subscriptionEnabler = function(Subscription $subscription, $newStatus) {
            $this->changeSubscriptionStatus($subscription, $newStatus);
        };
        
        $this->garbageCollectionEvent = $gcEvent = event_new();
        event_timer_set($gcEvent, [$this, 'collectGarbage']);
        event_base_set($gcEvent, $this->eventBase);
    }
    
    /**
     * Start the event reactor
     * 
     * Give program control to the reactor and begin event loop iteration. Program control will not
     * be returned until Reactor::stop is invoked or an exception is thrown. If the reactor is
     * already running this function has no effect.
     * 
     * @return void
     */
    function run() {
        if (!$this->isRunning) {
            $this->isRunning = TRUE;
            $this->enableOnStart();
            $this->notify(self::START);
            event_base_loop($this->eventBase);
            $this->isRunning = FALSE;
        }
    }
    
    private function enableOnStart() {
        foreach ($this->enableOnStartQueue as $eventArr) {
            list($event, $interval) = $eventArr;
            event_add($event, $interval);
        }
    }
    
    /**
     * Is the reactor running at the moment?
     * 
     * @return bool
     */
    function isRunning() {
        return $this->isRunning;
    }
    
    /**
     * Stop the event reactor
     * 
     * When the reactor stops, all scheduled events are disabled but not cancelled. If the reactor
     * is subsequently restarted these unresolved event subscriptions will be re-enabled for
     * execution.
     * 
     * @return void
     */
    function stop() {
        $this->storeUnresolvedEventsForReenable();
        $this->scheduleGarbageCollection();
        $this->isRunning = FALSE;
        $this->notify(self::STOP);
        
        if ($this->stopException) {
            throw $this->stopException;
        }
    }
    
    private function storeUnresolvedEventsForReenable() {
        foreach ($this->subscriptions as $subscription) {
            $eventArr = $this->subscriptions->offsetGet($subscription);
            if ($subscription->isEnabled()) {
                $this->enableOnStartQueue[] = $eventArr;
            }
            $event = $eventArr[0];
            event_del($event);
        }
    }
    
    /**
     * Execute a single event loop iteration
     * 
     * Unlike Reactor::run, this method will execute a single event loop iteration and immediately
     * return control of the program back to the calling context. If the reactor is already running
     * this function has no effect.
     * 
     * @return void
     */
    function tick() {
        if (!$this->isRunning) {
            $this->isRunning = TRUE;
            $this->enableOnStart();
            event_base_loop($this->eventBase, EVLOOP_ONCE | EVLOOP_NONBLOCK);
            $this->isRunning = FALSE;
        }
    }
    
    /**
     * Schedule a callback for immediate invocation in the next event loop iteration
     * 
     * Callbacks scheduled using this method are not associated with an event subscription and
     * cannot be cancelled or disabled prior to invocation. They will be executed as soon as 
     * possible after the current event loop iteration completes barring an exception or stop call.
     * 
     * @param callable $callback Any valid PHP callable
     * @return void
     */
    function immediately(callable $callback) {
        $this->once($callback, $delay = 0);
    }
    
    /**
     * Schedule a callback to execute once with an optional delay
     * 
     * Callbacks scheduled using Reactor::once are associated with an event subscription and may
     * be enabled, disabled or cancelled prior to execution.
     * 
     * @param callable $callback Any valid PHP callable
     * @param float $delay An optional delay (in seconds) until the callback should be executed
     * @return Subscription Returns a subscription referencing the scheduled event
     */
    function once(callable $callback, $delay = 0) {
        $event = event_new();
        $delay = ($delay > 0) ? ($delay * $this->resolution) : 0;
        
        $subscription = new Subscription($this->subscriptionEnabler);
        $wrapper = function() use ($callback, $subscription) {
            $this->cancel($subscription);
            
            try {
                $callback();
            } catch (\Exception $e) {
                $this->stopException = $e;
                $this->stop();
            }
        };
        
        $this->subscriptions->attach($subscription, [$event, $delay, $wrapper]);
        
        event_timer_set($event, $wrapper);
        event_base_set($event, $this->eventBase);
        event_add($event, $delay);
        
        return $subscription;
    }
    
    /**
     * Schedule a recurring callback.
     * 
     * Schedule a callback to be invoked at the recurring interval specified by the $delay argument.
     * An optional number of iterations for which the callback should be repeated may be specified
     * by the third parameter, $iterations. If the iteration count is less than or equal to zero
     * the callback will repeat infinitely at the interval specified by the $delay parameter until
     * its subscription is explicitly cancelled.
     * 
     * IMPORTANT:
     * ==========
     * For events scheduled to execute forever ($iterations <= 0), the event subscription returned
     * MUST be retained by the calling context for future cancellation or the program will never
     * end (barring an uncaught exception). Callbacks scheduled with a finite number of iterations
     * will have their subscriptions and associated memory automatically garbage collected once
     * the predefined iteration count is reached.
     * 
     * @param callable $callback Any valid PHP callable
     * @param float $delay An optional delay (in seconds) to observe between callback executions
     * @param int $iterations How many times should the callback repeat?
     * @return Subscription Returns a subscription referencing the recurring event
     */
    function schedule(callable $callback, $delay = 0, $iterations = -1) {
        $event = event_new();
        $delay = ($delay > 0) ? ($delay * $this->resolution) : 0;
        
        $subscription = new Subscription($this->subscriptionEnabler);
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
                $this->stopException = $e;
                $this->stop();
            }
        };
        
        $this->subscriptions->attach($subscription, [$event, $delay, $wrapper]);
        
        event_timer_set($event, $wrapper);
        event_base_set($event, $this->eventBase);
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
    
    /**
     * Watch a stream resource for readable data and invoke the callback when said data is available
     * 
     * When invoked, callbacks are passed two arguments: the stream resource with readable data and
     * the trigger that caused the invocation. In the event of readable data the trigger value is
     * equal to the Reactor::READ constant. If invocation resulted from a timeout the trigger value
     * equates to the Reactor::TIMEOUT constant.
     * 
     * Note that for our purposes EOF (end of file) counts as "readable data." For example, readable
     * subscription callbacks will be notified when a socket stream reaches EOF due to a disconnect
     * from the other part.
     * 
     * IMPORTANT:
     * ==========
     * Stream subscriptions are NOT automatically garbage collected when a stream resource is closed.
     * This means that unless you want to create memory leaks in your application you MUST manually
     * call the returned subscription's cancel() method when you're finished with the stream.
     * 
     * @param resource $stream A stream resource to watch for readable data
     * @param callable $callback Any valid PHP callable
     * @param float $timeout An optional timeout after which the callback will be invoked if no activity occurred
     * @return Subscription Returns a subscription referencing the event
     */
    function onReadable($stream, callable $callback, $timeout = -1) {
        return $this->watchStream($stream, EV_READ | EV_PERSIST, $callback, $timeout);
    }
    
    /**
     * Watch for a stream resource to become writable
     * 
     * When invoked, callbacks are passed two arguments: the writable stream resource and the
     * trigger that caused the invocation. In the event of readable data the trigger value is equal
     * to the Reactor::WRITE constant. If invocation resulted from a timeout the trigger value
     * equates to the Reactor::TIMEOUT constant.
     * 
     * IMPORTANT:
     * ==========
     * Stream subscriptions are NOT automatically garbage collected when a stream resource is closed.
     * This means that unless you want to create memory leaks in your application you MUST manually
     * call the returned subscription's cancel() method when you're finished with the stream.
     * 
     * @param resource $stream A stream resource to watch for writability
     * @param callable $callback Any valid PHP callable
     * @param float $timeout An optional timeout after which the callback will be invoked if no activity occurred
     * @return Subscription Returns a subscription referencing the event
     */
    function onWritable($stream, callable $callback, $timeout = -1) {
        return $this->watchStream($stream, EV_WRITE | EV_PERSIST, $callback, $timeout);
    }
    
    private function watchStream($stream, $flags, callable $callback, $timeout) {
        $event = event_new();
        $timeout = ($timeout >= 0) ? ($timeout * $this->resolution) : -1;
        
        $wrapper = function($stream, $triggeredBy) use ($callback) {
            try {
                $callback($stream, $triggeredBy);
            } catch (\Exception $e) {
                $this->stopException = $e;
                $this->stop();
            }
        };
        
        event_set($event, $stream, $flags, $wrapper);
        event_base_set($event, $this->eventBase);
        event_add($event, $timeout);
        
        $subscription = new Subscription($this->subscriptionEnabler);
        $this->subscriptions->attach($subscription, [$event, $timeout, $wrapper]);
        
        return $subscription;
    }
    
    private function changeSubscriptionStatus(Subscription $subscription, $newStatus) {
        switch ($newStatus) {
            case Subscription::ENABLED:
                $this->enable($subscription);
                break;
            case Subscription::DISABLED:
                $this->disable($subscription);
                break;
            case Subscription::CANCELLED:
                $this->cancel($subscription);
                break;
        }
    }
    
    private function enable(Subscription $subscription) {
        list($event, $interval) = $this->subscriptions->offsetGet($subscription);
        event_add($event, $interval);
    }
    
    private function disable(Subscription $subscription) {
        $event = $this->subscriptions->offsetGet($subscription)[0];
        event_del($event);
    }
    
    private function cancel(Subscription $subscription) {
        $this->garbage[] = $subscriptionArr = $this->subscriptions->offsetGet($subscription);
        $event = $subscriptionArr[0];
        event_del($event);
        $this->subscriptions->detach($subscription);
        $this->repeatIterationMap->detach($subscription);
        $this->scheduleGarbageCollection();
    }
    
    private function scheduleGarbageCollection() {
        if (!$this->isGarbageCollectionScheduled) {
            event_add($this->garbageCollectionEvent, 0);
            $this->isGarbageCollectionScheduled = TRUE;
        }
    }
    
    private function collectGarbage() {
        $this->garbage = [];
        $this->isGarbageCollectionScheduled = FALSE;
        event_del($this->garbageCollectionEvent);
        
        if (!$this->isRunning) {
            event_base_loopexit($this->eventBase);
        }
    }
    
}

