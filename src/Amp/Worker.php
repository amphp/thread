<?php

namespace Amp;

class Worker {
    
    public $id;
    public $process;
    public $parser;
    public $writer;
    public $readWatcher;
    public $writeWatcher;
    public $outstandingCalls = [];
    
}
