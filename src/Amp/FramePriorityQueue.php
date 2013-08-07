<?php

namespace Amp;

class FramePriorityQueue implements \Countable {

    private $priorityQueue;
    private $serial;
    private $serialMax;

    final function __construct($serialMax = PHP_INT_MAX) {
        $this->serialMax = (int) $serialMax;
        $this->serial = $this->serialMax;
        $this->priorityQueue = new \SplPriorityQueue;
    }

    function insert(Frame $frame) {
        $priority = (int) ($frame->getOpcode() > Frame::OP_DATA_FIN);
        if (!($serial = --$this->serial)) {
            $serial = $this->serial = $this->serialMax;
        }
        $this->priorityQueue->insert($frame, [$priority, $serial]);
    }

    function extract() {
        return $this->priorityQueue->extract();
    }

    function count() {
        return $this->priorityQueue->count();
    }

}
