<?php

namespace Amp;

class Worker {
    public $id;
    public $ipcClient;
    public $ipcReadWatcher;
    public $results;
    public $resultCodes;
    public $thread;
    public $task;
    public $promise;
    public $future;
    public $stream;
    public $streamInjector;
    public $tasksExecuted = 0;
}
