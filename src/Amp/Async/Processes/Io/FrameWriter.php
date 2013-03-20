<?php

namespace Amp\Async\Processes\Io;

class FrameWriter {
    
    const START = 0;
    const HEADER = 1;
    const PAYLOAD = 2;
    
    protected $state = self::START;
    protected $ouputStream;
    protected $currentFrame;
    protected $frameQueue = [];
    protected $buffer;
    protected $bufferSize;
    protected $granularity = 8192;
    
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
        
        switch ($this->state) {
            case self::START:
                goto start;
            case self::HEADER:
                goto header;
            case self::PAYLOAD:
                goto payload;
        }
        
        start: {
            $this->currentFrame = array_shift($this->frameQueue);
            $this->buffer = $this->currentFrame->getHeader();
            $this->bufferSize = strlen($this->buffer);
            $this->state = self::HEADER;
            
            goto header;
        }
        
        header: {
            if ($this->doWrite()) {
                $this->buffer = $this->currentFrame->getPayload();
                $this->bufferSize = $this->currentFrame->getLength();
                $this->state = self::PAYLOAD;
                goto payload;
            } else {
                goto further_write_needed;
            }
        }
        
        payload: {
            if ($this->doWrite()) {
                goto frame_complete;
            } else {
                goto further_write_needed;
            }
        }
        
        frame_complete: {
            $this->buffer = NULL;
            $this->bufferSize = NULL;
            $this->currentFrame = NULL;
            
            $this->state = self::START;
            
            if ($this->frameQueue) {
                goto start;
            } else {
                return TRUE;
            }
        }
        
        further_write_needed: {
            return FALSE;
        }
        
    }
    
    protected function doWrite() {
        $bytesWritten = @fwrite($this->ouputStream, $this->buffer, $this->granularity);
        
        if ($bytesWritten === $this->bufferSize) {
            return TRUE;
        } elseif ($bytesWritten) {
            $this->buffer = substr($this->buffer, $bytesWritten);
            $this->bufferSize -= $bytesWritten;
            return FALSE;
        } elseif (is_resource($this->ouputStream)) {
            return FALSE;
        } else {
            throw new ResourceException(
                'Failed writing to ouput stream'
            );
        }
    }
    
}
