<?php

namespace Amp;

class Worker {
    public $id;
    public $ipcClient;
    public $ipcReadWatcher;
    public $sharedData;
    public $thread;
    public $task;
    public $taskNotifier;
    public $taskId;
    public $afterTask;
    public $tasksExecuted = 0;
}
