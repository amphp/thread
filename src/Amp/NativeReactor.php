<?php

namespace Amp;

class NativeReactor implements Reactor {
    
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
    
    function stop() {
        $this->isRunning = FALSE;
    }
    
    function run() {
        foreach ($this->alarmIterationMap as $alarm) {
            $alarm->start();
        }
        
        $this->isRunning = TRUE;
        
        while ($this->isRunning) {
            $this->tick();
        }
    }
    
    function tick() {
        $timeToNextAlarm = $this->getAlarmInterval();
        
        if ($timeToNextAlarm <= 0) {
            $sec = $usec = 0;
        } else {
            list($sec, $usec) = explode('.', $timeToNextAlarm);
            $sec = (int) $sec;
            $usec = $usec * 100;
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
    
    function once(callable $callback, $interval = 0) {
        return $this->schedule($callback, $interval, 1);
    }
    
    function schedule(callable $callback, $interval = 0, $iterations = -1) {
        $alarm = new Alarm($callback, $interval);
        
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
    
    function onReadable($stream, callable $callback, $timeout = -1) {
        return $this->subscribe($stream, self::READ, $callback, $timeout);
    }
    
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
    
    function disable(Subscription $subscription) {
        if ($this->streamSubscriptions->contains($subscription)) {
            $this->unmapStreamSubscription($subscription);
        } elseif ($this->subscriptionAlarmMap->contains($subscription)) {
            $alarm = $this->subscriptionAlarmMap->offsetGet($subscription);
            $alarm->stop();
        }
    }
    
    function cancel(Subscription $subscription) {
        $this->disable($subscription);
        $this->streamSubscriptions->detach($subscription);
        $this->subscriptionAlarmMap->detach($subscription);
        $this->alarmSubscriptionMap->detach($subscription);
        $this->garbage[] = $subscription;
    }
    
}

