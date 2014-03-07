<?php

namespace Amp;

use Alert\Reactor, Alert\Promise, Alert\Future;

class Dispatcher {
    const OPT_ON_WORKER_TASK = 1;
    const OPT_THREAD_FLAGS = 2;
    const OPT_POOL_SIZE = 3;
    const OPT_TASK_TIMEOUT = 4;
    const OPT_EXEC_LIMIT = 5;
    const OPT_IPC_URI = 6;
    const OPT_UNIX_IPC_DIR = 7;

    private $reactor;
    private $ipcUri;
    private $ipcServer;
    private $ipcAcceptWatcher;
    private $workers = [];
    private $pendingIpcClients = [];
    private $pendingWorkers = [];
    private $availableWorkers = [];
    private $cachedQueueSize = 0;
    private $rejectedTasks = [];
    private $queue = [];
    private $promises = [];
    private $promiseWorkerMap = [];
    private $promiseTimeoutMap = [];
    private $taskTimeoutWatcher;
    private $taskRejector;
    private $threadStartFlags = PTHREADS_INHERIT_ALL;
    private $poolSize = 1;
    private $taskTimeout = 30;
    private $executionLimit = 1024;
    private $maxTaskQueueSize = 1024;
    private $unixIpcSocketDir;
    private $onWorkerStartTasks;
    private $timeoutInterval = 1000;
    private $isTimeoutWatcherEnabled = FALSE;
    private $isRejectionEnabled = FALSE;
    private $taskReflection;
    private $taskNotifier;
    private $nextId;
    private $now;
    private $isStarted = FALSE;
    
    public function __construct(Reactor $reactor) {
        $this->reactor = $reactor;
        $this->nextId = PHP_INT_MAX * -1;
        $this->onWorkerStartTasks = new \SplObjectStorage;
        $this->taskReflection = new \ReflectionClass('Amp\Task');
        $this->taskRejector = function() { $this->processTaskRejections(); };
        $this->taskNotifier = new TaskNotifier;
    }

    /**
     * Dispatch a procedure call to the thread pool
     *
     * This method will auto-start the thread pool if workers have not been spawned.
     *
     * @param string $procedure The name of the function to invoke
     * @param mixed $varArgs A variable-length argument list to pass the procedure
     * @throws \InvalidArgumentException if the final parameter is not a valid callback
     * @return \Alert\Future
     */
    public function call($procedure, $varArgs = NULL /*..., $argN*/) {
        if (!is_string($procedure)) {
            throw new \InvalidArgumentException(
                sprintf('%s requires a string at Argument 1', __METHOD__)
            );
        } elseif (!$this->isStarted) {
            $this->start();
        }

        if ($this->maxTaskQueueSize < 0 || $this->maxTaskQueueSize > $this->cachedQueueSize) {
            $task = $this->taskReflection->newInstanceArgs(func_get_args());
            $future = $this->acceptNewTask($task);
        } else {
            $future = $this->rejectTask();
        }

        return $future;
    }

    /**
     * Dispatch a pthreads Stackable to the thread pool for processing
     *
     * This method will auto-start the thread pool if workers have not been spawned.
     *
     * @param \Stackable $task A custom pthreads stackable
     * @return \Alert\Future
     */
    public function execute(\Stackable $task) {
        if (!$this->isStarted) {
            $this->start();
        }

        if ($this->maxTaskQueueSize < 0 || $this->maxTaskQueueSize > $this->cachedQueueSize) {
            $future = $this->acceptNewTask($task);
        } else {
            $future = $this->rejectTask();
        }

        return $future;
    }

    private function acceptNewTask(\Stackable $task) {
        $promise = new Promise;
        $promiseId = $this->nextId++;

        $this->queue[$promiseId] = [$promise, $task];
        $this->cachedQueueSize++;

        $canTimeout = $this->taskTimeout > -1;

        if ($canTimeout && !$this->isTimeoutWatcherEnabled) {
            $this->now = microtime(TRUE);
            $this->reactor->enable($this->taskTimeoutWatcher);
            $this->isTimeoutWatcherEnabled = TRUE;
            $timeoutAt = $this->taskTimeout + $this->now;
            $this->promiseTimeoutMap[$promiseId] = $timeoutAt;
        } elseif ($canTimeout) {
            $timeoutAt = $this->taskTimeout + $this->now;
            $this->promiseTimeoutMap[$promiseId] = $timeoutAt;
        }

        if ($this->availableWorkers) {
            $this->dequeueNextTask();
        }

        return $promise->getFuture();
    }

