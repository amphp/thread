<?php

namespace Amp;

class Slave extends \Worker {

    const SUCCESS = '1';
    const FAILURE = '0';
    const FATAL = '-';

    private $sharedData;
    private $ipcUri;
    private $ipcSocket;
    private $lastTaskResultCode;
    private $bootstrapPaths;

    public function __construct(SharedData $sharedData, $ipcUri, array $bootstrapPaths = []) {
        $this->sharedData = $sharedData;
        $this->ipcUri = $ipcUri;
        $this->bootstrapPaths = $bootstrapPaths;
        /*
        if (func_num_args() > 3) {
            $userArgs = array_slice(func_get_args(), 3);
        }
        */
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

        if ($this->bootstrapPaths) {
            foreach ($this->bootstrapPaths as $path) {
                require_once $path;
            }
        }
    }

    private function registerResult($resultCode, $data) {
        switch ($resultCode) {
            case self::SUCCESS: break;
            case self::FAILURE: break;
            case self::FATAL: break;
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
