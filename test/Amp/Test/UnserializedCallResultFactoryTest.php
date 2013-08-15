<?php

use Amp\Test;

class UnserializedCallResultFactoryTest extends \PHPUnit_Framework_TestCase {
    
    function testMakeReturnsCallResult() {
        $crf = new \Amp\UnserializedCallResultFactory();
        $callResult = $crf->make($callId = 1, $result = TRUE, $error = NULL);
        $this->assertInstanceOf('Amp\CallResult', $callResult);
    }
}
