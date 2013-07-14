<?php

namespace Amp;

class NativeReactor implements Reactor {
    
    use Subject;
    
    private $alarmIterationMap;
    private $alarmSubscriptionMap;
    private $subscriptionAlarmMap;
    private $streamSubscriptions;
    private $readStreams = [];
    private $readTimeouts = [];
    private $readCallbacks = [];
    private $writeStreams = [];
    private $writeTimeouts = [];
    private $writeCallbacks = [];
    private $garbage = [];
    private $isRunning = FALSE;
    
    function __construct() {
        $this->alarmIterationMap = new \SplObjectStorage;
        $this->alarmSubscriptionMap = new \SplObjectStorage;
        $this->subscriptionAlarmMap = new \SplObjectStorage;
        $this->streamSubscriptions = new \SplObjectStorage;
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
            $this->notify(self::START);
            $this->enableAlarms();
            
            while ($this->isRunning) {
                $this->tick();
            }
        }
    }
    
    private function enableAlarms() {
        foreach ($this->alarmIterationMap as $alarm) {
            $alarm->start();
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
        $this->isRunning = FALSE;
        $this->notify(self::STOP);
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
            $this->enableAlarms();
        }
        
        $timeToNextAlarm = $this->getAlarmInterval();
        
        if ($timeToNextAlarm <= 0) {
            $sec = $usec = 0;
        } elseif (strstr($timeToNextAlarm, '.')) {
            list($sec, $usec) = explode('.', $timeToNextAlarm);
            $sec = (int) $sec;
            $usec = $usec * 100;
        } else {
            $sec = (int) $timeToNextAlarm;
            $usec = 0;
        }
        
        if ($this->readStreams || $this->writeStreams) {
            $this->selectActionableStreams($sec, $usec);
        } elseif ($timeToNextAlarm > 0) {
            usleep($timeToNextAlarm * self::MICRO_RESOLUTION);
        }
        
        $this->executeScheduledEvents();
        $this->garbage = [];
    }
    
    private function getAlarmInterval() {
        $min = $this->alarmIterationMap->count() ? $this->getNextAlarmTime() : NULL;
        
        foreach ($this->readTimeouts as $streamArr) {
            foreach ($streamArr as $subscriptionId => $timeoutArr) {
                $nextAlarm = $timeoutArr[1];
                $min = (!$min || $min > $nextAlarm) ? $nextAlarm : $min;
            }
        }
        
        foreach ($this->writeTimeouts as $streamArr) {
            foreach ($streamArr as $subscriptionId => $timeoutArr) {
                $nextAlarm = $timeoutArr[1];
                $min = (!$min || $min > $nextAlarm) ? $nextAlarm : $min;
            }
        }
        
        return ($min === NULL) ? '1.0' : round(($min - microtime(TRUE)), 4);
    }
    
    private function getNextAlarmTime() {
        $this->alarmIterationMap->rewind();
        $min = $this->alarmIterationMap->current()->getNextScheduledExecutionTime();
        $this->alarmIterationMap->next();
        
        while ($this->alarmIterationMap->valid()) {
            $alarm = $this->alarmIterationMap->current();
            $next = $alarm->getNextScheduledExecutionTime();
            $min = ($next && $min > $next) ? $next : $min;
            $this->alarmIterationMap->next();
        }
        
        return $min;
    }
    
    private function selectActionableStreams($sec, $usec) {
        $r = $this->readStreams ?: [];
        $w = $this->writeStreams ?: [];
        $e = NULL;
        
        if (($r || $w) && stream_select($r, $w, $e, $sec, $usec)) {
            foreach ($r as $readableStream) {
                $this->doReadCallbacksFor($readableStream, self::READ);
            }
            foreach ($w as $writableStream) {
                $this->doWriteCallbacksFor($writableStream, self::WRITE);
            }
        }
        
        $this->notifyStreamIoTimeouts();
    }
    
    private function notifyStreamIoTimeouts() {
        $now = microtime(TRUE);
        
        foreach ($this->readTimeouts as $streamId => $subscriptionArr) {
            foreach ($subscriptionArr as $subscriptionId => $timeoutArr) {
                if (empty($this->readCallbacks[$streamId][$subscriptionId])) {
                    unset($this->readTimeouts[$streamId][$subscriptionId]);
                } elseif ($now >= $timeoutArr[1]) {
                    $callback = $this->readCallbacks[$streamId][$subscriptionId];
                    $timeoutArr[1] = $now + $timeoutArr[0];
                    $stream = $this->readStreams[$streamId];
                    $callback($stream, self::TIMEOUT);
                    $this->readTimeouts[$streamId][$subscriptionId] = $timeoutArr;
                }
            }
        }
        
        foreach ($this->writeTimeouts as $streamId => $subscriptionArr) {
            foreach ($subscriptionArr as $subscriptionId => $timeoutArr) {
                if (empty($this->writeCallbacks[$streamId][$subscriptionId])) {
                    unset($this->writeTimeouts[$streamId][$subscriptionId]);
                } elseif ($now >= $timeoutArr[1]) {
                    $callback = $this->writeCallbacks[$streamId][$subscriptionId];
                    $timeoutArr[1] = $now + $timeoutArr[0];
                    $stream = $this->writeStreams[$streamId];
                    $callback($stream, self::TIMEOUT);
                    $this->writeTimeouts[$streamId][$subscriptionId] = $timeoutArr;
                }
            }
        }
    }
    
    private function doReadCallbacksFor($stream, $flag) {
        $streamId = (int) $stream;
        
        foreach ($this->readCallbacks[$streamId] as $subscriptionId => $callback) {
            $callback($stream, $flag);
        }
        
        $this->renewReadTimeoutsFor($stream);
    }
    
    private function renewReadTimeoutsFor($stream) {
        $streamId = (int) $stream;
        $now = microtime(TRUE);
        
        if (!empty($this->readTimeouts[$streamId])) {
            foreach ($this->readTimeouts[$streamId] as $subscriptionId => $timeoutArr) {
                $timeoutArr[1] = $timeoutArr[0] + $now;
                $this->readTimeouts[$streamId][$subscriptionId] = $timeoutArr;
            }
        }
    }
    
    private function doWriteCallbacksFor($stream, $flag) {
        $streamId = (int) $stream;
        
        foreach ($this->writeCallbacks[$streamId] as $subscriptionId => $callback) {
            $callback($stream, $flag);
        }
        
        $this->renewWriteTimeoutsFor($stream);
    }
    
    private function renewWriteTimeoutsFor($stream) {
        $streamId = (int) $stream;
        $now = microtime(TRUE);
        
        if (!empty($this->writeTimeouts[$streamId])) {
            foreach ($this->writeTimeouts[$streamId] as $subscriptionId => $timeoutArr) {
                $timeoutArr[1] = $timeoutArr[0] + $now;
                $this->writeTimeouts[$streamId][$subscriptionId] = $timeoutArr;
            }
        }
    }
    
    private function executeScheduledEvents() {
        $microtime = microtime(TRUE);
        
        foreach ($this->alarmIterationMap as $alarm) {
            $invocationCount = $alarm->execute($microtime);
            $invocationLimit = $this->alarmIterationMap->offsetGet($alarm);
            
            if ($invocationLimit > 0 && $invocationLimit <= $invocationCount) {
                $this->unloadAlarm($alarm);
            }
        }
    }
    
    private function unloadAlarm(Alarm $alarm) {
        $this->alarmIterationMap->detach($alarm);
        $subscription = $this->alarmSubscriptionMap->offsetGet($alarm);
        $this->subscriptionAlarmMap->detach($subscription);
        $this->alarmSubscriptionMap->detach($alarm);
    }
    
    /**
     * Schedule a callback for immediate invocation in the next event loop iteration
     * 
     * Callbacks scheduled using this method are not associated with an event subscription and
     * cannot be cancelled or disabled once assigned. They will be executed as soon as possible
     * after the current event loop iteration completes barring an exception or stop call.
     * 
     * @param callable $callback Any valid PHP callable
     * @return void
     */
    function immediately(callable $callback) {
        $this->once($callback);
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
        return $this->schedule($callback, $delay, 1);
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
        $alarm = new Alarm($callback, $delay);
        
        if ($this->isRunning) {
            $alarm->start();
        }
        
        $iterations = ($iterations > 0) ? $iterations : 0;
        $this->alarmIterationMap->attach($alarm, $iterations);
        
        $subscription = new Subscription($this);
        $this->subscriptionAlarmMap->attach($subscription, $alarm);
        $this->alarmSubscriptionMap->attach($alarm, $subscription);
        
        return $subscription;
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
        return $this->subscribe($stream, self::READ, $callback, $timeout);
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
        return $this->subscribe($stream, self::WRITE, $callback, $timeout);
    }
    
    private function subscribe($stream, $flag, callable $callback, $timeout) {
        $subscription = new Subscription($this);
        $this->mapStreamSubscription($subscription, $stream, $flag, $callback, $timeout);
        return $subscription;
    }
    
    private function mapStreamSubscription($subscription, $stream, $flag, $callback, $timeout) {
        $streamId = (int) $stream;
        $subscriptionId = spl_object_hash($subscription);
        
        if ($flag === self::READ) {
            $this->readStreams[$streamId] = $stream;
            $this->readCallbacks[$streamId][$subscriptionId] = $callback;
        } else {
            $this->writeStreams[$streamId] = $stream;
            $this->writeCallbacks[$streamId][$subscriptionId] = $callback;
        }
        
        if (!isset($this->readTimeouts[$streamId])) {
            $this->readTimeouts[$streamId] = [];
        }
        
        if (!isset($this->writeTimeouts[$streamId])) {
            $this->writeTimeouts[$streamId] = [];
        }
        
        if ($timeout > 0) {
            $this->assignStreamTimeout($subscriptionId, $stream, $flag, $timeout);
        }
        
        $streamArr = [$stream, $flag, $callback, $timeout];
        
        $this->streamSubscriptions->attach($subscription, $streamArr);
    }
    
    private function assignStreamTimeout($subscriptionId, $stream, $flag, $timeout) {
        $streamId = (int) $stream;
        $expiry = $timeout + microtime(TRUE);
        $timeoutArr = [$timeout, $expiry];
        
        if ($flag === self::READ) {
            $this->readTimeouts[$streamId][$subscriptionId] = $timeoutArr;
        } else {
            $this->writeTimeouts[$streamId][$subscriptionId] = $timeoutArr;
        }
    }
    
    private function unmapStreamSubscription(Subscription $subscription) {
        $streamArr = $this->streamSubscriptions->offsetGet($subscription);
        list($stream, $flag, $callback, $timeout) = $streamArr;
        
        $streamId = (int) $stream;
        $subscriptionId = spl_object_hash($subscription);
        return ($flag === self::READ)
            ? $this->clearReadSubscription($streamId, $subscriptionId)
            : $this->clearWriteSubscription($streamId, $subscriptionId);
    }
    
    private function clearReadSubscription($streamId, $subscriptionId) {
        unset(
            $this->readCallbacks[$streamId][$subscriptionId],
            $this->readTimeouts[$streamId][$subscriptionId]
        );
        
        if (empty($this->readCallbacks[$streamId])) {
            unset($this->readStreams[$streamId]);
        }
        
        if (empty($this->readTimeouts[$streamId])) {
            unset($this->readTimeouts[$streamId]);
        }
    }
    
    private function clearWriteSubscription($streamId, $subscriptionId) {
        unset(
            $this->writeCallbacks[$streamId][$subscriptionId],
            $this->writeTimeouts[$streamId][$subscriptionId]
        );
        
        if (empty($this->writeCallbacks[$streamId])) {
            unset($this->writeStreams[$streamId]);
        }
        
        if (empty($this->writeTimeouts[$streamId])) {
            unset($this->writeTimeouts[$streamId]);
        }
    }
    
    /**
     * Enable a previously disabled event/stream subscription
     * 
     * @param Subscription $subscription
     * @throws \RuntimeException If the subscription has previously been cancelled
     * @return void
     */
    function enable(Subscription $subscription) {
        if ($this->streamSubscriptions->contains($subscription)) {
            $streamArr = $this->streamSubscriptions->offsetGet($subscription);
            list($stream, $flag, $callback, $timeout) = $streamArr;
            $this->mapStreamSubscription($subscription, $stream, $flag, $callback, $timeout);
        } elseif ($this->subscriptionAlarmMap->contains($subscription)) {
            $alarm = $this->subscriptionAlarmMap->offsetGet($subscription);
            $alarm->start();
        }
    }
    
    /**
     * Temporarily disable an active event or stream subscription
     * 
     * @param Subscription $subscription
     * @return void
     */
    function disable(Subscription $subscription) {
        if ($this->streamSubscriptions->contains($subscription)) {
            $this->unmapStreamSubscription($subscription);
        } elseif ($this->subscriptionAlarmMap->contains($subscription)) {
            $alarm = $this->subscriptionAlarmMap->offsetGet($subscription);
            $alarm->stop();
        }
    }
    
    /**
     * Permanently cancel an event or stream subscription
     * 
     * @param Subscription $subscription
     * @return void
     */
    function cancel(Subscription $subscription) {
        $this->disable($subscription);
        $this->streamSubscriptions->detach($subscription);
        $this->subscriptionAlarmMap->detach($subscription);
        $this->alarmSubscriptionMap->detach($subscription);
        $this->garbage[] = $subscription;
    }
    
}

