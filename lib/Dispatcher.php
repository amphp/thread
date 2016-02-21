<?php

namespace Amp\Thread;

use Amp\Deferred;
use Amp\Reactor;
use Amp\Failure;

class Dispatcher {
    const OPT_THREAD_FLAGS = 1;
    const OPT_POOL_SIZE_MIN = 2;
    const OPT_POOL_SIZE_MAX = 3;
    const OPT_TASK_TIMEOUT = 4;
    const OPT_IDLE_WORKER_TIMEOUT = 5;
    const OPT_EXEC_LIMIT = 6;
    const OPT_IPC_URI = 7;
    const OPT_UNIX_IPC_DIR = 8;

    /** @var Reactor */
    private $reactor;
    private $ipcUri;
    private $ipcServer;
    private $ipcAcceptWatcher;
    private $workers = [];
    private $pendingIpcClients = [];
    private $pendingWorkers = [];
    private $pendingWorkerCount = 0;
    private $availableWorkers = [];
    private $outstandingTaskCount = 0;
    private $queue = [];
    private $promises = [];
    private $promiseWorkerMap = [];
    private $promiseTimeoutMap = [];
    private $timeoutWatcher;
    private $threadStartFlags = PTHREADS_INHERIT_ALL;
    private $poolSize = 0;
    private $poolSizeMin = 1;
    private $poolSizeMax = 8;
    private $taskTimeout = 30;
    private $executionLimit = 2048;
    private $maxTaskQueueSize = 1024;
    private $unixIpcSocketDir;
    private $workerStartTasks;
    private $periodTimeoutInterval = 1000;
    private $idleWorkerTimeout = 1;
    private $isPeriodWatcherEnabled = false;
    private $taskReflection;
    private $taskNotifier;
    private $nextId;
    private $now;
    private $isStarted = false;