    private function dequeueNextTask() {
        $promiseId = key($this->queue);
        list($promise, $task) = $this->queue[$promiseId];

        unset($this->queue[$promiseId]);

        $worker = array_shift($this->availableWorkers);

        $this->promiseWorkerMap[$promiseId] = $worker;

        $worker->promiseId = $promiseId;
        $worker->promise = $promise;
        $worker->task = $task;
        $worker->thread->stack($task);
        $worker->thread->stack($this->taskNotifier);
    }

    private function rejectTask() {
        $promise = new Promise;

        $this->rejectedTasks[] = $promise;

        if (!$this->isRejectionEnabled) {
            $this->isRejectionEnabled = TRUE;
            $this->reactor->immediately($this->taskRejector);
        }

        return $promise->getFuture();
    }

    private function processTaskRejections() {
        $this->isRejectionEnabled = FALSE;
        foreach ($this->rejectedTasks as $promise) {
            $promise->fail(new TooBusyException);
        }
        $this->rejectedTasks = [];
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
        $results = new SharedData;
        $resultCodes = new SharedData;
        $thread = new Thread($results, $resultCodes, $this->ipcUri);

        if (!$thread->start()) {
            throw new \RuntimeException(
                'Worker thread failed to start'
            );
        }

        $worker = new Worker;
        $worker->id = $thread->getThreadId();
        $worker->results = $results;
        $worker->resultCodes = $resultCodes;
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

        if ($this->queue) {
            $this->dequeueNextTask();
        }
    }

    private function onReadableIpcClient(Worker $worker) {
        $ipcClient = $worker->ipcClient;

        if (fgetc($ipcClient)) {
            $this->processWorkerTaskResult($worker);
        } elseif (!is_resource($ipcClient) || feof($ipcClient)) {
            $this->respawnWorker($worker);
        }
    }

    private function registerTaskTimeoutWatcher() {
        if ($this->taskTimeout > -1) {
            $this->now = microtime(TRUE);
            $this->taskTimeoutWatcher = $this->reactor->repeat(function() {
                $this->timeoutOverdueTasks();
            }, $this->timeoutInterval);
            $this->isTimeoutWatcherEnabled = TRUE;
        }
    }

    private function timeoutOverdueTasks() {
        $now = microtime(TRUE);
        $this->now = $now;

        foreach ($this->promiseTimeoutMap as $promiseId => $timeoutAt) {
            if ($now >= $timeoutAt) {
                unset($this->promiseTimeoutMap[$promiseId]);
                $this->killTask($promiseId, new TimeoutException(
                    sprintf(
                        'Task timeout exceeded (%d second%s)',
                        $this->taskTimeout,
                        ($this->taskTimeout === 1 ? '' : 's')
                    )
                ));
            } else {
                break;
            }
        }
    }

    private function killTask($promiseId, DispatchException $error) {
        if (isset($this->promiseWorkerMap[$promiseId])) {
            $this->cachedQueueSize--;
            $worker = $this->promiseWorkerMap[$promiseId];
            $worker->promise->resolveSafely($error);
            $this->unloadWorker($worker);
            $worker->thread->kill();
            $this->spawnWorker();
        } else {
            $this->cachedQueueSize--;
            list($promise) = $this->queue[$promiseId];
            $promise->resolveSafely($error);
            unset($this->queue[$promiseId]);
        }
    }

    private function unloadWorker(Worker $worker) {
        $this->reactor->cancel($worker->ipcReadWatcher);

        unset(
            $this->workers[$worker->id],
            $this->availableWorkers[$worker->id],
            $this->promises[$worker->promiseId],
            $this->promiseTimeoutMap[$worker->promiseId]
        );

        if (is_resource($worker->ipcClient)) {
            @fclose($worker->ipcClient);
        }
    }

    private function processWorkerTaskResult(Worker $worker) {
        $resultCode = $worker->resultCodes->shift();
        $data = $worker->results->shift();

        if ($worker->stream) {
            $this->processStreamTaskNotification($worker, $resultCode, $data);
        } else {
            $this->processNonStreamTaskNotification($worker, $resultCode, $data);
        }
    }

