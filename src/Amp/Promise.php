<?php

namespace Amp;

/**
 * Promises are used internally by the producers of asynchronously-generated results.
 *
 * Example:
 *
 * class Producer {
 *     public function retrieveValueAsynchronously() {
 *         // Create a new promise that needs to be fulfilled
 *         $promise = new Promise;
 *
 *         $future = $promise->future();
 *
 *         // When we finished non-blocking value retrieval we
 *         // simply call the relevant Promise method depending
 *         // on whether or not retrieval succeeded:
 *         //
 *         // $promise->succeed($result)
 *         // $promise->fail($error)
 *
 *         return $future;
 *     }
 * }
 */
class Promise {

    private $result;
    private $error;
    private $future;
    private $isResolved = FALSE;
    private $futureResolver;

    public function __construct() {
        $futureResolver = function(\Exception $error = NULL, $result = NULL) {
            $this->resolve($error, $result);
        };
        $future = new Future;
        $this->futureResolver = $futureResolver->bindTo($future, $future);
        $this->future = $future;
    }

    /**
     * Retrieve the Future value associated with this Promise
     *
     * @return \Amp\Future
     */
    public function future() {
        return $this->future;
    }

    /**
     * Fulfill the Promise's associated Future with either an error or result
     *
     * @param \Exception $error
     * @param mixed $result
     * @return void
     */
    public function fulfill(\Exception $error = NULL, $result = NULL) {
        $this->isResolved = TRUE;
        $futureResolver = $this->futureResolver;
        $futureResolver($error, $result);
    }

    /**
     * Fulfill the associated Future but only if it has not already been resolved
     *
     * @param \Exception $error
     * @param mixed $result
     * @return bool Returns TRUE if the Future was fulfilled by this operation; FALSE if already resolved
     */
    public function fulfillSafely(\Exception $error = NULL, $result = NULL) {
        if ($this->future->isPending()) {
            $this->fulfill($error, $result);
            $couldResolve = TRUE;
        } else {
            $couldResolve = FALSE;
        }

        return $couldResolve;
    }

    /**
     * Fail the Promise's associated Future
     *
     * @param \Exception $error
     * @return void
     */
    public function fail(\Exception $error) {
        $this->isResolved = TRUE;
        $futureResolver = $this->futureResolver;
        $futureResolver($error, $result = NULL);
    }

    /**
     * Fufill the Promise's Future value with a successful result
     *
     * @param mixed $result
     * @return void
     */
    public function succeed($result) {
        $this->isResolved = TRUE;
        $futureResolver = $this->futureResolver;
        $futureResolver($error = NULL, $result);
    }

}
