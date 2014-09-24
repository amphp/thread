<?php

namespace Amp\Thread;

class Worker {
    public $id;
    public $ipcClient;
    public $ipcReadWatcher;
    public $results;
    public $resultCodes;
    public $thread;
    public $task;
    public $promiseId;
    public $future;
    public $tasksExecuted = 0;
    public $lastStackedAt;
}
