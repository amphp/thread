<?php

namespace Amp;

class FutureStream implements \Iterator {

    private $data = [];
    private $promise;
    private $position = 0;
    private $isValid = TRUE;

    function __construct() {
        $this->promise = new Promise;
        $this->data[] = $this->promise->future();
    }

    private function fulfillLastPromise($isFinal, \Exception $error = NULL, $result = NULL) {
        if (!$isFinal) {
            $newPromise = new Promise;
            $this->data[] = $newPromise->future();
        }
        if ($this->promise->fulfillSafely($error, $result)) {
            $this->promise = $isFinal ? NULL : $newPromise;
        } else {
            $this->isValid = FALSE;
        }
    }

    function current() {
        if ($this->data) {
            return current($this->data);
        } else {
            throw new \OutOfRangeException(
                sprintf('Illegal index access at position %d in %s', $this->position, __METHOD__)
            );
        }
    }

    /**
     * The key doesn't really mean anything for our purposes but it's here if you want it
     */
    function key() {
        return $this->position;
    }

    function next() {
        $current = current($this->data);
        if ($current && $current instanceof Future && $current->isPending()) {
            throw new \LogicException(
                sprintf('Cannot advance stream at index %d; Future still pending', $this->position)
            );
        } else {
            array_shift($this->data);
        }

        $this->position++;
    }

    function valid() {
        return $this->isValid && $this->data;
    }

    /**
     * Do nothing -- we don't want to retain streamed future data in-memory.
     * If applications wish to store this data they may do it as each individual
     * future resolves.
     */
    function rewind() {}

}
