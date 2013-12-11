<?php

namespace Amp;

use Alert\Reactor;

class ThreadedDispatcher implements Dispatcher {

    private static $STOPPED = 0;
    private static $STARTED = 1;

    private $state;
    private $reactor;
    private $workers = [];
    private $availableWorkers = [];
    private $isWindows;
    private $threadBootstrapPath;
    private $maxQueueSize = 250;
    private $outstandingCallCount = 0;
    private $callQueueSize = 0;
    private $callReflection;
    private $callTimeoutWatcher;
    private $callIdTimeoutMap = [];
    private $callIdWorkerMap = [];
    private $nextCallId = 1;
    private $callTimeout = 3;
    private $callTimeoutCheckInterval = 1;
    private $timeoutsCurrentlyEnabled = FALSE;
    private $now;

    function __construct(Reactor $reactor) {
        $this->state = self::$STOPPED;
        $this->reactor = $reactor;
        $this->isWindows = (stripos(PHP_OS, "WIN") === 0);
        $this->callReflection = new \ReflectionClass('Amp\Call');
    }

    /**
     * Dispatch a call to the thread pool
     *
     * @param string $procedure The name of the function to invoke
     * @param mixed $varArgs A variable-length argument list to pass the procedure
     * @param callable $onResult The final argument is the callable to invoke with the invocation result
     * @throws \InvalidArgumentException if the final parameter is not a valid callback
     * @return int Returns a unique integer call ID identifying this call or integer zero (0) if the
     *             dispatcher is too busy to handle the call right now.
     */
    function call($procedure, $varArgs /* ..., $argN, callable $onResult*/) {
        $args = func_get_args();
        $lastArg = end($args);

        if (!is_callable($lastArg)) {
            throw new \InvalidArgumentException(
                sprintf('Callable required at argument %d; %s provided', count($args), gettype($lastArg))
            );
        } elseif ($this->canAcceptNewCall()) {
            $callId = $this->acceptNewCall($args);
        } else {
            $callId = 0;
        }

        return $callId;
    }

    /**
     * Cancel a previously dispatched call
     *
     * @param int $callId The call to be cancelled
     * @return bool Returns TRUE on successful cancellation or FALSE on an unknown ID
     */
    function cancel($callId) {
        return $this->killCall($callId, new CallCancelledException);
    }

    private function canAcceptNewCall() {
        if ($this->maxQueueSize < 0) {
            $canAccept = TRUE;
        } elseif ($this->callQueueSize < $this->maxQueueSize) {
            $canAccept = TRUE;
        } elseif ($this->availableWorkers) {
            $canAccept = TRUE;
        } else {
            $canAccept = FALSE;
        }

        return $canAccept;
    }

    private function acceptNewCall(array $args) {
        $callId = $this->nextCallId++;

        $this->callQueue[$callId] = $args;
        $this->callQueueSize++;

        if ($this->callTimeout > -1) {
            $this->registerCallTimeout($callId);
        }

        if ($this->availableWorkers) {
            $this->dequeueNextCall();
        }

        return $callId;
    }

    private function registerCallTimeout($callId) {
        if (!$this->timeoutsCurrentlyEnabled) {
            $this->now = microtime(TRUE);
            $this->reactor->enable($this->callTimeoutWatcher);
            $this->timeoutsCurrentlyEnabled = TRUE;
        }

        $this->callIdTimeoutMap[$callId] = $this->callTimeout + $this->now;
    }

    private function dequeueNextCall() {
        $callId = key($this->callQueue);

        // I know you want to, but don't use array_shift here! It will reindex our numeric $callId keys!
        $callArgs = $this->callQueue[$callId];
        unset($this->callQueue[$callId]);

        $afterCall = array_pop($callArgs);

        $call = $this->callReflection->newInstanceArgs($callArgs);

        $worker = array_shift($this->availableWorkers);
        $worker->call = $call;
        $worker->callId = $callId;
        $worker->afterCall = $afterCall;
        $worker->thread->stack($call);

        $this->callIdWorkerMap[$callId] = $worker;
        $this->outstandingCallCount++;
    }

    /**
     * Spawn worker threads
     *
     * No calls may be dispatched until Dispatcher::start is invoked.
     *
     * @param int $workerCount The number of worker threads to spawn
     * @return \Amp\Dispatcher Returns the current object instance
     */
    function start($workerCount) {
        if ($this->state === self::$STARTED) {
            return;
        } elseif ($workerCount > 0) {
            $this->state = self::$STARTED;
            $this->workerCount = (int) $workerCount;
            for ($i=0;$i<$workerCount;$i++) {
                $this->spawnWorker();
            }
            $this->registerCallTimeoutWatcher();
        } else {
            throw new \InvalidArgumentException(
                'Argument 1 requires a positive integer'
            );
        }

        return $this;
    }

    private function spawnWorker() {
        list($localSock, $threadSock) = $this->generateSocketPair();
        stream_set_blocking($localSock, FALSE);

        $sharedData = new SharedData;

        $workerId = (int) $localSock;

        $worker = new WorkerState;
        $worker->id = $workerId;
        $worker->localSock = $localSock;
        $worker->threadSock = $threadSock;
        $worker->sharedData = $sharedData;
        $worker->thread = new WorkerThread($sharedData, $threadSock, $this->threadBootstrapPath);
        $worker->thread->start();
        $worker->ipcWatcher = $this->reactor->onReadable($localSock, function() use ($worker) {
            $this->onReadableWorker($worker);
        });

        $this->workers[$worker->id] = $worker;
        $this->availableWorkers[$worker->id] = $worker;
    }

