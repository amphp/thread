<?php

namespace Amp;

use Alert\Reactor;

class PthreadsDispatcher implements ThreadDispatcher {

    private $reactor;
    private $ipcUri;
    private $ipcServer;
    private $ipcAcceptWatcher;
    private $isStarted = FALSE;
    private $queue;
    private $workers = [];
    private $pendingIpcClients = [];
    private $pendingWorkers = [];
    private $availableWorkers = [];
    private $queuedTaskIds = [];
    private $cachedQueueSize = 0;
    private $taskTimeoutWatcher;
    private $taskIdTimeoutMap = [];
    private $taskIdWorkerMap = [];
    private $rejectedTasks = [];
    private $taskRejector;
    private $nextTaskId;
    private $threadStartFlags = PTHREADS_INHERIT_ALL;
    private $poolSize = 1;
    private $taskTimeout = 30;
    private $executionLimit = 1024;
    private $maxTaskQueueSize = 1024;
    private $unixIpcSocketDir;
    private $onWorkerStartTasks;
    private $taskTimeoutCheckInterval = 1;
    private $isTimeoutWatcherEnabled = FALSE;
    private $isRejectionEnabled = FALSE;
    private $taskReflection;
    private $now;

    public function __construct(Reactor $reactor) {
        $this->reactor = $reactor;
        $this->taskReflection = new \ReflectionClass('Amp\Task');
        $this->nextTaskId = PHP_INT_MAX * -1;
        $this->taskRejector = function() { $this->processTaskRejections(); };
        $this->queue = new TaskPriorityQueue;
        $this->onWorkerStartTasks = new \SplObjectStorage;
    }

