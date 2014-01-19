<?php

namespace Amp;

class Thread extends \Worker {

    const SUCCESS = '1';
    const FAILURE = '0';
    const FATAL = '-';
    const PARTIAL = '+';
    const FORGET = '*';

    private $sharedData;
    private $ipcUri;
    private $ipcSocket;
    private $lastTaskResultCode;

    public function __construct(SharedData $sharedData, $ipcUri) {
        $this->sharedData = $sharedData;
        $this->ipcUri = $ipcUri;
    }

    public function run() {
        if (!$ipcSocket = @stream_socket_client($this->ipcUri, $errno, $errstr, 5)) {
            throw new \RuntimeException(
                sprintf("Failed connecting to IPC server: (%d) %s", $errno, $errstr)
            );
        }

        stream_set_write_buffer($ipcSocket, 0);
        stream_socket_shutdown($ipcSocket, STREAM_SHUT_RD);

        $openMsg = $this->getThreadId() . "\n";

        if (fwrite($ipcSocket, $openMsg) !== strlen($openMsg)) {
            throw new \RuntimeException(
                "Failed writing open message to IPC server"
            );
        }

        $this->ipcSocket = $ipcSocket;
    }

    private function registerResult($resultCode, $data) {
        switch ($resultCode) {
            case self::SUCCESS: break;
            case self::FAILURE: break;
            case self::FATAL: break;
            case self::FORGET: break;
            default:
                $resultCode = self::FAILURE;
                $data = sprintf('Stackable task registered unknown result code: %s', $resultCode);
        }

        $this->lastTaskResultCode = $resultCode;
        $this->sharedData[] = $data;
    }

    private function completedPreviousTask() {
        return isset($this->lastTaskResultCode);
    }

    private function notifyDispatcher() {
        $resultCode = $this->lastTaskResultCode;
        $this->lastTaskResultCode = NULL;

        if (!fwrite($this->ipcSocket, $resultCode)) {
            throw new \RuntimeException(
                "Failed writing to IPC socket"
            );
        }
    }

}
