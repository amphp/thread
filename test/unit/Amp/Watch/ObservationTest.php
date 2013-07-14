<?php

use Amp\Watch\Observation;

class ObservationTest extends PHPUnit_Framework_TestCase {
    
    /**
     * @expectedException InvalidArgumentException
     */
    function testConstructorThrowsOnEmptyCallbackArray() {
        $observable = $this->getMock('Amp\Watch\Observable');
        $observation = new Observation($observable, $callbacks = []);
    }
    
    /**
     * @expectedException InvalidArgumentException
     */
    function testConstructorThrowsOnUncallableListener() {
        $observable = $this->getMock('Amp\Watch\Observable');
        $observation = new Observation($observable, $callbacks = [
            'event' => new StdClass
        ]);
    }
    
    function testInvokeNotifiesObservationListener() {
        $mutable = 99;
        $observable = $this->getMock('Amp\Watch\Observable');
        $observation = new Observation($observable, $callbacks = [
            'event' => function($val) use (&$mutable) { $mutable = $val; }
        ]);
        
        $observation('event', 42);
        
        $this->assertEquals(42, $mutable);
    }
    
    function testDisablePreventsEventObservation() {
        $mutable = 99;
        $observable = $this->getMock('Amp\Watch\Observable');
        $observation = new Observation($observable, $callbacks = [
            'event' => function($val) use (&$mutable) { $mutable = $val; }
        ]);
        $observation->disable();
        $observation('event', 42);
        
        $this->assertEquals(99, $mutable);
        
        $observation->enable();
        $observation('event', 42);
        
        $this->assertEquals(42, $mutable);
    }
    
    function testCancelPreventsEventObservation() {
        $mutable = 99;
        $observable = $this->getMock('Amp\Watch\Observable');
        $observation = new Observation($observable, $callbacks = [
            'event' => function($val) use (&$mutable) { $mutable = $val; }
        ]);
        $observation->cancel();
        $observation('event', 42);
        $this->assertEquals(99, $mutable);
    }
    
    function testModifyChangesCallbacks() {
        $mutable = 99;
        $observable = $this->getMock('Amp\Watch\Observable');
        $observation = new Observation($observable, $callbacks = [
            'event' => function($val) use (&$mutable) { $mutable = $val; }
        ]);
        
        $observation('event', 42);
        $this->assertEquals(42, $mutable);
        
        $observation->modify([
            'event' => function() use (&$mutable) { $mutable = 1; }
        ]);
        
        $observation('event', 42);
        $this->assertEquals(1, $mutable);
    }
    
    function testReplaceChangesCallbacks() {
        $mutable = 99;
        $observable = $this->getMock('Amp\Watch\Observable');
        $observation = new Observation($observable, $callbacks = [
            'event' => function($val) use (&$mutable) { $mutable = $val; }
        ]);
        
        $observation('event', 42);
        $this->assertEquals(42, $mutable);
        
        $observation->replace([
            'event' => function() use (&$mutable) { $mutable = 1; }
        ]);
        
        $observation('event', 42);
        $this->assertEquals(1, $mutable);
    }
    
}
