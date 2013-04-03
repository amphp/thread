<?php

namespace Amp\Async;

class WorkerService {
    
    private $parser;
    private $writer;
    
    function __construct(FrameParser $parser, FrameWriter $writer) {
        $this->parser = $parser;
        $this->writer = $writer;
        
        $parser->throwOnEof(FALSE);
    }
    
    function onReadable() {
        while ($frameArr = $this->parser->parse()) {
            $payload = $frameArr[3];
            
            $callId = substr($payload, 0, 4);
            $procLen = ord($payload[4]);
            $procedure = substr($payload, 5, $procLen);
            $workload = substr($payload, $procLen + 5);
            
            try {
                $this->invokeProcedure($callId, $procedure, $workload);
            } catch (ResourceException $e) {
                throw $e;
            } catch (\Exception $e) {
                $payload = $callId . $e->__toString();
                $frame = new Frame($fin = 1, Dispatcher::CALL_ERROR, Frame::OP_DATA, $payload);
                $this->writer->writeAll($frame);
            }
        }
    }
    
    private function invokeProcedure($callId, $procedure, $workload) {
        $result = $procedure($workload);
        
        if ($result instanceof \Iterator) {
            $this->streamResult($callId, $result);
        } elseif ($result === NULL || is_scalar($result)) {
            $payload = $callId . $result;
            $frame = new Frame($fin = 1, Dispatcher::CALL_RESULT, Frame::OP_DATA, $payload);
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
                $chunk = $callId . $chunk;
                $frame = new Frame($isFin, Dispatcher::CALL_RESULT, Frame::OP_DATA, $chunk);
                $this->writer->writeAll($frame);
            }
            
            if ($isFin) {
                break;
            }
        }
    }
    
}