    /**
     * Dispatch a procedure call to the thread pool
     *
     * This method will auto-start the thread pool if workers have not been spawned.
     *
     * @param string $procedure The name of the function to invoke
     * @param mixed $varArgs A variable-length argument list to pass the procedure
     * @param callable $onResult The final argument is the callable to invoke with the invocation result
     * @throws \InvalidArgumentException if the final parameter is not a valid callback
     * @return int Returns a unique integer task ID identifying this task. This value MAY be zero.
     */
    public function call($procedure, $varArgs /* ..., $argN, callable $onResult*/) {
        if (!is_string($procedure)) {
            throw new \InvalidArgumentException(
                sprintf('%s requires a string at Argument 1', __METHOD__)
            );
        } elseif (!$this->isStarted) {
            $this->start();
        }

        $funcArgs = func_get_args();
        $onResult = array_pop($funcArgs);
        if (!is_callable($onResult)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Callable required at argument %d; %s provided',
                    func_num_args(),
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
     * Dispatch a pthreads Stackable to the thread pool for processing
     *
     * This method will auto-start the thread pool if workers have not been spawned.
     *
     * @param \Stackable $task A custom pthreads stackable
     * @param callable $onResult The callable to invoke upon completion
     * @param int $priority Task priority [1-100]
     * @throws \InvalidArgumentException On invalid priority type
     * @throws \RangeException On priority outside the acceptable range [1-100]
     * @return int Returns a unique integer task ID identifying this task. This value MAY be zero.
     */
    public function execute(\Stackable $task, callable $onResult, $priority = 50) {
        return $this->doExecution($task, $priority, $onResult);
    }

    private function doExecution(\Stackable $task, $priority, callable $onResult = NULL) {
        if (!is_int($priority)) {
            throw new \InvalidArgumentException;
        } elseif ($priority < 1 || $priority > 100) {
            throw new \RangeException;
        } elseif (!$this->isStarted) {
            $this->start();
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

    /**
     * Blindly dispatch a pthreads Stackable to the thread pool without a result callback
     *
     * This method is useful if you legitimately do not care whether a task fails or
     * encounters an error. Be very careful with its use, however. Ignoring errors can
     * lead to phantom bugs that are very difficult to diagnose!
     *
     * This method will auto-start the thread pool if workers have not been spawned.
     *
     * @param \Stackable $task A custom pthreads stackable
     * @param int $priority Task priority [1-100]
     * @throws \InvalidArgumentException On invalid priority type
     * @throws \RangeException On priority outside the acceptable range
     * @return int Returns a unique integer task ID identifying this task. This value MAY be zero.
     */
    public function forget(\Stackable $task, $priority = 50) {
        return $this->doExecution($task, $priority);
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

        $taskNotifier = $onResult ? new TaskNotifier : new ForgetNotifier;

        $worker = array_shift($this->availableWorkers);
        $worker->task = $task;
        $worker->taskNotifier = $taskNotifier;
        $worker->taskId = $taskId;
        $worker->onTaskResult = $onResult;
        $worker->thread->stack($task);
        $worker->thread->stack($taskNotifier);

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
     * No tasks will be dispatched until Dispatcher::start is invoked.
     *
     * @return \Amp\Dispatcher Returns the current object instance
     */
    public function start() {
        if (!$this->isStarted) {
            $this->generateIpcServer();
            $this->isStarted = TRUE;
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
        $thread = new Thread($sharedData, $this->ipcUri);

        if (!$thread->start()) {
            throw new \RuntimeException(
                'Worker thread failed to start'
            );
        }

        $worker = new Worker;
        $worker->id = $thread->getThreadId();
        $worker->sharedData = $sharedData;
        $worker->thread = $thread;

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

        foreach ($this->onWorkerStartTasks as $task) {
            $worker->thread->stack($task);
        }

        if ($this->queue->count()) {
            $this->dequeueNextTask();
        }
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
            $worker->thread->kill();
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

        $this->cachedQueueSize -= $taskMatchFound;

        if ($taskMatchFound && $onTaskResult && !$error instanceof CancellationException) {
            $taskResult = new TaskResult($taskId, $data = NULL, $error);
            $onTaskResult($taskResult);
        }

        return $taskMatchFound;
    }

    private function pluckResultCallbackFromQueue($taskId) {
        $this->queue->setExtractFlags(TaskPriorityQueue::EXTR_BOTH);
        $onTaskResult = NULL;

        // Eddie Izzard FTW! ... NEW QUEUE!
        $newQueue = new TaskPriorityQueue;

        foreach ($this->queue as $taskArr) {
            extract($taskArr); // $data + $priority
            if (!$onTaskResult && $data[0] == $taskId) {
                $onTaskResult = end($data);
            } else {
                $newQueue->insert($data, $priority);
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
        $result = $error = NULL;
        $wasFatal = $isPartial = FALSE;
        switch ($resultCode) {
            case Thread::SUCCESS:
                $result = $data;
                break;
            case Thread::PARTIAL:
                $result = $data;
                $isPartial = TRUE;
                break;
            case Thread::FORGET:
                break;
            case Thread::FAILURE:
                $error = new TaskException($data);
                break;
            case Thread::FATAL:
                $error = new TaskException($data);
                $wasFatal = TRUE;
                break;
            default:
                throw new \DomainException(
                    sprintf('Unrecognized worker notification code: %s', $resultCode)
                );
        }

        $taskResult = new TaskResult($worker->taskId, $result, $error, $isPartial);

        return ($isPartial)
            ? $this->sendPartialResult($taskResult, $worker)
            : $this->sendFinalResult($taskResult, $worker, $wasFatal);
    }

    private function sendPartialResult(TaskResult $taskResult, Worker $worker) {
        if ($onTaskResult = $worker->onTaskResult) {
            $onTaskResult($taskResult);
        }
    }

    private function sendFinalResult(TaskResult $taskResult, Worker $worker, $wasFatal) {
        $this->cachedQueueSize--;
        $worker->tasksExecuted++;

        if ($onTaskResult = $worker->onTaskResult) {
            $onTaskResult($taskResult);
        }

        if ($wasFatal) {
            $shouldRespawn = TRUE;
        } elseif ($this->executionLimit <= 0) {
            $shouldRespawn = FALSE;
        } elseif ($worker->tasksExecuted >= $this->executionLimit) {
            $shouldRespawn = TRUE;
        } else {
            $shouldRespawn = FALSE;
        }

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
            case 'onworkerstart':
                $this->addOnWorkerStartTask($value); break;
            case 'threadstartflags':
                $this->setThreadStartFlags($value); break;
            case 'poolsize':
                $this->setPoolSize($value); break;
            case 'tasktimeout':
                $this->setTaskTimeout($value); break;
            case 'executionlimit':
                $this->setExecutionLimit($value); break;
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

    private function addOnWorkerStartTask(\Stackable $task) {
        $this->onWorkerStartTasks->attach($task);
    }

    private function setThreadStartFlags($flags) {
        $this->threadStartFlags = $flags;
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
            'min_range' => -1,
            'default' => 256
        ]]);
    }

    private function setIpcUri($uri) {
        if ($this->isStarted) {
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
     * Retrieve a count of all outstanding tasks (queued and in-progress)
     *
     * @return int
     */
    function count() {
        return $this->cachedQueueSize;
    }

    /**
     * Execute a Stackable task in the thread pool
     *
     * @param \Stackable $task
     * @param callable $onResult
     * @param int $priority
     * @return int Returns a unique integer task ID identifying this task. This value MAY be zero.
     */
    public function __invoke(\Stackable $task, callable $onResult, $priority = 50) {
        return $this->execute($task, $onResult, $priority);
    }

    /**
     * Assume unknown methods are dispatches for functions in the global namespace
     *
     * @param string $method
     * @param array $args
     * @throws \InvalidArgumentException if the final parameter is not a valid callback
     * @return int Returns a unique integer task ID identifying this task. This value MAY be zero.
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
