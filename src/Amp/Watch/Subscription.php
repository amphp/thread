<?php

namespace Amp\Watch;

class Subscription {
    
    const ENABLED = 1;
    const DISABLED = 0;
    const CANCELLED = -1;
    
    private $enabler;
    private $status = self::ENABLED;
    
    function __construct(\Closure $enabler) {
        $this->enabler = $enabler;
    }
    
    /**
     * Enable the previously-disabled subscription
     * 
     * If the subscription is already enabled this method has no effect.
     * 
     * @throws \RuntimeException If the subscription was previously cancelled
     * @return void
     */
    function enable() {
        if ($this->status === self::DISABLED) {
            $enabler = $this->enabler;
            $enabler($this, self::ENABLED);
            $this->status = self::ENABLED;
        } elseif ($this->status === self::CANCELLED) {
            throw new \RuntimeException(
                'Cannot reenable a subscription after cancellation'
            );
        }
    }
    
    /**
     * Temporarily disable this subscription
     * 
     * @return void
     */
    function disable() {
        if ($this->status === self::ENABLED) {
            $enabler = $this->enabler;
            $enabler($this, self::DISABLED);
            $this->status = self::DISABLED;
        }
    }
    
    /**
     * Permanently cancel this subscription
     * 
     * Once a subscription is cancelled it cannot be reenabled.
     * 
     * @return void
     */
    function cancel() {
        if ($this->status !== self::CANCELLED) {
            $enabler = $this->enabler;
            $enabler($this, self::CANCELLED);
            $this->status = self::CANCELLED;
        }
    }
    
    /**
     * Is the subscription currently enabled?
     * 
     * @return bool
     */
    function isEnabled() {
        return ($this->status === self::ENABLED);
    }
    
    /**
     * Is the subscription currently disabled?
     * 
     * @return bool
     */
    function isDisabled() {
        return ($this->status === self::DISABLED);
    }
    
    /**
     * Has the subscription been cancelled?
     * 
     * @return bool
     */
    function isCancelled() {
        return ($this->status === self::CANCELLED);
    }

}
