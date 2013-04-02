<?php

namespace Amp\Async;

class FrameParser {
    
    const START = 0;
    const DETERMINE_LENGTH_254 = 1;
    const DETERMINE_LENGTH_255 = 2;
    const PAYLOAD = 3;
    
    private $state = self::START;
    private $inputStream;
    private $buffer = '';
    private $bytesRcvd = 0;
    
    private $fin;
    private $rsv;
    private $opcode;
    private $length;
    private $payload;
    
    private $granularity = 65536;
    private $throwOnEof = TRUE;
    
    function __construct($inputStream) {
        $this->inputStream = $inputStream;
    }
    
    function setGranularity($bytes) {
        $this->granularity = (int) $bytes;
    }
    
    function throwOnEof($boolFlag) {
        $this->throwOnEof = (bool) $boolFlag;
    }
    
    function parse() {
        $this->buffer .= @fread($this->inputStream, $this->granularity);
        
        if ($this->buffer || $this->buffer === '0') {
            switch ($this->state) {
                case self::START:
                    goto start;
                case self::DETERMINE_LENGTH_254:
                    goto determine_length_254;
                case self::DETERMINE_LENGTH_255:
                    goto determine_length_255;
                case self::PAYLOAD:
                    goto payload;
            }
        } elseif (!is_resource($this->inputStream)
            || ($this->throwOnEof && feof($this->inputStream))
        ) {
            throw new ResourceException(
                'Failed reading from input stream'
            );
        } else {
            goto more_data_needed;
        }
        
        start: {
            if (!isset($this->buffer[1])) {
                goto more_data_needed;
            }
            
            $firstByte = ord($this->buffer[0]);
            
            $this->fin = (bool) ($firstByte & 0b10000000);
            $this->rsv = ($firstByte & 0b01110000) >> 4;
            $this->opcode = $firstByte & 0b00001111;
            $this->length = ord($this->buffer[1]);
            
            if ($this->length == 254) {
                $this->state = self::DETERMINE_LENGTH_254;
                goto determine_length_254;
            } elseif ($this->length == 255) {
                $this->state = self::DETERMINE_LENGTH_255;
                goto determine_length_255;
            } else {
                $this->buffer = substr($this->buffer, 2);
                $this->state = self::PAYLOAD;
                
                goto payload;
            }
        }
        
        determine_length_254: {
            if (isset($this->buffer[3])) {
                $this->length = current(unpack('n', $this->buffer[2] . $this->buffer[3]));
                $this->buffer = substr($this->buffer, 4);
                $this->state = self::PAYLOAD;
                goto payload;
            } else {
                goto more_data_needed;
            }
        }
        
        determine_length_255: {
            if (isset($this->buffer[5])) {
                $this->length = current(unpack('N', substr($this->buffer, 2, 4)));
                $this->buffer = substr($this->buffer, 6);
                $this->state = self::PAYLOAD;
                goto payload;
            } else {
                goto more_data_needed;
            }
        }
        
        payload: {
            if (!$this->length) {
                goto frame_complete;
            }
            
            $bytesRemaining = $this->length - $this->bytesRcvd;
            
            if (!isset($this->buffer[$bytesRemaining - 1])) {
                $this->bytesRcvd += strlen($this->buffer);
                $this->payload .= $this->buffer;
                $this->buffer = '';
                goto more_data_needed;
            } elseif (isset($this->buffer[$bytesRemaining])) {
                $this->payload .= substr($this->buffer, 0, $bytesRemaining);
                $this->buffer = substr($this->buffer, $bytesRemaining);
                goto frame_complete;
            } else {
                $this->payload .= $this->buffer;
                $this->buffer = '';
                goto frame_complete;
            }
        }
        
        frame_complete: {
            $frameArr = [$this->fin, $this->rsv, $this->opcode, $this->payload, $this->length];
            
            $this->state = self::START;
            
            $this->fin = NULL;
            $this->rsv = NULL;
            $this->opcode = NULL;
            $this->payload = NULL;
            $this->length = 0;
            $this->bytesRcvd = 0;
            
            return $frameArr;
        }
        
        more_data_needed: {
            return NULL;
        }
    }
    
}

