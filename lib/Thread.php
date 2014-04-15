<?php

namespace Amp;

class Thread extends \Worker {

    const SUCCESS = '$';
    const FAILURE = '!';
    const FATAL = 'x';
    const STREAM_START = '[';
    const STREAM_DATA = '=';
    const STREAM_END = ']';

    private $results;
    private $resultCodes;
    private $ipcUri;
    private $ipcSocket;

    public function __construct(SharedData $results, SharedData $resultCodes, $ipcUri) {
        $this->results = $results;
        $this->resultCodes = $resultCodes;
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

        if (@fwrite($ipcSocket, $openMsg) !== strlen($openMsg)) {
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
            case self::STREAM_START: break;
            case self::STREAM_DATA: break;
            case self::STREAM_END: break;
            default:
                $data = sprintf('Unknown task result code: %s', $resultCode);
                $resultCode = self::FATAL;
        }

        $this->results[] = $data;
        $this->resultCodes[] = $resultCode;
    }

    private function completedPreviousTask() {
        return ($resultCount = count($this->results)) && $resultCount === count($this->resultCodes);
    }

    private function notifyDispatcher() {
        while (!@fwrite($this->ipcSocket, '.')) {
            if (!is_resource($this->ipcSocket)) {
                // Our IPC socket has died somehow ... all we can do now is exit.
                exit;
            }
        }
    }
}
