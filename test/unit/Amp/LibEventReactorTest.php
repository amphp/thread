<?php

use Amp\LibEventReactor;

class LibEventReactorTest extends PHPUnit_Framework_TestCase {
    
    private function skipIfMissingExtLibevent() {
        if (!extension_loaded('libevent')) {
            $this->markTestSkipped(
                'libevent extension not available'
            );
        }
    }
    
    function testTickExecutesReadyEvents() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibEventReactor;
        
        $testIncrement = 0;
        
        $reactor->once(function() use (&$testIncrement) {
            $testIncrement++;
        });
        $reactor->tick();
        
        $this->assertEquals(1, $testIncrement);
    }
    
    function testRunExecutesEventsUntilExplicitlyStopped() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibEventReactor;
        
        $testIncrement = 0;
        
        $reactor->repeat(function() use (&$testIncrement, $reactor) {
            if ($testIncrement < 10) {
                $testIncrement++;
            } else {
                $reactor->stop();
            }
        }, $delay = 0.001);
        $reactor->run();
        
        $this->assertEquals(10, $testIncrement);
    }
    
    function testOnceReturnsEventSubscription() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibEventReactor;
        
        $subscription = $reactor->once(function(){});
        
        $this->assertInstanceOf('Amp\\LibEventSubscription', $subscription);
    }
    
    function testReactorDoesntSwallowOnceCallbackException() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibEventReactor;
        
        $reactor->repeat(function(){}, $delay = 1);
        $reactor->once(function(){ throw new Exception('test'); });
        
        try {
            $reactor->tick();
            $this->fail('Expected exception not thrown');
        } catch (Exception $e) {
            // woot! this is what we wanted
        }
    }
    
    function testRepeatReturnsEventSubscription() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibEventReactor;
        
        $subscription = $reactor->repeat(function(){}, $interval = 1);
        
        $this->assertInstanceOf('Amp\\LibEventSubscription', $subscription);
    }
    
    function testReactorDoesntSwallowRepeatCallbackException() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibEventReactor;
        
        $reactor->repeat(function(){ throw new Exception('test'); });
        
        try {
            $reactor->run();
            $this->fail('Expected exception not thrown');
        } catch (Exception $e) {
            // woot! this is what we wanted
        }
    }
    
    function testCancelRemovesSubscription() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibEventReactor;
        
        $subscription = $reactor->once(function(){
            $this->fail('Subscription was not cancelled as expected');
        }, $delay = 0.001);
        
        $reactor->once(function() use ($subscription) { $subscription->cancel(); });
        $reactor->once(function() use ($reactor) { $reactor->stop(); }, $delay = 0.002);
        $reactor->run();
    }
    
    function testRepeatCancelsSubscriptionAfterSpecifiedNumberOfIterations() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibEventReactor;
        
        $counter = 0;
        
        $reactor->repeat(function() use (&$counter) { ++$counter; }, $delay = 0, $iterations = 3);
        $reactor->once(function() use ($reactor, $counter) { $reactor->stop(); }, $delay = 0.005);
        
        $reactor->run();
        $this->assertEquals(3, $counter);
    }
    
}

