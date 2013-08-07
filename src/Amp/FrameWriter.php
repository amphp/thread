<?php

namespace Amp;

class FrameWriter {
    
    private $destination;
    private $frameQueue;
    private $currentFrame;
    private $buffer = '';
    private $bufferSize = 0;
    private $cachedFrameQueueCount = 0;
    
    function __construct($destination, FramePriorityQueue $queue = NULL) {
        $this->destination = $destination;
        $this->frameQueue = $queue ?: new FramePriorityQueue;
    }
    
    function enqueueFrame(Frame $frame) {
        $this->frameQueue->insert($frame);
        $this->cachedFrameQueueCount++;
    }
    
    function write() {
        if ($this->currentFrame) {
            $hasAllDataBeenWritten = $this->doWrite();
        } elseif (!$this->currentFrame && $this->cachedFrameQueueCount) {
            $nextFrame = $this->frameQueue->extract();
            $this->cachedFrameQueueCount--;
            $this->currentFrame = $nextFrame;
            $this->buffer .= $nextFrame;
            $this->bufferSize = strlen($this->buffer);
            $hasAllDataBeenWritten = $this->doWrite();
        } else {
            $hasAllDataBeenWritten = TRUE;
        }
        
        return $hasAllDataBeenWritten;
    }
    
    private function doWrite() {
        $bytesWritten = @fwrite($this->destination, $this->buffer);
        
        if ($bytesWritten === $this->bufferSize) {
            $this->buffer = '';
            $this->bufferSize = 0;
            $this->currentFrame = NULL;
            $hasAllDataBeenWritten = !$this->cachedFrameQueueCount;
        } elseif ($bytesWritten) {
            $this->buffer = substr($this->buffer, $bytesWritten);
            $this->bufferSize -= $bytesWritten;
            $hasAllDataBeenWritten = FALSE;
        } elseif (is_resource($this->destination)) {
            $hasAllDataBeenWritten = FALSE;
        } else {
            throw new ResourceException(
                'Failed writing to destination stream'
            );
        }
        
        return $hasAllDataBeenWritten;
    }
    
}
