<?php

use Amp\MultiProcess\CallResultFactory;

class CallResultFactoryTest extends PHPUnit_Framework_TestCase {
    
    function testMakeReturnsCallResult() {
        $crf = new CallResultFactory;
        $callResult = $crf->make($callId = 1, $result = TRUE, $error = NULL);
        $this->assertInstanceOf('Amp\MultiProcess\CallResult', $callResult);
    }
}
