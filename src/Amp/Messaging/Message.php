<?php

namespace Amp\Messaging;

class Message implements \Countable {
    
    private $frames;
    private $frameCount = 0;
    private $length;
    private $payloadBuffer;
    private $payloadStream;
    
    function __construct(array $frames) {
        $this->frames = $frames;
        $this->frameCount = count($frames);
        
        $firstFrame = $frames[0];
        $this->length = $firstFrame->getLength();
        
        if ($this->frameCount == 1) {
            $this->processSingleFrameMessage($firstFrame);
        } else {
            for ($i=1; $i < $this->frameCount; $i++) {
                $this->length += $frames[$i]->getLength();
                
            }
        }
    }
    
    private function processSingleFrameMessage(Frame $firstFrame) {
        $payload = $firstFrame->getPayload();
        
        if (is_string($payload)) {
            $this->payloadBuffer = $payload;
        } else {
            $this->payloadStream = $payload;
        }
    }
    
    function count() {
        return $this->frameCount;
    }
    
    function getFrames() {
        return $this->frames;
    }
    
    function getLength() {
        return $this->length;
    }
    
    /**
     * @TODO Add error handling for stream error
     */
    function getPayload() {
        if (isset($this->payloadBuffer)) {
            return $this->payloadBuffer;
        } elseif ($this->payloadStream) {
            return $this->payloadBuffer = stream_get_contents($this->payloadStream);
        } else {
            return $this->flattenMultiFramePayload();
        }
    }
    
    /**
     * @TODO Add error handling for stream error
     */
    private function flattenMultiFramePayload() {
        foreach ($this->frames as $frame) {
            $payload = $frame->getPayload();
            if (is_string($payload)) {
                $this->payloadBuffer .= $payload;
            } else {
                $this->payloadBuffer .= stream_get_contents($payload);
            }
        }
        
        return $this->payloadBuffer;
    }
    
}

