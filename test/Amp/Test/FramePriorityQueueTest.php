<?php

namespace Amp\Test;

use Amp\Frame, Amp\FramePriorityQueue;

class FramePriorityQueueTest extends \PHPUnit_Framework_TestCase {

    function testConstruction() {
        $queue = new FramePriorityQueue;
        $this->assertInstanceof('Amp\FramePriorityQueue', $queue);
    }

    function testExtractReturnsControlFramesBeforeDataFrames() {
        $controlFrame = new Frame(Frame::OP_CLOSE, 'test');
        $dataFrame1 = new Frame(Frame::OP_DATA_MORE, 'test');
        $dataFrame2 = new Frame(Frame::OP_DATA_FIN, 'test');

        $queue = new FramePriorityQueue;
        $queue->insert($dataFrame1);
        $queue->insert($controlFrame);
        $queue->insert($dataFrame2);

        $frame = $queue->extract();
        $this->assertSame($frame, $controlFrame);

        $frame = $queue->extract();
        $this->assertSame($frame, $dataFrame1);

        $frame = $queue->extract();
        $this->assertSame($frame, $dataFrame2);
    }

    function testCount() {
        $queue = new FramePriorityQueue;
        $this->assertSame(0, $queue->count());

        $frame = new Frame(Frame::OP_DATA_FIN, 'test');
        $queue->insert($frame);
        $this->assertSame(1, $queue->count());

        $queue->extract();
        $this->assertSame(0, $queue->count());
    }

    function testSerialMaxReset() {
        $max = 1;
        $queue = new FramePriorityQueue($max);

        $frame = new Frame(Frame::OP_DATA_FIN, 'test');
        $queue->insert($frame);
        $queue->extract();
    }

}
