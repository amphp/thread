<?php

use Amp\Dispatch\CallResultFactory;

class CallResultFactoryTest extends PHPUnit_Framework_TestCase {
    
    function testMakeReturnsCallResult() {
        $crf = new CallResultFactory;
        $callResult = $crf->make($callId = 1, $result = TRUE, $error = NULL);
        $this->assertInstanceOf('Amp\Dispatch\CallResult', $callResult);
    }
}
