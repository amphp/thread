<?php

use Amp\Test;

class CallResultTest extends \PHPUnit_Framework_TestCase {
    
    function testGetCallId() {
        $callId = pack('N', 55555555);
        $cr = new Amp\CallResult($callId, $result = 'test', $error = NULL);
        $this->assertEquals($callId, $cr->getCallId());
    }
    
    function testGetResult() {
        $callId = pack('N', 55555555);
        $cr = new Amp\CallResult($callId, $result = 'test', $error = NULL);
        $this->assertEquals('test', $cr->getResult());
    }
    
    /**
     * @expectedException Exception
     */
    function testGetResultThrowsExceptionIfGetResultCalledOnErrorResult() {
        $callId = pack('N', 55555555);
        $cr = new Amp\CallResult($callId, $result = NULL, $error = new Exception);
        $cr->getResult();
    }
    
    function testGetError() {
        $callId = pack('N', 55555555);
        $cr = new Amp\CallResult($callId, $result = NULL, $error = new Exception);
        $this->assertEquals($error, $cr->getError());
    }
    
    function testIsSuccess() {
        $callId = pack('N', 55555555);
        $cr = new Amp\CallResult($callId, $result = 'test', $error = NULL);
        $this->assertEquals(TRUE, $cr->isSuccess());
    }
    
    function testIsError() {
        $callId = pack('N', 55555555);
        $cr = new Amp\CallResult($callId, $result = 'test', $error = NULL);
        $this->assertEquals(FALSE, $cr->isError());
    }
    
}

