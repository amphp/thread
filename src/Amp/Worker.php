<?php

namespace Amp;

class Worker {
    public $id;
    public $ipcClient;
    public $ipcReadWatcher;
    public $sharedData;
    public $slave;
    public $task;
    public $taskNotifier;
    public $taskId;
    public $afterTask;
    public $tasksExecuted = 0;
    public $sqid;
    public $sqidQueue;
    public $sqidQueueSize;
}
