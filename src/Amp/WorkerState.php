<?php

namespace Amp;

class WorkerState {
    public $id;
    public $localSock;
    public $threadSock;
    public $sharedData;
    public $thread;
    public $ipcWatcher;
    public $call;
    public $callId;
    public $afterCall;
}
