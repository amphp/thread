<?php

namespace Amp;

interface DispatchResult {

    /**
     * Retrieve the result returned from the asynchronous task
     *
     * Implementors MUST throw the stored error exception if this method is invoked on a result
     * that did not succeed.
     *
     * @throws \Amp\DispatchException Throws if the task did not complete successfully
     * @return mixed
     */
    function getResult();

    /**
     * Manually retrieve the exception object describing the task's failure
     *
     * If the task did not fail implementors MUST return NULL
     *
     * @return DispatchException|null
     */
    function getError();

    /**
     * Did the task execute without error?
     *
     * @return bool
     */
    function succeeded();

    /**
     * Did task execution encounter an error?
     *
     * @return bool
     */
    function failed();

    /**
     * Retrieve the unique integer ID associated with this task
     *
     * @return int
     */
    function getTaskId();

}
