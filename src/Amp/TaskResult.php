<?php

namespace Amp;

class TaskResult implements DispatchResult {

    private $taskId;
    private $result;
    private $error;

    function __construct($taskId, $result = NULL, \Exception $error = NULL) {
        $this->taskId = $taskId;
        $this->result = $result;
        $this->error = $error;
    }

    /**
     * Retrieve the result returned from the asynchronous task
     *
     * Implementors MUST throw the stored error exception if this method is invoked on a result
     * that did not succeed.
     *
     * @throws \Amp\DispatchException Throws if the task did not complete successfully
     * @return mixed
     */
    function getResult() {
        if ($this->error) {
            throw new DispatchException(
                $msg = 'Dispatch failure',
                $code = 0,
                $this->error
            );
        }

        return $this->result;
    }

    /**
     * Manually retrieve the exception object describing the task's failure
     *
     * If the task did not fail implementors MUST return NULL
     *
     * @return DispatchException|null
     */
    function getError() {
        return $this->error;
    }

    /**
     * Retrieve the unique integer ID associated with this task
     *
     * @return int
     */
    function getTaskId() {
        return $this->taskId;
    }

    /**
     * Did the task execute without error?
     *
     * @return bool
     */
    function succeeded() {
        return empty($this->error);
    }

    /**
     * Did task execution encounter an error?
     *
     * @return bool
     */
    function failed() {
        return (bool) $this->error;
    }

}
