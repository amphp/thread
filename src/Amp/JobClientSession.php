<?php

namespace Amp;

class JobClientSession {

    public $id;
    public $name;
    public $socket;
    public $parser;
    public $writer;
    public $readWatcher;
    public $writeWatcher;
    public $msgBuffer = '';
    public $clientCallMap = [];
    public $internalCallMap = [];

}
