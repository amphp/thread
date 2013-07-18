<?php

namespace Amp\Dispatch;

class FrameWriter {
    
    private $outputStream;
    private $buffer = '';
    private $bufferSize = 0;
    private $granularity = 65536;
    
    function __construct($outputStream) {
        $this->outputStream = $outputStream;
    }
    
    function setGranularity($bytes) {
        $this->granularity = (int) $bytes;
    }
    
    function write($data = NULL) {
        if ($data !== NULL) {
            $this->buffer .= $data;
            $this->bufferSize = strlen($this->buffer);
        }
        
        return $this->bufferSize ? $this->doWrite() : TRUE;
    }
    
    function writeAll($data = NULL) {
        if ($data !== NULL) {
            $this->buffer .= $data;
            $this->bufferSize = strlen($this->buffer);
        }
        
        if ($this->bufferSize) {
            while (!$this->doWrite()) {
                $this->doWrite();
            }
        }
    }
    
    private function doWrite() {
        $allDataWritten = FALSE;
        $bytesWritten = @fwrite($this->outputStream, $this->buffer, $this->granularity);
        
        if ($bytesWritten === $this->bufferSize) {
            $this->buffer = '';
            $this->bufferSize = 0;
            $allDataWritten = TRUE;
        } elseif ($bytesWritten) {
            $this->buffer = substr($this->buffer, $bytesWritten);
            $this->bufferSize -= $bytesWritten;
        } elseif (!is_resource($this->outputStream)) {
            throw new ResourceException(
                'Failed writing to output stream'
            );
        }
        
        return $allDataWritten;
    }
    
}

