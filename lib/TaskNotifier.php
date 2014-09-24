<?php

namespace Amp\Thread;

class TaskNotifier extends \Stackable {

    public function run() {
        if (!$this->worker->completedPreviousTask()) {
            $this->registerErrorResult();
        }

        $this->worker->notifyDispatcher();
    }

    private function registerErrorResult() {
        $fatals = [
            E_ERROR,
            E_PARSE,
            E_USER_ERROR,
            E_CORE_ERROR,
            E_CORE_WARNING,
            E_COMPILE_ERROR,
            E_COMPILE_WARNING
        ];

        $error = error_get_last();

        if ($error && in_array($error['type'], $fatals)) {
            $resultCode = Thread::FATAL;
            $data = sprintf("%s in %s on line %d", $error['message'], $error['file'], $error['line']);
        } else {
            $resultCode = Thread::FAILURE;
            $data = "Stackable tasks MUST register results with the worker thread";
        }

        $this->worker->resolve($resultCode, $data);
    }

}
