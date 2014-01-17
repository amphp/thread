<?php

namespace Amp;

use Alert\Reactor;

class PthreadsDispatcher implements ThreadDispatcher {

    private static $STOPPED = 0;
    private static $STARTED = 1;
    private static $PRIORITY_HIGH = 1;
    private static $PRIORITY_NORMAL = 0;

    private $state;
    private $reactor;
    private $ipcUri;
    private $ipcServer;
    private $ipcAcceptWatcher;
    private $queue;
    private $workers = [];
    private $pendingIpcClients = [];
    private $pendingWorkers = [];
    private $availableWorkers = [];
    private $threadBootstrapPaths = [];
    private $queuedTaskIds = [];
    private $cachedQueueSize = 0;
    private $taskTimeoutWatcher;
    private $taskIdTimeoutMap = [];
    private $taskIdWorkerMap = [];
    private $rejectedTasks = [];
    private $taskRejector;
    private $nextTaskId;
    private $poolSize = 1;
    private $taskTimeout = 30;
    private $executionLimit = 512;
    private $maxTaskQueueSize = 1024;
    private $unixIpcSocketDir;
    private $taskTimeoutCheckInterval = 1;
    private $isTimeoutWatcherEnabled = FALSE;
    private $isRejectionEnabled = FALSE;
    private $taskReflection;
    private $now;

    public function __construct(Reactor $reactor) {
        $this->state = self::$STOPPED;
        $this->reactor = $reactor;
        $this->taskReflection = new \ReflectionClass('Amp\Task');
        $this->nextTaskId = PHP_INT_MAX * -1;
        $this->taskRejector = function() { $this->processTaskRejections(); };
        $this->queue = new \SplPriorityQueue;
    }

