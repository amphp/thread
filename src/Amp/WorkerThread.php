<?php

namespace Amp;

class WorkerThread extends \Worker {

    public $sharedData;
    public $ipcUri;
    public $ipcSocket;
    public $lastCallSucceeded;

    function __construct(SharedData $sharedData, $ipcUri, $bootstrapPath = NULL) {
        $this->sharedData = $sharedData;
        $this->ipcUri = $ipcUri;

        if ($bootstrapPath) {
            require_once $bootstrapPath;
        }
    }

    function run() {
        if (!$this->ipcSocket = @stream_socket_client($this->ipcUri, $errno, $errstr, 5)) {
            throw new \RuntimeException(
                sprintf("Failed connecting to IPC socket: (%d) %s", $errno, $errstr)
            );
        }
    }
}