    public function __construct() {
        $this->reactor = \Amp\reactor();
        $this->nextId = PHP_INT_MAX * -1;
        $this->workerStartTasks = new \SplObjectStorage;
        $this->taskReflection = new \ReflectionClass('Amp\Thread\Task');
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
     * @return \Amp\Promise
     */
    public function call($procedure, $varArgs = null /*..., $argN*/) {
        if (!is_string($procedure)) {
            throw new \InvalidArgumentException(
                sprintf('%s requires a string at Argument 1', __METHOD__)
            );
        }

        if (!$this->isStarted) {
            $this->start();
        }

        if ($this->maxTaskQueueSize < 0 || $this->maxTaskQueueSize > $this->outstandingTaskCount) {
            $task = $this->taskReflection->newInstanceArgs(func_get_args());
            return $this->acceptNewTask($task);
        } else {
            return new Failure(new TooBusyException(
                sprintf("Cannot execute '%s' task; too busy", $procedure)
            ));
        }
    }

    /**
     * Dispatch a pthreads Collectable to the thread pool for processing
     *
     * This method will auto-start the thread pool if workers have not been spawned.
     *
     * @param \Collectable $task A custom pthreads collectable
     * @return \Amp\Promise
     */
    public function execute(\Collectable $task) {
        if (!$this->isStarted) {
            $this->start();
        }

        if ($this->maxTaskQueueSize < 0 || $this->maxTaskQueueSize > $this->outstandingTaskCount) {
            return $this->acceptNewTask($task);
        } else {
            return new Failure(new TooBusyException(
                sprintf('Cannot execute task of type %s; too busy', get_class($task))
            ));
        }
    }

    private function acceptNewTask(\Collectable $task) {
        $promisor = new Deferred($this->reactor);
        $promiseId = $this->nextId++;
        $this->queue[$promiseId] = [$promisor, $task];
        $this->outstandingTaskCount++;

        if ($this->isPeriodWatcherEnabled === false) {
            $this->now = microtime(true);
            $this->reactor->enable($this->timeoutWatcher);
            $this->isPeriodWatcherEnabled = true;
        }

        if ($this->taskTimeout > -1) {
            $timeoutAt = $this->taskTimeout + $this->now;
            $this->promiseTimeoutMap[$promiseId] = $timeoutAt;
        }

        if ($this->availableWorkers) {
            $this->dequeueNextTask();
        } elseif (($this->poolSize + $this->pendingWorkerCount) < $this->poolSizeMax) {
            $this->spawnWorker();
        }

        return $promisor->promise();
    }

    private function dequeueNextTask() {
        $promiseId = key($this->queue);
        list($promisor, $task) = $this->queue[$promiseId];

        unset($this->queue[$promiseId]);

        $worker = array_shift($this->availableWorkers);

        $this->promiseWorkerMap[$promiseId] = $worker;

        $worker->promiseId = $promiseId;
        $worker->promisor = $promisor;
        $worker->task = $task;
        $worker->thread->stack($task);
        $worker->thread->stack($this->taskNotifier);
        $worker->lastStackedAt = $this->now;
    }

    /**
     * Spawn worker threads
     *
     * No tasks will be dispatched until Dispatcher::start is invoked.
     *
     * @return \Amp\Thread\Dispatcher Returns the current object instance
     */
    public function start() {
        if (!$this->isStarted) {
            $this->generateIpcServer();
            $this->isStarted = true;
            for ($i=0;$i<$this->poolSizeMin;$i++) {
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

        stream_set_blocking($server, false);

        $serverName = stream_socket_get_name($server, false);
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

        if (!$thread->start($this->threadStartFlags)) {
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
        $this->pendingWorkerCount++;

        return $worker;
    }

    private function acceptIpcClient($ipcServer) {
        if (!$ipcClient = @stream_socket_accept($ipcServer)) {
            throw new \RuntimeException(
                'Failed accepting IPC client'
            );
        }

        $ipcClientId = (int) $ipcClient;
        stream_set_blocking($ipcClient, false);
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
        $this->pendingWorkerCount--;
        $worker->ipcClient = $ipcClient;
        $worker->ipcReadWatcher = $this->reactor->onReadable($ipcClient, function() use ($worker) {
            $this->onReadableIpcClient($worker);
        });
        $this->workers[$workerId] = $worker;
        $this->availableWorkers[$workerId] = $worker;
        $this->poolSize++;

        foreach ($this->workerStartTasks as $task) {
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
            $this->now = microtime(true);
            $this->timeoutWatcher = $this->reactor->repeat(function() {
                $this->executePeriodTimeouts();
            }, $this->periodTimeoutInterval);
            $this->isPeriodWatcherEnabled = true;
        }
    }

    private function executePeriodTimeouts() {
        $this->now = $now = microtime(true);

        if ($this->promiseTimeoutMap) {
            $this->timeoutOverdueTasks($now);
        }

        if ($this->availableWorkers && ($this->poolSize > $this->poolSizeMin)) {
            $this->decrementSuperfluousWorkers($now);
        }
    }

    private function decrementSuperfluousWorkers($now) {
        foreach ($this->availableWorkers as $worker) {
            $idleTime = $now - $worker->lastStackedAt;
            if ($idleTime > $this->idleWorkerTimeout) {
                $this->unloadWorker($worker);
                // we don't want to unload more than one worker per second, so break; afterwards
                break;
            }
        }

        if ($this->outstandingTaskCount === 0 && $this->poolSize === $this->poolSizeMin) {
            $this->isPeriodWatcherEnabled = false;
            $this->reactor->disable($this->timeoutWatcher);
        }
    }

    private function timeoutOverdueTasks($now) {
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
            $this->outstandingTaskCount--;
            $worker = $this->promiseWorkerMap[$promiseId];
            $worker->future->fail($error);
            $this->unloadWorker($worker);
            $worker->thread->kill();
            $this->spawnWorker();
        } else {
            $this->outstandingTaskCount--;
            list($promisor) = $this->queue[$promiseId];
            $promisor->fail($error);
            unset($this->queue[$promiseId]);
        }
    }

    private function unloadWorker(Worker $worker) {
        $this->reactor->cancel($worker->ipcReadWatcher);
        $this->poolSize--;

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
        $error = $mustKill = null;

        switch ($resultCode) {
            case Thread::SUCCESS:
                // nothing to do
                break;
            case Thread::FAILURE:
                $error = new TaskException($data);
                break;
            case Thread::FATAL:
                $mustKill = true;
                $error = new TaskException($data);
                break;
            case Thread::UPDATE:
                $worker->promisor->update($data);
                return; // return here because the task is not yet complete
            default:
                $mustKill = true;
                $error = new TaskException(
                    sprintf(
                        'Unexpected worker notification code: %s',
                        ord($resultCode) ? $resultCode : 'null'
                    )
                );
        }

        $this->outstandingTaskCount--;
        $worker->tasksExecuted++;

        if ($error) {
            $worker->promisor->fail($error);
        } else {
            $worker->promisor->succeed($data);
        }

        $this->afterTaskCompletion($worker, $mustKill);
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
            $worker->promiseId = $worker->promisor = $worker->task = null;
            $this->availableWorkers[$worker->id] = $worker;
        }

        if ($this->queue && $this->availableWorkers) {
            $this->dequeueNextTask();
        }
    }

    private function shouldRespawn(Worker $worker) {
        if ($this->executionLimit <= 0) {
            return false;
        } elseif ($worker->tasksExecuted >= $this->executionLimit) {
            return true;
        } else {
            return false;
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
     * @return \Amp\Thread\Dispatcher Returns the current object instance
     */
    public function setOption($option, $value) {
        switch ($option) {
            case self::OPT_THREAD_FLAGS:
                $this->setThreadStartFlags($value); break;
            case self::OPT_POOL_SIZE_MIN:
                $this->setPoolSizeMin($value); break;
            case self::OPT_POOL_SIZE_MAX:
                $this->setPoolSizeMax($value); break;
            case self::OPT_TASK_TIMEOUT:
                $this->setTaskTimeout($value); break;
            case self::OPT_IDLE_WORKER_TIMEOUT:
                $this->setIdleWorkerTimeout($value); break;
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

    private function setIdleWorkerTimeout($seconds) {
        $this->idleWorkerTimeout = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
            'default' => 1
        ]]);
    }

    private function setThreadStartFlags($flags) {
        $this->threadStartFlags = $flags;
    }

    private function setPoolSizeMin($int) {
        $this->poolSizeMin = filter_var($int, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
            'default' => 1
        ]]);
    }

    private function setPoolSizeMax($int) {
        $this->poolSizeMax = filter_var($int, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
            'default' => 8
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
        if (!is_dir($dir) || @mkdir($dir, $permissions = 0744, $recursive = true)) {
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
    public function count() {
        return $this->outstandingTaskCount;
    }

    /**
     * Execute a Collectable task in the thread pool
     *
     * @param \Collectable $task
     * @return \Amp\Promise
     */
    public function __invoke(\Collectable $task) {
        return $this->execute($task);
    }

    /**
     * Store a worker task to execute each time a worker spawns
     *
     * @param \Collectable $task
     * @return void
     */
    public function addStartTask(\Collectable $task) {
        $this->workerStartTasks->attach($task);
    }

    /**
     * Clear a worker task currently stored for execution each time a worker spawns
     *
     * @param \Collectable $task
     * @return void
     */
    public function removeStartTask(\Collectable $task) {
        if ($this->workerStartTasks->contains($task)) {
            $this->workerStartTasks->detach($task);
        }
    }

    /**
     * Assume unknown methods are dispatches for functions in the global namespace
     *
     * @param string $method
     * @param array $args
     * @return \Amp\Promise
     */
    public function __call($method, $args) {
        array_unshift($args, $method);
        return call_user_func_array([$this, 'call'], $args);
    }

    public function __destruct() {
        $this->reactor->cancel($this->timeoutWatcher);

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
            @unlink($path);
        }
    }
}
