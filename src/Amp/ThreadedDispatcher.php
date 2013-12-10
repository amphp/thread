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
    private $taskQueueSize = 0;
    private $taskReflection;

    function __construct(Reactor $reactor) {
        $this->state = self::$STOPPED;
        $this->reactor = $reactor;
        $this->isWindows = (stripos(PHP_OS, "WIN") === 0);
        $this->taskReflection = new \ReflectionClass('Amp\Task');
    }

    /**
     * Has the dispatcher been started?
     *
     * @return bool
     */
    function isStarted() {
        return ($this->state === self::$STARTED);
    }

    /**
     * Spawn worker threads
     *
     * No calls may be dispatched until Dispatcher::start is invoked.
     *
     * @param int $workerCount The number of worker threads to spawn
     * @return \Amp\Dispatcher Returns the current object instance
     */
    function start($workerCount = 1) {
        if ($this->state === self::$STOPPED) {
            $this->state = self::$STARTED;
            $this->workerCount = $workerCount;
            for ($i=0;$i<$workerCount;$i++) {
                $this->spawnWorker();
            }
        }
        
        return $this;
    }

    private function spawnWorker() {
        list($localSock, $threadSock) = $this->getSocketPair();
        stream_set_blocking($localSock, FALSE);

        $sharedData = new SharedData;
        $thread = new WorkerThread($sharedData, $threadSock, $this->threadBootstrapPath);
        $thread->start();

        $worker = new WorkerState;
        $worker->id = (int) $localSock;
        $worker->localSock = $localSock;
        $worker->threadSock = $threadSock;
        $worker->sharedData = $sharedData;
        $worker->thread = $thread;
        $worker->ipcWatcher = $this->reactor->onReadable($worker->localSock, function() use ($worker) {
            $this->onReadableWorker($worker);
        });

        $this->workers[$worker->id] = $worker;
        $this->availableWorkers[$worker->id] = $worker;
    }

    private function getSocketPair() {
        $args = $this->isWindows
            ? [STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_TCP]
            : [STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP];

        return call_user_func_array('stream_socket_pair', $args);
    }

    private function onReadableWorker(WorkerState $worker) {
        $socket = $worker->localSock;
        $line = fread($socket, 8192);
        $length = strlen($line);

        if ($length) {
            $this->dequeueSharedTaskResults($worker, $line, $length);
        } elseif (!is_resource($socket) || feof($socket)) {
            $this->onDeadWorker($worker);
        }
    }

    private function dequeueSharedTaskResults(WorkerState $worker, $line, $length) {
        $this->taskQueueSize -= $length;

        for ($i=0; $i<$length; $i++) {
            $data = $worker->sharedData->shift();
            $c = $line[$i];

            if ($c === '+') {
                $result = $data;
                $error = NULL;
                $isFatal = FALSE;
            } else {
                $result = NULL;
                $error = new DispatchExecutionException($data);
                $isFatal = ($c === 'x');
            }

            $result = new DispatchResult($result, $error);
            $onTaskCompletion = $worker->onTaskCompletion;

            if ($isFatal) {
                $this->onDeadWorker($worker);
            } else {
                $this->makeWorkerAvailable($worker);
            }

            if ($this->taskQueue) {
                $this->dequeueNextTask();
            }

            $onTaskCompletion($result);
        }
    }

    private function makeWorkerAvailable(WorkerState $worker) {
        $worker->onTaskCompletion = NULL;
        $worker->currentTask = NULL;
        $this->availableWorkers[$worker->id] = $worker;
    }

    private function onDeadWorker(WorkerState $worker) {
        $this->unloadWorker($worker);
        $this->spawnWorker();
    }

    private function unloadWorker(WorkerState $worker) {
        $this->reactor->cancel($worker->ipcWatcher);
        unset(
            $this->workers[$worker->id],
            $this->availableWorkers[$worker->id]
        );
        if (is_resource($worker->localSock)) {
            @fclose($worker->localSock);
        }
        if (is_resource($worker->threadSock)) {
            @fclose($worker->threadSock);
        }
        if (!$worker->thread->isShutdown()) {
            $worker->thread->shutdown();
        }
    }

    /**
     * Dispatch a call to the thread pool
     *
     * @param string $procedure The name of the function to invoke
     * @param mixed $varArgs A variable-length argument list to pass the procedure
     * @param callable $onResult The final argument is the callable to invoke with the invocation result
     * @throws \LogicException if the dispatcher has not been started
     * @throws \InvalidArgumentException if the final parameter is not a valid callback
     * @return \Amp\Dispatcher Returns the current object instance
     */
    function dispatch($procedure, $varArgs /* ..., $argN, callable $onResult*/) {
        if ($this->state === self::$STOPPED) {
            throw new \LogicException(
                'Cannot dispatch jobs; dispatcher not started!'
            );
        }

        $args = func_get_args();
        $callback = array_pop($args);
        if (!($callback && is_callable($callback))) {
            throw new \InvalidArgumentException(
                sprintf('Callable required at argument %d; %s provided', count($args), gettype($callback))
            );
        }

        $task = $this->taskReflection->newInstanceArgs($args);

        if ($this->maxQueueSize <= 0 || $this->taskQueueSize < $this->maxQueueSize) {
            $this->taskQueue[] = [$task, $callback];
            $this->taskQueueSize++;
            $this->dequeueNextTask();
        } else {
            $result = new DispatchResult($data = NULL, $error = new DispatcherBusyException(
                'Too busy; task queue full'
            ));
            $callback($result);
        }
        
        return $this;
    }

    private function dequeueNextTask() {
        if ($this->availableWorkers) {
            $worker = array_shift($this->availableWorkers);
            list($worker->currentTask, $worker->onTaskCompletion) = array_shift($this->taskQueue);
            $worker->thread->stack($worker->currentTask);
        }
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
            'min_range' => 0,
            'default' => 250
        ]]);
    }
    
    function __destruct() {
        foreach ($this->workers as $worker) {
            $this->unloadWorker($worker);
        }
    }

}
