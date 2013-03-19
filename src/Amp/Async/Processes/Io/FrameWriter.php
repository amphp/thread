<?php

namespace Amp\Async\Processes\Io;

class FrameWriter {
    
    const START = 0;
    const HEADER = 1;
    const PAYLOAD_BUFFERED = 2;
    const PAYLOAD_STREAMING = 3;
    
    private $state = self::START;
    private $ouputStream;
    private $currentFrame;
    private $buffer;
    private $bufferSize;
    private $granularity = 8192;
    
    private $streamingPayloadResource;
    private $isStreamPayloadReadingComplete;
    
    function __construct($ouputStream) {
        $this->ouputStream = $ouputStream;
    }
    
    function setGranularity($bytes) {
        $this->granularity = (int) $bytes;
    }
    
    function write(Frame $frame = NULL) {
        if ($frame && $this->state != self::START) {
            throw new \LogicException(
                'Cannot start new frame write; previous write still in progress'
            );
        } elseif ($frame) {
            $this->currentFrame = $frame;
            goto start;
        } else {
            goto no_data_to_write;
        }
        
        switch ($this->state) {
            case self::HEADER:
                goto header;
            case self::PAYLOAD_BUFFERED:
                goto payload_buffered;
            case self::PAYLOAD_STREAMING:
                goto payload_streaming;
            default:
                throw new \UnexpectedValueException(
                    'Unexpected FrameWriter state value encountered'
                );
        }
        
        start: {
            $this->buffer = $this->currentFrame->getHeader();
            $this->bufferSize = strlen($this->buffer);
            $this->state = self::HEADER;
            
            goto header;
        }
        
        header: {
            if ($this->doWrite()) {
                goto payload_start;
            } else {
                goto further_write_needed;
            }
        }
        
        payload_start: {
            $payload = $this->currentFrame->getPayload();
            
            if (is_string($payload) || is_object($payload) && method_exists($payload, '__toString')) {
                $this->buffer = $payload;
                $this->bufferSize = strlen($payload);
                $this->state = self::PAYLOAD_BUFFERED;
                goto payload_buffered;
            } else {
                $this->streamingPayloadResource = $payload;
                $this->isStreamPayloadReadingComplete = FALSE;
                $this->state = self::PAYLOAD_STREAMING;
                goto payload_streaming;
            }
        }
        
        payload_buffered: {
            if ($this->doWrite()) {
                goto frame_complete;
            } else {
                goto further_write_needed;
            }
        }
        
        payload_streaming: {
            if (!$this->isStreamPayloadReadingComplete) {
                $this->bufferStreamingPayloadDataChunk();
            }
            
            if ($this->doWrite()) {
                $this->isStreamPayloadReadingComplete = NULL;
                $this->streamingPayloadResource = NULL;
                goto frame_complete;
            } else {
                goto further_write_needed;
            }
        }
        
        frame_complete: {
            $frame = $this->currentFrame;
            
            $this->buffer = NULL;
            $this->bufferSize = NULL;
            $this->currentFrame = NULL;
            
            $this->state = self::START;
            
            return $frame;
        }
        
        further_write_needed: {
            return FALSE;
        }
        
        no_data_to_write: {
            return TRUE;
        }
        
    }
    
    private function doWrite() {
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
                'Failed writing to ouputStream stream'
            );
        }
    }
    
    private function bufferStreamingPayloadDataChunk() {
        if (FALSE === ($chunk = @fread($this->streamingPayloadResource, $this->granularity))) {
            throw new ResourceException(
                'Failed reading from payload resource stream: ' . print_r($this->streamingPayloadResource, TRUE)
            );
        } elseif ($chunk || $chunk === '0') {
            $this->buffer .= $chunk;
            $this->bufferSize += strlen($chunk);
        } elseif (feof($this->streamingPayloadResource)) {
            $this->isStreamPayloadReadingComplete = TRUE;
        }
    }
    
}
