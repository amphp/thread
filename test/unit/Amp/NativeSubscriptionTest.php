<?php

use Amp\NativeSubscription,
    Amp\NativeReactor;

class NativeSubscriptionTest extends PHPUnit_Framework_TestCase {
    
    function testEnableAllowsExecution() {
        $counter = 0;
        
        $reactor = new NativeReactor;
        $subscription = $reactor->once(function() use (&$counter) {
            $counter++;
        }, $delay = 0.05);
        
        $reactor->once(function() use ($subscription) {
            $subscription->disable();
        }, $delay = 0);
        
        $reactor->once(function() use ($subscription) {
            $subscription->enable();
        }, $delay = 0.03);
        
        $reactor->once(function() use ($reactor) {
            $reactor->stop();
        }, $delay = 0.07);
        
        $reactor->run();
        
        $this->assertSame($counter, 0);
    }
    
    /**
     * @expectedException RuntimeException
     */
    function testEnableThrowsIfAlreadyCancelled() {
        $reactor = new NativeReactor;
        $subscription = $reactor->once(function(){});
        $subscription->cancel();
        $subscription->enable();
    }
    
    function testDisablePreventsExecution() {
        $counter = 0;
        
        $reactor = new NativeReactor;
        $subscription = $reactor->once(function() use (&$counter) {
            $counter++;
        }, $delay = 0.05);
        
        $reactor->once(function() use ($subscription) {
            $subscription->disable();
        }, $delay = 0);
        
        $reactor->once(function() use ($reactor) {
            $reactor->stop();
        }, $delay = 0.075);
        
        $reactor->run();
        
        $this->assertSame($counter, 0);
    }
    
    function testCancelPreventsExecution() {
        $counter = 0;
        
        $reactor = new NativeReactor;
        $subscription = $reactor->once(function() use (&$counter) {
            $counter++;
        }, $delay = 0.05);
        
        $reactor->once(function() use ($subscription) {
            $subscription->cancel();
        }, $delay = 0);
        
        $reactor->once(function() use ($reactor) {
            $reactor->stop();
        }, $delay = 0.075);
        
        $reactor->run();
        
        $this->assertSame($counter, 0);
    }
    
}