    private function generateSocketPair() {
        $args = $this->isWindows
            ? [STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_TCP]
            : [STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP];

        return call_user_func_array('stream_socket_pair', $args);
    }

    private function registerCallTimeoutWatcher() {
        if ($this->callTimeout > -1) {
            $this->now = microtime(TRUE);
            $this->callTimeoutWatcher = $this->reactor->repeat(function() {
                $this->timeoutOverdueCalls();
            }, $interval = $this->callTimeoutCheckInterval);
            $this->timeoutsCurrentlyEnabled = TRUE;
        }
    }

    private function timeoutOverdueCalls() {
        $now = microtime(TRUE);
        $this->now = $now;
        foreach ($this->callIdTimeoutMap as $callId => $timeoutAt) {
            if ($now >= $timeoutAt) {
                $this->killCall($callId, new CallTimeoutException);
            } else {
                break;
            }
        }
    }

    private function killCall($callId, DispatcherException $error) {
        if (isset($this->callIdWorkerMap[$callId])) {
            $worker = $this->callIdWorkerMap[$callId];
            $afterCall = $worker->afterCall;
            $this->unloadWorker($worker);
            $worker->thread->kill();
            $this->reactor->immediately(function() {
                $this->spawnWorker();
            });
            $killSucceeded = TRUE;
        } elseif (isset($this->callQueue[$callId])) {
            $afterCall = end($this->callQueue[$callId]);
            unset(
                $this->callQueue[$callId]
            );
            $killSucceeded = TRUE;
        } else {
            // In case Dispatcher::cancel uses an unknown call ID
            $killSucceeded = FALSE;
        }

        if ($killSucceeded) {
            $callResult = new CallResult($callId, $data = NULL, $error);
            $afterCall($callResult);
            $this->outstandingCallCount--;
        }

        return $killSucceeded;
    }

    private function unloadWorker(WorkerState $worker) {
        $this->reactor->cancel($worker->ipcWatcher);

        $workerId = $worker->id;
        $callId = $worker->callId;

        unset(
            $this->workers[$workerId],
            $this->availableWorkers[$workerId],
            $this->callIdWorkerMap[$callId],
            $this->callIdTimeoutMap[$callId]
        );

        if (is_resource($worker->localSock)) {
            @fclose($worker->localSock);
        }
        if (is_resource($worker->threadSock)) {
            @fclose($worker->threadSock);
        }
    }

    private function onReadableWorker(WorkerState $worker) {
        $socket = $worker->localSock;
        $resultChar = fgetc($socket);

        if (isset($resultChar[0])) {
            $this->dequeueWorkerCallResult($worker, $resultChar);
        } elseif (!is_resource($socket) || feof($socket)) {
            echo "---OMG DEAD WORKER\n";
            $this->onDeadWorker($worker);
        }
    }

    private function dequeueWorkerCallResult(WorkerState $worker, $resultChar) {
        $this->callQueueSize--;

        $data = $worker->sharedData->shift();

        if ($resultChar === '+') {
            $result = $data;
            $error = NULL;
            $isFatal = FALSE;
        } else {
            $result = NULL;
            $error = new CallException($data);
            $isFatal = ($resultChar === 'x');
        }

        $callId = $worker->callId;
        $callResult = new CallResult($callId, $result, $error);
        $afterCall = $worker->afterCall;
        $afterCall($callResult);
        $this->outstandingCallCount--;

        if ($isFatal) {
            $this->onDeadWorker($worker);
        } else {
            $this->makeWorkerAvailable($worker);
        }

        if ($this->callQueue && $this->availableWorkers) {
            $this->dequeueNextCall();
        } elseif ($this->timeoutsCurrentlyEnabled && !($this->callQueue || $this->outstandingCallCount)) {
            $this->timeoutsCurrentlyEnabled = FALSE;
            $this->reactor->disable($this->callTimeoutWatcher);
        }
    }

    private function makeWorkerAvailable(WorkerState $worker) {
        $callId = $worker->callId;

        unset(
            $this->callIdWorkerMap[$callId],
            $this->callIdTimeoutMap[$callId]
        );

        $worker->callId = NULL;
        $worker->call = NULL;
        $worker->afterCall = NULL;

        $this->availableWorkers[$worker->id] = $worker;
    }

    private function onDeadWorker(WorkerState $worker) {
        $this->unloadWorker($worker);
        $this->spawnWorker();
    }

    /**
     * Configure dispatcher options
     *
     * @param string $option A case-insensitive option key
     * @param mixed $value The value to assign
     * @throws \DomainException On unknown option key
     * @return \Amp\Dispatcher Returns the current object instance
     */
    function setOption($option, $value) {
        switch (strtolower($option)) {
            case 'threadbootstrappath':
                $this->setThreadBootstrapPath($value); break;
            case 'maxqueuesize':
                $this->setMaxQueueSize($value); break;
            default:
                throw new \DomainException(
                    sprintf('Unknown option: %s', $option)
                );
        }

        return $this;
    }

    private function setThreadBootstrapPath($path) {
        if (is_file($path) && is_readable($path)) {
            $this->threadBootstrapPath = $path;
        } else {
            throw new \InvalidArgumentException(
                sprintf('Thread autoloader path must point to a readable file: %s', $path)
            );
        }
    }

    private function setMaxQueueSize($int) {
        $this->maxQueueSize = filter_var($int, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => -1,
            'default' => 250
        ]]);
    }

    function __destruct() {
        $this->reactor->cancel($this->callTimeoutWatcher);
        foreach ($this->workers as $worker) {
            $this->unloadWorker($worker);
        }
    }

}
