<?php

namespace Amp;

class Subscription {
    
    const DISABLED = 0;
    const ENABLED = 1;
    const CANCELLED = -1;
    
    private $reactor;
    private $status = self::ENABLED;
    
    function __construct(Reactor $reactor) {
        $this->reactor = $reactor;
    }
    
    function enable() {
        if ($this->status === self::DISABLED) {
            $this->reactor->enable($this);
            $this->status = self::ENABLED;
        } elseif ($this->status == self::CANCELLED) {
            throw new \RuntimeException(
                'Cannot reenable a subscription after cancellation'
            );
        }
    }
    
    function disable() {
        if ($this->status === self::ENABLED) {
            $this->reactor->disable($this);
            $this->status = self::DISABLED;
        }
    }
    
    function cancel() {
        if ($this->status !== self::CANCELLED) {
            $this->reactor->cancel($this);
            $this->status = self::CANCELLED;
        }
    }
    
}

