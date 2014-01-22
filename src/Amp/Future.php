<?php

namespace Amp;

/**
 * Future is a "placeholder" for a value that will be fulfilled at a later time.
 */
class Future {

    private $result;
    private $error;
    private $onComplete;
    private $onSuccess;
    private $onFailure;
    private $isResolved;

    /**
     * Pass this Future to the specified callback upon resolution
     *
     * @param callable $onComplete
     * @return Future Returns the current object instance
     */
    public function onComplete(callable $onComplete) {
        if ($this->isResolved) {
            call_user_func($onComplete, $this);
        } else {
            $this->onComplete = $onComplete;
        }

        return $this;
    }

    /**
     * Pass the Future's eventual value to this continuation callback upon successful fulfillment
     *
     * @param callable $onSuccess
     * @return Future Returns the current object instance
     */
    public function onSuccess(callable $onSuccess) {
        if (!$this->isResolved) {
            $this->onSuccess = $onSuccess;
        } elseif (!$this->error) {
            call_user_func($onSuccess, $this->result);
        }

        return $this;
    }

    /**
     * Pass the relevant exception to the specified callback if value resolution fails
     *
     * @param callable $onFailure
     * @return Future Returns the current object instance
     */
    public function onFailure(callable $onFailure) {
        if (!$this->isResolved) {
            $this->onFailure = $onFailure;
        } elseif ($this->error) {
            call_user_func($onFailure($this->error));
        }

        return $this;
    }

    /**
     * Has the Future been resolved (either succeeded or failed)?
     *
     * @return bool
     */
    public function isResolved() {
        return $this->isResolved;
    }

    /**
     * Is the Future still pending?
     *
     * @return bool
     */
    public function isPending() {
        return !$this->isResolved;
    }

    /**
     * Has the Future value been successfully resolved?
     *
     * @throws \LogicException If the Future is still pending
     * @return bool
     */
    public function succeeded() {
        if ($this->isResolved) {
            return !$this->error;
        } else {
            throw new \LogicException(
                'Cannot retrieve state from unresolved Future'
            );
        }
    }

    /**
     * Has the Future failed?
     *
     * @throws \LogicException If the Future is still pending
     * @return bool
     */
    public function failed() {
        if ($this->isResolved) {
            return (bool) $this->error;
        } else {
            throw new \LogicException(
                'Cannot retrieve state from unresolved Future'
            );
        }
    }

    /**
     * Retrieve the value that successfully fulfilled the Future
     *
     * @throws \LogicException If the Future is still pending
     * @throws \Exception If the Future failed the exception that caused the failure is thrown
     * @return mixed
     */
    public function value() {
        if (!$this->isResolved) {
            throw new \LogicException(
                'Cannot retrieve value from unresolved Future'
            );
        } elseif ($this->error) {
            throw $this->error;
        } else {
            return $this->result;
        }
    }

    /**
     * Retrieve the exception instance that resulted in the Future's failure
     *
     * @throws \LogicException If the Future succeeded or is still pending
     * @return mixed
     */
    public function error() {
        if (!$this->isResolved) {
            throw new \LogicException(
                'Cannot retrieve error from unresolved Future'
            );
        } elseif ($this->error) {
            return $this->error;
        } else {
            throw new \LogicException(
                'Cannot retrieve error from successfully resolved Future'
            );
        }
    }

    private function resolve(\Exception $error = NULL, $result = NULL) {
        return $error ? $this->fail($error) : $this->succeed($result);
    }

    private function fail(\Exception $error) {
        if ($this->isResolved) {
            throw new \LogicException(
                'Cannot fail Future: already resolved'
            );
        }

        $this->isResolved = TRUE;
        $this->error = $error;

        if ($this->onFailure) {
            call_user_func($this->onFailure, $error);
        } elseif ($this->onComplete) {
            call_user_func($this->onComplete, $this);
        }
    }

    private function succeed($result) {
        if ($this->isResolved) {
            throw new \LogicException(
                'Cannot fulfill future: already resolved'
            );
        } elseif ($result instanceof Future) {
            $result->onSuccess(function($result) {
                $this->succeed($result);
            });
            $result->onFailure(function(\Exception $error) {
                $this->fail($error);
            });
        } else {
            $this->resolveSuccess($result);
        }
    }

    private function resolveSuccess($result) {
        $this->isResolved = TRUE;
        $this->result = $result;

        if ($this->onSuccess) {
            call_user_func($this->onSuccess($result));
        } elseif ($this->onComplete) {
            call_user_func($this->onComplete, $this);
        }
    }

}