    /**
     * Dispatch a procedure call to the thread pool
     *
     * @param string $procedure The name of the function to invoke
     * @param mixed $varArgs A variable-length argument list to pass the procedure
     * @param callable $onResult The final argument is the callable to invoke with the invocation result
     * @throws \InvalidArgumentException if the final parameter is not a valid callback
     * @return int Returns a unique integer task ID identifying this task or integer zero (0) if the
     *             dispatcher is too busy to accept the task right now.
     */
    public function call($procedure, $varArgs /* ..., $argN, callable $onResult*/) {
        if (!is_string($procedure)) {
            throw new \InvalidArgumentException(
                sprintf('%s requires a string at Argument 1', __METHOD__)
            );
        }

        $funcArgs = func_get_args();
        $onResult = array_pop($funcArgs);
        if (!is_callable($onResult)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Callable required at argument %d; %s provided',
                    count($funcArgs),
                    gettype($onResult)
                )
            );
        }

        $taskId = $this->nextTaskId++;

         if ($this->isTooBusy()) {
            $this->rejectTask($taskId, $onResult);
        } else {
            $task = $this->taskReflection->newInstanceArgs($funcArgs);
            $taskQueueStruct = [$taskId, $task, $onResult];
            $this->acceptNewTask($taskQueueStruct, $priority = 50);
        }

        return $taskId;
    }

    /**
     * Dispatch a Stackable task to the thread pool for processing
     *
     * @param \Stackable $task
     * @param callable $onResult
     * @return int Returns a unique integer task ID identifying this task or integer zero (0) if the
     *             dispatcher is too busy to accept the task right now.
     */
    public function execute(\Stackable $task, callable $onResult, $priority = 50) {
        if (!is_int($priority)) {
            throw new \InvalidArgumentException;
        } elseif ($priority < 1 || $priority > 100) {
            throw new \RangeException;
        }

        $taskId = $this->nextTaskId++;

        if ($this->isTooBusy()) {
            $this->rejectTask($taskId, $onResult);
        } else {
            $taskQueueStruct = [$taskId, $task, $onResult];
            $this->acceptNewTask($taskQueueStruct, $priority);
        }

        return $taskId;
    }

    private function isTooBusy() {
        if ($this->maxTaskQueueSize <= 0) {
            $tooBusy = FALSE;
        } elseif ($this->cachedQueueSize >= $this->maxTaskQueueSize) {
            $tooBusy = TRUE;
        } else {
            $tooBusy = FALSE;
        }

        return $tooBusy;
    }

    private function acceptNewTask(array $taskQueueStruct, $priority) {
        $taskId = $taskQueueStruct[0];
        $this->queuedTaskIds[$taskId] = TRUE;
        $this->queue->insert($taskQueueStruct, $priority);
        $this->cachedQueueSize++;

        if ($this->taskTimeout > -1) {
            $this->registerTaskTimeout($taskId);
        }

        if ($this->availableWorkers) {
            $this->dequeueNextTask();
        }

        return $taskId;
    }

    private function registerTaskTimeout($taskId) {
        if (!$this->isTimeoutWatcherEnabled) {
            $this->now = microtime(TRUE);
            $this->reactor->enable($this->taskTimeoutWatcher);
            $this->isTimeoutWatcherEnabled = TRUE;
        }

        $this->taskIdTimeoutMap[$taskId] = $this->taskTimeout + $this->now;
    }

    private function dequeueNextTask() {
        list($taskId, $task, $onResult) = $this->queue->extract();

        unset($this->queuedTaskIds[$taskId]);

        $taskNotifier = new TaskNotifier;

        $worker = array_shift($this->availableWorkers);
        $worker->task = $task;
        $worker->taskNotifier = $taskNotifier;
        $worker->taskId = $taskId;
        $worker->onTaskResult = $onResult;
        $worker->slave->stack($task);
        $worker->slave->stack($taskNotifier);

        $this->taskIdWorkerMap[$taskId] = $worker;
    }

    private function rejectTask($taskId, $onResult) {
        $this->rejectedTasks[$taskId] = $onResult;

        if (!$this->isRejectionEnabled) {
            $this->isRejectionEnabled = TRUE;
            $this->reactor->immediately($this->taskRejector);
        }

        return $taskId;
    }

    private function processTaskRejections() {
        $this->isRejectionEnabled = FALSE;
        $error = new TooBusyException;
        foreach (array_keys($this->rejectedTasks) as $taskId) {
            $this->killTask($taskId, $error);
        }
    }

    /**
     * Spawn worker threads
     *
     * No tasks may be dispatched until Dispatcher::start is invoked.
     *
     * @return \Amp\Dispatcher Returns the current object instance
     */
    public function start() {
        if ($this->state !== self::$STARTED) {
            $this->generateIpcServer();
            $this->state = self::$STARTED;
            for ($i=0;$i<$this->poolSize;$i++) {
                $this->spawnWorker();
            }
            $this->registerTaskTimeoutWatcher();
        }

        return $this;
    }

    private function generateIpcUri() {
        $availableTransports = array_flip(stream_get_transports());

        if (isset($availableTransports['unix'])) {
            $dir = $this->unixIpcSocketDir ? $this->unixIpcSocketDir : sys_get_temp_dir();
            $uri = sprintf('unix://%s/amp_ipc_%s', $dir, md5(microtime()));
        } elseif (isset($availableTransports['tcp'])) {
            $uri = 'tcp://127.0.0.1:0';
        } else {
            throw new \RuntimeException(
                'Cannot bind IPC server: no usable stream transports exist'
            );
        }

        return $uri;
    }

    private function generateIpcServer() {
        $uri = $this->ipcUri ?: $this->generateIpcUri();
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        if (!$server = @stream_socket_server($uri, $errno, $errstr, $flags)) {
            throw new \RuntimeException(
                sprintf("Failed binding IPC server socket: (%d) %s", $errno, $errstr)
            );
        }

        stream_set_blocking($server, FALSE);

        $serverName = stream_socket_get_name($server, FALSE);
        $protocol = ($serverName[0] === '/') ? 'unix' : 'tcp';

        $this->ipcUri = sprintf('%s://%s', $protocol, $serverName);
        $this->ipcServer = $server;
        $this->ipcAcceptWatcher = $this->reactor->onReadable($server, function($watcherId, $server) {
            $this->acceptIpcClient($server);
        });
    }

    private function spawnWorker() {
        $sharedData = new SharedData;
        $slave = new Slave($sharedData, $this->ipcUri, $this->threadBootstrapPaths);

        if (!$slave->start()) {
            throw new \RuntimeException(
                'Worker thread failed to start'
            );
        }

        $worker = new Worker;
        $worker->id = $slave->getThreadId();
        $worker->sharedData = $sharedData;
        $worker->slave = $slave;

        $this->pendingWorkers[$worker->id] = $worker;

        return $worker;
    }

    private function acceptIpcClient($ipcServer) {
        if (!$ipcClient = @stream_socket_accept($ipcServer)) {
            throw new \RuntimeException(
                'Failed accepting IPC client'
            );
        }

        $ipcClientId = (int) $ipcClient;
        stream_set_blocking($ipcClient, FALSE);
        $readWatcher = $this->reactor->onReadable($ipcClient, function($watcherId, $ipcClient)  {
            $this->onPendingReadableIpcClient($ipcClient);
        });

        $this->pendingIpcClients[$ipcClientId] = [$ipcClient, $readWatcher];
    }

    private function onPendingReadableIpcClient($ipcClient) {
        $openMsg = @fgets($ipcClient);
        if (isset($openMsg[0])) {
            $workerId = (int) rtrim($openMsg);
            $this->openIpcClient($workerId, $ipcClient);
        } elseif (!is_resource($ipcClient) || feof($ipcClient)) {
            $this->clearPendingIpcClient($ipcClient);
        }
    }

    private function clearPendingIpcClient($ipcClient) {
        $ipcClientId = (int) $ipcClient;
        $readWatcher = end($this->pendingIpcClients[$ipcClientId]);
        $this->reactor->cancel($readWatcher);
        unset($this->pendingIpcClients[$ipcClientId]);
    }

    private function openIpcClient($workerId, $ipcClient) {
        $this->clearPendingIpcClient($ipcClient);
        if (isset($this->pendingWorkers[$workerId])) {
            $this->importPendingIpcClient($workerId, $ipcClient);
        }
    }

    private function importPendingIpcClient($workerId, $ipcClient) {
        $worker = $this->pendingWorkers[$workerId];
        unset($this->pendingWorkers[$workerId]);
        $worker->ipcClient = $ipcClient;
        $worker->ipcReadWatcher = $this->reactor->onReadable($ipcClient, function() use ($worker) {
            $this->onReadableIpcClient($worker);
        });
        $this->workers[$workerId] = $worker;
        $this->availableWorkers[$workerId] = $worker;
    }

    private function onReadableIpcClient(Worker $worker) {
        $ipcClient = $worker->ipcClient;
        $resultCode = fgetc($ipcClient);

        if (isset($resultCode[0])) {
            $this->processWorkerTaskResult($worker, $resultCode);
        } elseif (!is_resource($ipcClient) || feof($ipcClient)) {
            $this->respawnWorker($worker);
        }
    }

    private function registerTaskTimeoutWatcher() {
        if ($this->taskTimeout > -1) {
            $this->now = microtime(TRUE);
            $this->taskTimeoutWatcher = $this->reactor->repeat(function() {
                $this->timeoutOverdueTasks();
            }, $interval = $this->taskTimeoutCheckInterval);
            $this->isTimeoutWatcherEnabled = TRUE;
        }
    }

    private function timeoutOverdueTasks() {
        $now = microtime(TRUE);
        $this->now = $now;
        foreach ($this->taskIdTimeoutMap as $taskId => $timeoutAt) {
            if ($now >= $timeoutAt) {
                $this->killTask($taskId, new TimeoutException);
            } else {
                break;
            }
        }
    }

    private function killTask($taskId, DispatchException $error) {
        if (isset($this->taskIdWorkerMap[$taskId])) {
            $worker = $this->taskIdWorkerMap[$taskId];
            $onTaskResult = $worker->onTaskResult;
            $this->unloadWorker($worker);
            $worker->slave->kill();
            $this->spawnWorker();
            $taskMatchFound = TRUE;
        } elseif (isset($this->rejectedTasks[$taskId])) {
            $onTaskResult = $this->rejectedTasks[$taskId];
            unset($this->rejectedTasks[$taskId]);
            $taskMatchFound = TRUE;
        } elseif (isset($this->queuedTaskIds[$taskId])) {
            unset($this->queuedTaskIds[$taskId]);
            $onTaskResult = $this->pluckResultCallbackFromQueue($taskId);
            $taskMatchFound = TRUE;
        } else {
            $taskMatchFound = FALSE;
        }

        if ($taskMatchFound) {
            $this->cachedQueueSize--;
            $taskResult = new TaskResult($taskId, $data = NULL, $error);
            $onTaskResult($taskResult);
        }

        return $taskMatchFound;
    }

    private function pluckResultCallbackFromQueue($taskId) {
        // Eddie Izzard FTW!
        $newQueue = new \SplPriorityQueue;
        $this->queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $onTaskResult = NULL;

        foreach ($this->queue as list($taskStruct, $priority)) {
            $newQueue->insert($taskStruct, $priority);
            if (!$onTaskResult && $taskStruct[0] == $taskId) {
                $onTaskResult = end($taskStruct);
            }
        }

        $this->queue = $newQueue;

        return $onTaskResult;
    }

    private function unloadWorker(Worker $worker) {
        $this->reactor->cancel($worker->ipcReadWatcher);

        $workerId = $worker->id;

        if ($taskId = $worker->taskId) {
            unset(
                $this->workers[$workerId],
                $this->availableWorkers[$workerId],
                $this->taskIdWorkerMap[$taskId],
                $this->taskIdTimeoutMap[$taskId],
                $this->queuedTaskIds[$taskId]
            );
        } else {
            unset(
                $this->workers[$workerId],
                $this->availableWorkers[$workerId]
            );
        }

        if (is_resource($worker->ipcClient)) {
            @fclose($worker->ipcClient);
        }

    }

    private function processWorkerTaskResult(Worker $worker, $resultCode) {
        $data = $worker->sharedData->shift();

        switch ($resultCode) {
            case Slave::SUCCESS:
                $result = $data;
                $error = NULL;
                $wasFatal = FALSE;
                break;
            case Slave::FAILURE:
                $result = NULL;
                $error = new TaskException($data);
                $wasFatal = FALSE;
                break;
            case Slave::FATAL:
                $result = NULL;
                $error = new TaskException($data);
                $wasFatal = TRUE;
                break;
            default:
                throw new \DomainException(
                    sprintf('Unrecognized worker notification code: %s', $resultCode)
                );
        }

        $this->cachedQueueSize--;
        $worker->tasksExecuted++;
        $taskId = $worker->taskId;
        $taskResult = new TaskResult($taskId, $result, $error);

        if ($wasFatal) {
            $shouldRespawn = TRUE;
        } elseif ($this->executionLimit <= 0) {
            $shouldRespawn = FALSE;
        } elseif ($worker->tasksExecuted >= $this->executionLimit) {
            $shouldRespawn = TRUE;
        } else {
            $shouldRespawn = FALSE;
        }

        $this->finalizeTaskResult($worker, $taskResult, $shouldRespawn);
    }

    private function finalizeTaskResult(Worker $worker, TaskResult $taskResult, $shouldRespawn) {
        try {
            $onTaskResult = $worker->onTaskResult;
            $onTaskResult($taskResult);
        } finally {
            if ($shouldRespawn) {
                $this->respawnWorker($worker);
            }

            $taskId = $worker->taskId;

            unset(
                $this->taskIdWorkerMap[$taskId],
                $this->taskIdTimeoutMap[$taskId]
            );

            $worker->taskId = $worker->task = $worker->taskNotifier = $worker->onTaskResult = NULL;
            $this->availableWorkers[$worker->id] = $worker;

            $queueSize = $this->queue->count();

            if ($queueSize && $this->availableWorkers) {
                $this->dequeueNextTask();
            } elseif ($this->isTimeoutWatcherEnabled && !$queueSize) {
                $this->isTimeoutWatcherEnabled = FALSE;
                $this->reactor->disable($this->taskTimeoutWatcher);
            }
        }
    }

    private function respawnWorker(Worker $worker) {
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
    public function setOption($option, $value) {
        switch (strtolower($option)) {
            case 'threadbootstrappath':
                $this->setThreadBootstrapPath($value); break;
            case 'tasktimeout':
                $this->setTaskTimeout($value); break;
            case 'executionlimit':
                $this->setExecutionLimit($value); break;
            case 'poolsize':
                $this->setPoolSize($value); break;
            case 'ipcuri':
                $this->setIpcUri($value); break;
            case 'unixipcsocketdir':
                $this->setUnixIpcSocketDir($value); break;
            default:
                throw new \DomainException(
                    sprintf('Unknown option: %s', $option)
                );
        }

        return $this;
    }

    private function setThreadBootstrapPath($path) {
        if (!($path && is_string($path))) {
            throw new \InvalidArgumentException(
                "Thread bootstrap file requires a non-empty string"
            );
        } elseif (is_file($path) && is_readable($path)) {
            $this->threadBootstrapPaths[] = $path;
            $this->threadBootstrapPaths = array_unique($this->threadBootstrapPaths);
        } else {
            throw new \InvalidArgumentException(
                sprintf('Thread autoloader path must point to a readable file: %s', $path)
            );
        }
    }

    private function setPoolSize($int) {
        $this->poolSize = filter_var($int, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
            'default' => 16
        ]]);
    }

    private function setTaskTimeout($seconds) {
        $this->taskTimeout = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => 30
        ]]);
    }

    private function setExecutionLimit($int) {
        $this->executionLimit = filter_var($int, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'default' => 256
        ]]);
    }

    private function setIpcUri($uri) {
        if ($this->state === self::$STARTED) {
            throw new \RuntimeException(
                'Cannot assign IPC URI while the dispatcher is running!'
            );
        } elseif (stripos($uri, 'unix://') === 0) {
            $transport = 'unix';
        } elseif (stripos($uri, 'tcp://') === 0) {
            $transport = 'tcp';
        } else {
            throw new \DomainException(
                'Cannot set IPC server URI: tcp:// or unix:// URI scheme required'
            );
        }

        $availableTransports = array_flip(stream_get_transports());
        if (!isset($availableTransport[$transport])) {
            throw new \RuntimeException(
                sprintf('PHP is not compiled with support for %s:// streams', $transport)
            );
        }

        $this->ipcUri = $uri;
    }

    private function setUnixIpcSocketDir($dir) {
        if (!is_dir($dir) || @mkdir($dir, $permissions = 0744, $recursive = TRUE)) {
            throw new \RuntimeException(
                sprintf('Socket directory does not exist and could not be created: %s', $dir)
            );
        } else {
            $this->unixIpcSocketDir = $dir;
        }
    }

    /**
     * Cancel a previously dispatched task
     *
     * @param int $taskId The task to be cancelled
     * @return bool Returns TRUE on successful cancellation or FALSE on an unknown ID
     */
    public function cancel($taskId) {
        return $this->killTask($taskId, new CancellationException);
    }

    /**
     * Retrieve a count of tasks queued for execution in the thread pool
     *
     * @return int
     */
    function countOutstanding() {
        return $this->cachedQueueSize;
    }

    /**
     * Execute a Stackable task in the thread pool
     *
     * @param \Stackable $task
     * @param callable $onResult
     * @return int Returns a unique integer task ID identifying this task or integer zero (0) if the
     *             dispatcher is too busy to accept the task right now.
     */
    public function __invoke(\Stackable $task, callable $onResult) {
        return $this->execute($task, $onResult);
    }

    /**
     * Assume unknown methods are dispatches for functions in the global namespace
     *
     * @param string $method
     * @param array $args
     * @throws \InvalidArgumentException if the final parameter is not a valid callback
     * @return int Returns a unique integer task ID identifying this task or integer zero (0) if the
     *             dispatcher is too busy to accept the task right now.
     */
    public function __call($method, $args) {
        array_unshift($args, $method);
        return call_user_func_array([$this, 'call'], $args);
    }

    public function __destruct() {
        $this->reactor->cancel($this->taskTimeoutWatcher);

        foreach ($this->workers as $worker) {
            $this->unloadWorker($worker);
        }

        if (is_resource($this->ipcServer)) {
            @fclose($this->ipcServer);
        }

        if (stripos($this->ipcUri, 'unix://') === 0) {
            $this->unlinkUnixSocketPath($this->ipcUri);
        }
    }

    private function unlinkUnixSocketPath($absoluteUri) {
        $path = substr($absoluteUri, 7);
        if (file_exists($path)) {
            @unlink($unixPath);
        }
    }

}
