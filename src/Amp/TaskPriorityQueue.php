<?php

namespace Amp;

class TaskPriorityQueue extends \SplPriorityQueue {

    private $serial = PHP_INT_MAX;

    /**
     * Items with the same priority are extracted by the base SplPriorityQueue
     * in no particular order. We need this operation to maintain FIFO order for
     * tasks with the same priority.
     */
    public function insert($value, $priority) {
        parent::insert($value, [$priority, $this->serial--]);
    }

}
