<?php

namespace Amp;

class JobServerSession {

    public $uri;
    public $socket;
    public $onResolution;
    public $connectWatcher;
    public $readWatcher;
    public $writeWatcher;
    public $pendingCalls = [];
    public $parser;
    public $writer;

}