    private function processNonStreamTaskNotification(Worker $worker, $resultCode, $data) {
        $result = $error = $mustKill = $isStream = NULL;

        switch ($resultCode) {
            case Thread::SUCCESS:
                $result = $data;
                break;
            case Thread::FAILURE:
                $error = new TaskException($data);
                break;
            case Thread::FATAL:
                $mustKill = TRUE;
                $error = new TaskException($data);
                break;
            case Thread::STREAM_START:
                $isStream = TRUE;
                $result = $this->generateStreamResult($worker, $data);
                break;
            default:
                $mustKill = TRUE;
                $error = new TaskException(
                    sprintf(
                        'Unexpected worker notification code: %s',
                        ord($resultCode) ? $resultCode : 'NULL'
                    )
                );
        }

        if ($isStream) {
            $worker->promise->resolve($error, $result);
        } else {
            $this->cachedQueueSize--;
            $worker->tasksExecuted++;
            $worker->promise->resolve($error, $result);
            $this->afterTaskCompletion($worker, $mustKill);
        }
    }

    private function generateStreamResult(Worker $worker, $data) {
        $stream = new FutureStream;
        $streamInjector = function($isFinalStreamElement, \Exception $error = NULL, $result = NULL) {
            $this->fulfillLastPromise($isFinalStreamElement, $error, $result);
        };
        $streamInjector = $streamInjector->bindTo($stream, $stream);
        $worker->stream = $stream;
        $worker->streamInjector = $streamInjector;

        $streamInjector($isFinal = FALSE, $error = NULL, $data);
        $worker->thread->stack($this->taskNotifier);

        return $stream;
    }

    private function afterTaskCompletion(Worker $worker, $mustKill) {
        if ($mustKill || $this->shouldRespawn($worker)) {
            $this->respawnWorker($worker);
        } else {
            $promiseId = $worker->promiseId;
            unset(
                $this->promiseWorkerMap[$promiseId],
                $this->promiseTimeoutMap[$promiseId]
            );
            $worker->promiseId = $worker->promise = $worker->task = NULL;
            $this->availableWorkers[$worker->id] = $worker;
        }

        if ($this->queue && $this->availableWorkers) {
            $this->dequeueNextTask();
        } elseif ($this->isTimeoutWatcherEnabled && !$this->queue) {
            $this->isTimeoutWatcherEnabled = FALSE;
            $this->reactor->disable($this->taskTimeoutWatcher);
        }
    }

    private function processStreamTaskNotification(Worker $worker, $resultCode, $data) {
        switch ($resultCode) {
            case Thread::STREAM_DATA:
                $mustKill = FALSE;
                $isFinalStreamElement = FALSE;
                break;
            case Thread::STREAM_END:
                $mustKill = FALSE;
                $isFinalStreamElement = TRUE;
                break;
            case Thread::FATAL:
                $mustKill = TRUE;
                $isFinalStreamElement = TRUE;
                $error = new TaskException($data);
                break;
            default:
                throw new \DomainException(
                    sprintf(
                        'Unexpected worker result code (%s); STREAM_DATA or STREAM_END required',
                        ord($resultCode) ? $resultCode : 'NULL'
                    )
                );
        }

        $streamInjector = $worker->streamInjector;
        $streamInjector($isFinalStreamElement, $error = NULL, $data);

        if ($isFinalStreamElement) {
            $this->cachedQueueSize--;
            $worker->tasksExecuted++;
            $this->afterTaskCompletion($worker, $mustKill);
        } else {
            $worker->thread->stack($this->taskNotifier);
        }
    }

    private function shouldRespawn(Worker $worker) {
        if ($this->executionLimit <= 0) {
            $shouldRespawn = FALSE;
        } elseif ($worker->tasksExecuted >= $this->executionLimit) {
            $shouldRespawn = TRUE;
        } else {
            $shouldRespawn = FALSE;
        }

        return $shouldRespawn;
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
        switch ($option) {
            case self::OPT_ON_WORKER_TASK:
                $this->addOnWorkerStartTask($value); break;
            case self::OPT_THREAD_FLAGS:
                $this->setThreadStartFlags($value); break;
            case self::OPT_POOL_SIZE:
                $this->setPoolSize($value); break;
            case self::OPT_TASK_TIMEOUT:
                $this->setTaskTimeout($value); break;
            case self::OPT_EXEC_LIMIT:
                $this->setExecutionLimit($value); break;
            case self::OPT_IPC_URI:
                $this->setIpcUri($value); break;
            case self::OPT_UNIX_IPC_DIR:
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
     * Retrieve a count of all outstanding tasks (both queued and in-progress)
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
     * @return \Alert\Future
     */
    public function __invoke(\Stackable $task) {
        return $this->execute($task);
    }

    /**
     * Assume unknown methods are dispatches for functions in the global namespace
     *
     * @param string $method
     * @param array $args
     * @return \Alert\Future
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
