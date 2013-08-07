<?php

namespace Amp;

class FrameParser {
    
    const START = 0;
    const DETERMINE_LENGTH = 1;
    const DETERMINE_LENGTH_254 = 2;
    const DETERMINE_LENGTH_255 = 3;
    const PAYLOAD = 4;
    
    private $state = self::START;
    private $buffer = '';
    private $bytesRcvd = 0;
    
    private $opcode;
    private $length;
    private $payload;
    
    function bufferData($data) {
        $this->buffer .= $data;
    }
    
    function parse() {
        if (!isset($this->buffer[0])) {
            goto more_data_needed;
        }
        
        switch ($this->state) {
            case self::START:
                goto start;
            case self::DETERMINE_LENGTH:
                goto determine_length;
            case self::DETERMINE_LENGTH_254:
                goto determine_length_254;
            case self::DETERMINE_LENGTH_255:
                goto determine_length_255;
            case self::PAYLOAD:
                goto payload;
        }
        
        start: {
            $this->opcode = (int) $this->buffer[0];
            $this->state = self::DETERMINE_LENGTH;
            
            if (isset($this->buffer[1])) {
                goto determine_length;
            } else {
                goto more_data_needed;
            }
        }
        
        determine_length: {
            $length = (int) ord($this->buffer[1]);
            
            if ($length === 254) {
                $this->state = self::DETERMINE_LENGTH_254;
                goto determine_length_254;
            } elseif ($length === 255) {
                $this->state = self::DETERMINE_LENGTH_255;
                goto determine_length_255;
            } else {
                $this->length = $length;
                $this->buffer = substr($this->buffer, 2);
                $this->state = self::PAYLOAD;
                goto payload;
            }
        }
        
        determine_length_254: {
            if (isset($this->buffer[3])) {
                $lenStr = $this->buffer[2] . $this->buffer[3];
                $this->length = (int) current(unpack('n', $lenStr));
                $this->buffer = substr($this->buffer, 4);
                $this->state = self::PAYLOAD;
                goto payload;
            } else {
                goto more_data_needed;
            }
        }
        
        determine_length_255: {
            if (isset($this->buffer[5])) {
                $lenStr = substr($this->buffer, 2, 4);
                $this->length = (int) current(unpack('N', $lenStr));
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
            $frame = new Frame($this->opcode, $this->payload);
            
            $this->state = self::START;
            $this->opcode = NULL;
            $this->payload = NULL;
            $this->length = 0;
            $this->bytesRcvd = 0;
            
            return $frame;
        }
        
        more_data_needed: {
            return NULL;
        }
    }
    
}
