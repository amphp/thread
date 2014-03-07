<?php

namespace Amp;

use Alert\Promise, Alert\Future;

class FutureStream implements \Iterator {
    private $futures = [];
    private $promise;
    private $position = 0;
    private $isValid = TRUE;

    public function __construct() {
        $this->promise = new Promise;
        $this->futures[] = $this->promise->getFuture();
    }

    private function fulfillLastPromise($isFinal, \Exception $error = NULL, $result = NULL) {
        if (!$isFinal) {
            $newPromise = new Promise;
            $this->futures[] = $newPromise->getFuture();
        }

        if ($this->promise->resolveSafely($error, $result)) {
            $this->promise = $isFinal ? NULL : $newPromise;
        } else {
            $this->isValid = FALSE;
        }
    }

    /**
     * Retrieve the stream's current Future
     *
     * @return \Alert\Future
     */
    public function current() {
        if ($this->futures) {
            return current($this->futures);
        } else {
            throw new \OutOfRangeException(
                sprintf('Illegal index access at position %d in %s', $this->position, __METHOD__)
            );
        }
    }

    /**
     * The key doesn't have any meaning for our purposes but it's here if you want it
     *
     * @return int
     */
    public function key() {
        return $this->position;
    }

    /**
     * Advance the stream pointer
     *
     * @throws \LogicException if the stream's current Future is still pending
     */
    public function next() {
        $current = current($this->futures);

        if ($current && $current instanceof Future && !$current->isComplete()) {
            throw new \LogicException(sprintf(
                'Cannot advance FutureStream at index %d; Future value still pending',
                $this->position
            ));
        } else {
            array_shift($this->futures);
        }

        $this->position++;
    }

    /**
     * Is there still future data remaining in the stream?
     *
     * @return bool
     */
    public function valid() {
        return $this->isValid && $this->futures;
    }

    /**
     * Do nothing -- we don't want to retain streamed future data in-memory.
     * If applications wish to store this data they may do so when each individual
     * future completes.
     */
    public function rewind() {}
}
