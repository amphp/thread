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
        
        $reactor->once($delay = 0, function() use (&$testIncrement) {
            $testIncrement++;
        });
        $reactor->tick();
        
        $this->assertEquals(1, $testIncrement);
    }
    
    function testRunExecutesEventsUntilExplicitlyStopped() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibEventReactor;
        
        $testIncrement = 0;
        
        $reactor->repeat($delay = 0.001, function() use (&$testIncrement, $reactor) {
            if ($testIncrement < 10) {
                $testIncrement++;
            } else {
                $reactor->stop();
            }
        });
        $reactor->run();
        
        $this->assertEquals(10, $testIncrement);
    }
    
    function testOnceReturnsEventSubscription() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibEventReactor;
        
        $subscription = $reactor->once($delay = 0, function(){});
        
        $this->assertInstanceOf('Amp\\LibEventSubscription', $subscription);
    }
    
    function testReactorDoesntSwallowOnceCallbackException() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibEventReactor;
        
        $reactor->repeat($delay = 1, function(){});
        $reactor->once($delay = 0, function(){ throw new Exception('test'); });
        
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
        
        $subscription = $reactor->repeat($interval = 1, function(){});
        
        $this->assertInstanceOf('Amp\\LibEventSubscription', $subscription);
    }
    
    function testReactorDoesntSwallowRepeatCallbackException() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibEventReactor;
        
        $reactor->repeat($interval = 0.001, function(){ throw new Exception('test'); });
        
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
        
        $subscription = $reactor->once($delay = 0.001, function(){
            $this->fail('Subscription was not cancelled as expected');
        });
        
        $reactor->once($delay = 0, function() use ($subscription) { $subscription->cancel(); });
        $reactor->once($delay = 0.002, function() use ($reactor) { $reactor->stop(); });
        $reactor->run();
    }
    
    function testRepeatCancelsSubscriptionAfterSpecifiedNumberOfIterations() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibEventReactor;
        
        $counter = 0;
        
        $reactor->repeat($delay = 0, function() use (&$counter) { ++$counter; }, $iterations = 3);
        $reactor->once($delay = 0.005, function() use ($reactor, $counter) { $reactor->stop(); });
        
        $reactor->run();
        $this->assertEquals(3, $counter);
    }
    
}

