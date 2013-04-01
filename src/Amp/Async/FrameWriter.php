<?php

namespace Amp\Async;

class FrameWriter {
    
    private $ouputStream;
    private $buffer = '';
    private $bufferSize = 0;
    private $granularity = 65536;
    
    function __construct($ouputStream) {
        $this->ouputStream = $ouputStream;
    }
    
    function setGranularity($bytes) {
        $this->granularity = (int) $bytes;
    }
    
    function write($data = NULL) {
        if ($data !== NULL) {
            $this->buffer .= $data;
            $this->bufferSize = strlen($this->buffer);
        }
        
        return ($this->bufferSize === '') ? TRUE : $this->doWrite();
    }
    
    private function doWrite() {
        $bytesWritten = @fwrite($this->ouputStream, $this->buffer, $this->granularity);
        
        if ($bytesWritten === $this->bufferSize) {
            $this->buffer = '';
            $this->bufferSize = 0;
            $allDataWritten = TRUE;
        } elseif ($bytesWritten) {
            $this->buffer = substr($this->buffer, $bytesWritten);
            $this->bufferSize -= $bytesWritten;
            $allDataWritten = FALSE;
        } elseif (is_resource($this->ouputStream)) {
            $allDataWritten = FALSE;
        } else {
            throw new ResourceException(
                'Failed writing to ouput stream'
            );
        }
        
        return $allDataWritten;
    }
    
}

