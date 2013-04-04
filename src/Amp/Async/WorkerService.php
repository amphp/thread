<?php

namespace Amp\Async;

class WorkerService {
    
    private $parser;
    private $writer;
    private $chrCallCodeResult;
    private $chrCallCodeError;
    
    function __construct(FrameParser $parser, FrameWriter $writer) {
        $this->parser = $parser;
        $this->writer = $writer;
        
        $this->chrCallCodeResult = chr(Dispatcher::CALL_RESULT);
        $this->chrCallCodeError  = chr(Dispatcher::CALL_ERROR);
        
        $parser->throwOnEof(FALSE);
    }
    
    function onReadable() {
        while ($frameArr = $this->parser->parse()) {
            $payload = $frameArr[3];
            
            $callId = substr($payload, 0, 4);
            
            // @TODO MAYBE ? Validate call code == Dispatcher::CALL
            //$callCode = ord($payload[4]);
            assert(ord($payload[4]) == Dispatcher::CALL);
            
            $procLen = ord($payload[5]);
            $procedure = substr($payload, 6, $procLen);
            $workload = substr($payload, $procLen + 6);
            
            try {
                $this->invokeProcedure($callId, $procedure, $workload);
            } catch (ResourceException $e) {
                throw $e;
            } catch (\Exception $e) {
                $payload = $callId . $this->chrCallCodeError . $e->__toString();
                $frame = new Frame($fin = 1, $rsv = 0, Frame::OP_DATA, $payload);
                $this->writer->writeAll($frame);
            }
        }
    }
    
    private function invokeProcedure($callId, $procedure, $workload) {
        $result = $procedure($workload);
        
        if ($result instanceof \Iterator) {
            $this->streamResult($callId, $result);
        } elseif ($result === NULL || is_scalar($result)) {
            $payload = $callId . $this->chrCallCodeResult . $result;
            $frame = new Frame($fin = 1, $rsv = 0, Frame::OP_DATA, $payload);
            $this->writer->writeAll($frame);
        } else {
            throw new \UnexpectedValueException(
                'Invalid procedure return type: NULL, scalar or Iterator expected'
            );
        }
    }
    
    private function streamResult($callId, \Iterator $result) {
        while (TRUE) {
            $chunk = $result->current();
            $result->next();
            $isFin = (int) !$result->valid();
            
            if (isset($chunk[0])) {
                $chunk = $callId . $this->chrCallCodeResult . $chunk;
                $frame = new Frame($isFin, $rsv = 0, Frame::OP_DATA, $chunk);
                $this->writer->writeAll($frame);
            }
            
            if ($isFin) {
                break;
            }
        }
    }
    
}

