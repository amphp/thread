<?php

use Amp\Watch\NativeReactor;

class NativeReactorTest extends PHPUnit_Framework_TestCase {
    
    function testImmediateExecution() {
        $reactor = new NativeReactor;
        
        $testIncrement = 0;
        
        $reactor->immediately(function() use (&$testIncrement) {
            $testIncrement++;
        });
        $reactor->tick();
        
        $this->assertEquals(1, $testIncrement);
    }
    
    function testSubscriptionTimeoutDoesNotAffectSubscriptionsThatDontHaveTimeouts() {
        $reactor = new NativeReactor;
        
        $stream = STDIN;
        $timeoutIncrement = 0;
        $readableIncrement = 0;
        
        $reactor->onReadable($stream, function($stream, $trigger) use (&$readableIncrement) {
            $readableIncrement++;
            fwrite(STDERR, "we shouldn't ever be invoked!\n");
        });
        
        $reactor->onReadable($stream, function($stream, $trigger) use ($reactor, &$timeoutIncrement) {
            if ($trigger === NativeReactor::TIMEOUT) {
                if ($timeoutIncrement++ >=3) {
                    $reactor->stop();
                }
            }
        }, $timeout = 0.001);
        
        $reactor->run();
        
        $this->assertEquals(0, $readableIncrement);
    }
    
    function testSubscriptionIsNeverNotifiedIfStreamIsNeverReadable() {
        $reactor = new NativeReactor;
        $stream = STDIN;
        $increment = 0;
        
        $reactor->onReadable($stream, function($stream) use (&$increment) {
            $increment++;
        });
        
        $reactor->once(function() use ($reactor) {
            $reactor->stop();
        }, $delay = 0.01);
        
        $reactor->run();
        
        $this->assertEquals(0, $increment);
    }
    
    function testTickExecutesReadyEvents() {
        $reactor = new NativeReactor;
        
        $testIncrement = 0;
        
        $reactor->once(function() use (&$testIncrement) {
            $testIncrement++;
        });
        $reactor->tick();
        
        $this->assertEquals(1, $testIncrement);
    }
    
    function testRunExecutesEventsUntilExplicitlyStopped() {
        $reactor = new NativeReactor;
        
        $testIncrement = 0;
        
        $reactor->schedule(function() use (&$testIncrement, $reactor) {
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
        $reactor = new NativeReactor;
        
        $subscription = $reactor->once(function(){});
        
        $this->assertInstanceOf('Amp\Watch\Subscription', $subscription);
    }
    
    function testReactorDoesntSwallowOnceCallbackException() {
        $reactor = new NativeReactor;
        
        $reactor->schedule(function(){}, $delay = 1);
        $reactor->once(function(){ throw new Exception('test'); });
        
        try {
            $reactor->tick();
            $this->fail('Expected exception not thrown');
        } catch (Exception $e) {
            // woot! this is what we wanted
        }
    }
    
    function testRepeatReturnsEventSubscription() {
        $reactor = new NativeReactor;
        
        $subscription = $reactor->schedule(function(){}, $interval = 1);
        
        $this->assertInstanceOf('Amp\Watch\Subscription', $subscription);
    }
    
    function testReactorDoesntSwallowRepeatCallbackException() {
        $reactor = new NativeReactor;
        
        $reactor->schedule(function(){ throw new Exception('test'); });
        
        try {
            $reactor->run();
            $this->fail('Expected exception not thrown');
        } catch (Exception $e) {
            // woot! this is what we wanted
        }
    }
    
    function testCancelRemovesSubscription() {
        $reactor = new NativeReactor;
        
        $subscription = $reactor->once(function(){
            $this->fail('Subscription was not cancelled as expected');
        }, $delay = 0.001);
        
        $reactor->once(function() use ($subscription) { $subscription->cancel(); });
        $reactor->once(function() use ($reactor) { $reactor->stop(); }, $delay = 0.002);
        $reactor->run();
    }
    
    function testRepeatCancelsSubscriptionAfterSpecifiedNumberOfIterations() {
        $reactor = new NativeReactor;
        
        $counter = 0;
        
        $reactor->schedule(function() use (&$counter) { ++$counter; }, $delay = 0, $iterations = 3);
        $reactor->once(function() use ($reactor, $counter) { $reactor->stop(); }, $delay = 0.005);
        
        $reactor->run();
        $this->assertEquals(3, $counter);
    }
    
    function testOnWritableSubscription() {
        $reactor = new NativeReactor;
        
        $flag = FALSE;
        
        $reactor->onWritable(STDOUT, function() use ($reactor, &$flag) {
            $flag = TRUE;
            $reactor->stop();
        });
        $reactor->once(function() use ($reactor) { $reactor->stop(); }, 0.05);
        
        $reactor->run();
        $this->assertTrue($flag);
    }
    
    function testGarbageCollection() {
        $reactor = new NativeReactor();
        $reactor->once(function() use ($reactor) { $reactor->stop(); }, 0.8);
        $reactor->run();
    }
    
}
