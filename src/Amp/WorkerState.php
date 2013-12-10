<?php

namespace Amp;

class WorkerState {
    public $id;
    public $localSock;
    public $threadSock;
    public $sharedData;
    public $thread;
    public $ipcWatcher;
    public $currentTask;
    public $onTaskCompletion;
}
