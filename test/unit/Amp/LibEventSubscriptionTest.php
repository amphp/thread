<?php

use Amp\LibEventSubscription,
    Amp\LibEventReactor;

class LibEventSubscriptionTest extends PHPUnit_Framework_TestCase {
    
    private function skipIfMissingExtLibevent() {
        if (!extension_loaded('libevent')) {
            $this->markTestSkipped(
                'libevent extension not available'
            );
        }
    }
    
    function testCancelInvokesReactorCancelMethod() {
        $this->skipIfMissingExtLibevent();
        
        $reactor = $this->getMock('Amp\\LibEventReactor');
        
        $base = event_base_new();
        $event = event_new();
        $interval = 42;
        
        event_timer_set($event, function(){});
        event_base_set($event, $base);
        event_add($event, $interval);
        
        $subscription = new LibEventSubscription($reactor, $event, $interval = 42);
        
        $reactor->expects($this->once())
                ->method('cancel')
                ->with($subscription);
        
        $subscription->cancel();
    }
    
    /**
     * @expectedException RuntimeException
     */
    function testEnableThrowsExceptionOnCancelledSubscription() {
        $this->skipIfMissingExtLibevent();
        $reactor = $this->getMock('Amp\\LibEventReactor');
        
        $base = event_base_new();
        $event = event_new();
        $interval = 42;
        
        event_timer_set($event, function(){});
        event_base_set($event, $base);
        event_add($event, $interval);
        
        $subscription = new LibEventSubscription($reactor, $event, $interval);
        
        $reactor->expects($this->once())
                ->method('cancel')
                ->with($subscription);
        
        $subscription->cancel();
        $this->assertEquals(LibEventSubscription::CANCELLED, $subscription->status());
        $subscription->enable();
    }
    
    function testDisable() {
        $this->skipIfMissingExtLibevent();
        $reactor = $this->getMock('Amp\\LibEventReactor');
        
        $base = event_base_new();
        $event = event_new();
        $interval = 42;
        
        event_timer_set($event, function(){});
        event_base_set($event, $base);
        event_add($event, $interval);
        
        $subscription = new LibEventSubscription($reactor, $event, $interval);
        $subscription->disable();
        
        $this->assertEquals(LibEventSubscription::DISABLED, $subscription->status());
        
        return $subscription;
    }
    
    /**
     * @depends testDisable
     */
    function testEnable($subscription) {
        $this->skipIfMissingExtLibevent();
        $this->assertEquals(LibEventSubscription::DISABLED, $subscription->status());
        $subscription->enable();
        $this->assertEquals(LibEventSubscription::ENABLED, $subscription->status());
    }
    
}

