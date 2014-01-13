<?php

namespace Amp;

class CallNotifier extends \Stackable {

    const FAILURE = '0';
    const SUCCESS = '1';

    public function run() {
        if ($this->worker->lastCallSucceeded === TRUE) {
            $notificationCode = self::SUCCESS;
        } else {
            $notificationCode = self::FAILURE;
            $this->worker->lastCallSucceeded = FALSE;
            $this->worker->sharedData[] = $this->generateFatalErrorMessage();
        }

        $this->worker->lastCallSucceeded = NULL;

        fwrite($this->worker->ipcSocket, $notificationCode, 1);
        fflush($this->worker->ipcSocket);
    }

    public function generateFatalErrorMessage() {
        $error = error_get_last();
        return sprintf("%s in %s on line %d", $error['message'], $error['file'], $error['line']);
    }

}
