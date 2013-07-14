<?php

use Amp\Watch\Subject,
    Amp\Watch\Observable;

class SubjectTest extends PHPUnit_Framework_TestCase {
    
    function testAddObserver() {
        $mutable = 99;
        
        $subject = new SubjectTestImplementation;
        $observation = $subject->addObserver([
            'event' => function($val) use (&$mutable) { $mutable = $val; }
        ]);
        
        $subject->doNotify(42);
        $this->assertEquals(42, $mutable);
    }
    
    function testRemoveObserver() {
        $mutable = 99;
        
        $subject = new SubjectTestImplementation;
        $observation = $subject->addObserver([
            'event' => function($val) use (&$mutable) { $mutable = $val; }
        ]);
        
        $subject->removeObserver($observation);
        
        $subject->doNotify(42);
        
        $this->assertEquals(99, $mutable);
    }
    
    function testRemoveAllObservers() {
        $mutable = 99;
        
        $subject = new SubjectTestImplementation;
        $observation = $subject->addObserver([
            'event' => function($val) use (&$mutable) { $mutable = $val; }
        ]);
        
        $subject->removeAllObservers();
        
        $subject->doNotify(42);
        
        $this->assertEquals(99, $mutable);
    }
    
}

class SubjectTestImplementation implements Observable {
    
    use Subject;
    
    function doNotify($data) {
        $this->notify('event', $data);
    }
}
