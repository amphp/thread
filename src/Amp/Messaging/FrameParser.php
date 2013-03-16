<?php

namespace Amp\Messaging;

class FrameParser {
    
    const START = 0;
    const DETERMINE_LENGTH_254 = 5;
    const DETERMINE_LENGTH_255 = 10;
    const PAYLOAD_READ = 25;
    const PAYLOAD_SWAP_WRITE = 30;
    
    private $inputStream;
    private $state = self::START;
    private $readBuffer = '';
    
    private $fin;
    private $rsv;
    private $opcode;
    private $length;
    private $payload;
    
    private $bytesRcvd = 0;
    private $payloadSwapStream;
    private $writeBuffer;
    private $granularity = 8192;
    private $swapSize = 10485760;
    private $swapDir;
    
    function __construct($inputStream) {
        $this->inputStream = $inputStream;
    }
    
    function setGranularity($bytes) {
        $this->granularity = (int) $bytes;
    }
    
    function setSwapSize($bytes) {
        $this->swapSize = (int) $bytes;
    }
    
    function setSwapDir($dir) {
        $this->swapDir = $dir;
    }

    function parse() {
        $data = fread($this->inputStream, $this->granularity);
        $emptyData = !($data || $data === '0');
        
        if ($emptyData && (!is_resource($this->inputStream) || feof($this->inputStream))) {
            throw new ResourceException(
                'Input stream has gone away'
            );
        } elseif ($emptyData) {
            goto more_data_needed;
        }
        
        $this->readBuffer .= $data;
        
        switch ($this->state) {
            case self::START:
                goto start;
            case self::DETERMINE_LENGTH_254:
                goto determine_length_254;
            case self::DETERMINE_LENGTH_255:
                goto determine_length_255;
            case self::PAYLOAD_READ:
                goto payload_read;
            case self::PAYLOAD_SWAP_WRITE:
                goto payload_swap_write;
            default:
                throw new \UnexpectedValueException(
                    'Unexpected frame parsing state'
                );
        }
        
        start: {
            if (!isset($this->readBuffer[1])) {
                goto more_data_needed;
            }
            
            $firstByte = ord($this->readBuffer[0]);
            
            $this->fin = (bool) ($firstByte & 0b10000000);
            $this->rsv = ($firstByte & 0b01110000) >> 4;
            $this->opcode = $firstByte & 0b00001111;
            $this->length = ord($this->readBuffer[1]);
            
            if ($this->length == 254) {
                $this->state = self::DETERMINE_LENGTH_254;
                goto determine_length_254;
            } elseif ($this->length == 255) {
                $this->state = self::DETERMINE_LENGTH_255;
                goto determine_length_255;
            } else {
                $this->readBuffer = substr($this->readBuffer, 2);
                $this->state = self::PAYLOAD_READ;
                
                goto payload_read;
            }
        }
        
        determine_length_254: {
            if (isset($this->readBuffer[3])) {
                $this->length = current(unpack('n', $this->readBuffer[2] . $this->readBuffer[3]));
                $this->readBuffer = substr($this->readBuffer, 4);
                $this->state = self::PAYLOAD_READ;
                goto payload_read;
            } else {
                goto more_data_needed;
            }
        }
        
        determine_length_255: {
            if (isset($this->readBuffer[5])) {
                $this->length = current(substr($this->readBuffer, 2, 4));
                $this->readBuffer = substr($this->readBuffer, 6);
                $this->state = self::PAYLOAD_READ;
                goto payload_read;
            } else {
                goto more_data_needed;
            }
        }
        
        payload_read: {
            if (!$this->length) {
                goto frame_complete;
            }
            
            $bytesRemaining = $this->length - $this->bytesRcvd;
            
            if (isset($this->readBuffer[$bytesRemaining - 1])) {
                $this->bytesRcvd += $bytesRemaining;
                
                if (isset($this->readBuffer[$bytesRemaining])) {
                    $payloadChunk = substr($this->readBuffer, 0, $bytesRemaining);
                    $this->readBuffer = substr($this->readBuffer, $bytesRemaining);
                } else {
                    $payloadChunk = $this->readBuffer;
                    $this->readBuffer = '';
                }
                
                if ($this->addPayloadChunk($payloadChunk)) {
                    goto frame_complete;
                } else {
                    $this->state = self::PAYLOAD_SWAP_WRITE;
                    goto further_write_needed;
                }
            } else {
                $this->bytesRcvd += strlen($this->readBuffer);
                $this->addPayloadChunk($this->readBuffer);
                $this->readBuffer = '';
                goto more_data_needed;
            }
        }
        
        payload_swap_write: {
            if ($this->writePayloadToSwapStream()) {
                goto frame_complete;
            } else {
                goto further_write_needed;
            }
        }
        
        frame_complete: {
            $payload = $this->payloadSwapStream ?: $this->payload;
            $frame = new Frame($this->fin, $this->rsv, $this->opcode, $payload, $this->length);
            
            $this->state = self::START;
            
            $this->fin = NULL;
            $this->rsv = NULL;
            $this->opcode = NULL;
            $this->payload = NULL;
            $this->length = 0;
            $this->bytesRcvd = 0;
            $this->writeBuffer = NULL;
            $this->payloadSwapStream = NULL;
            
            return $frame;
        }
        
        more_data_needed: {
            return NULL;
        }
        
        further_write_needed: {
            return NULL;
        }
    }
    
    private function addPayloadChunk($data) {
        $swapNeeded = ($this->bytesRcvd >= $this->swapSize);
        
        if ($swapNeeded && !$this->payloadSwapStream) {
            $swapDir = $this->swapDir ?: sys_get_temp_dir();
            $uri = tempnam($swapDir, 'amp');
            
            if (FALSE === ($this->payloadSwapStream = @fopen($uri, 'wb+'))) {
                throw new \RuntimeException(
                    'Failed opening temporary frame storage resource'
                );
            }
            
            stream_set_blocking($this->payloadSwapStream, FALSE);
        }
        
        if ($swapNeeded) {
            return $this->writePayloadToSwapStream($data);
        } else {
            $this->payload .= $data;
            return TRUE;
        }
    }
    
    private function writePayloadToSwapStream($data = NULL) {
        if (NULL !== $data) {
            $this->writeBuffer .= $data;
        }
        
        $bytesWritten = @fwrite($this->payloadSwapStream, $this->writeBuffer);
        
        if ($bytesWritten == strlen($this->writeBuffer)) {
            return TRUE;
        } elseif ($bytesWritten) {
            $this->writeBuffer = substr($this->writeBuffer, $bytesWritten);
            return FALSE;
        } elseif (!is_resource($this->payloadSwapStream)) {
            throw new \RuntimeException(
                'Temporary frame storage resource has gone away'
            );
        }
    }
    
}

