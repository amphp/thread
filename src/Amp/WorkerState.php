<?php

namespace Amp;

class WorkerState {
    public $id;
    public $ipcServer;
    public $ipcAcceptWatcher;
    public $ipcClient;
    public $ipcReadWatcher;
    public $sharedData;
    public $thread;
    public $call;
    public $callNotifier;
    public $callId;
    public $afterCall;
}
