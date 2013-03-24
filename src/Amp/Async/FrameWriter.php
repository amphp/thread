<?php

namespace Amp\Async;

class FrameWriter {
    
    private $ouputStream;
    private $currentFrame;
    private $frameQueue = [];
    private $buffer;
    private $bufferSize;
    private $granularity = 65536;
    
    function __construct($ouputStream) {
        $this->ouputStream = $ouputStream;
    }
    
    function setGranularity($bytes) {
        $this->granularity = (int) $bytes;
    }
    
    function write(Frame $frame = NULL) {
        if ($frame) {
            $this->frameQueue[] = $frame;
        }
        
        if ($this->currentFrame) {
            $allFramesWritten = $this->doWrite();
        } elseif ($this->frameQueue) {
            $this->currentFrame = array_shift($this->frameQueue);
            $this->buffer = $this->currentFrame->getHeader() . $this->currentFrame->getPayload();
            $this->bufferSize = strlen($this->buffer);
            $allFramesWritten = $this->doWrite();
        } else {
            $allFramesWritten = TRUE;
        }
        
        return $allFramesWritten;
    }
    
    private function doWrite() {
        $bytesWritten = @fwrite($this->ouputStream, $this->buffer, $this->granularity);
        
        if ($bytesWritten === $this->bufferSize) {
            $this->buffer = NULL;
            $this->bufferSize = NULL;
            $this->currentFrame = NULL;
            $allFramesWritten = !$this->frameQueue;
        } elseif ($bytesWritten) {
            $this->buffer = substr($this->buffer, $bytesWritten);
            $this->bufferSize -= $bytesWritten;
            $allFramesWritten = FALSE;
        } elseif (is_resource($this->ouputStream)) {
            $allFramesWritten = FALSE;
        } else {
            throw new ResourceException(
                'Failed writing to ouput stream'
            );
        }
        
        return $allFramesWritten;
    }
    
}
