<?php

namespace Amp\Dispatch;

class WorkerService {
    
    private $parser;
    private $writer;
    private $chrResultCode;
    private $chrErrorCode;
    
    function __construct(FrameParser $parser, FrameWriter $writer) {
        $this->parser = $parser;
        $this->writer = $writer;
        $this->chrResultCode = chr(BinaryDispatcher::CALL_RESULT);
        $this->chrErrorCode  = chr(BinaryDispatcher::CALL_ERROR);
        
        $parser->throwOnEof(FALSE);
    }
    
    function onReadable() {
        while ($frameArr = $this->parser->parse()) {
            $payload = $frameArr[3];
            $callId = substr($payload, 0, 4);
            $procLen = ord($payload[5]);
            $procedure = substr($payload, 6, $procLen);
            $workload = unserialize(substr($payload, $procLen + 6));
            
            try {
                if (is_callable($procedure)) {
                    $this->invokeProcedure($callId, $procedure, $workload);
                } else {
                    throw new \BadFunctionCallException(
                        'Function does not exist: ' . $procedure
                    );
                }
            } catch (ResourceException $e) {
                throw $e;
            } catch (\Exception $e) {
                $payload = $callId . $this->chrErrorCode . $e->__toString();
                $frame = new Frame($fin = 1, $rsv = 0, Frame::OP_DATA, $payload);
                $this->writer->writeAll($frame);
            }
        }
    }
    
    private function invokeProcedure($callId, $procedure, $workload) {
        $result = call_user_func_array($procedure, $workload);
        $payload = $callId . $this->chrResultCode . serialize($result);
        $frame = new Frame($fin = 1, $rsv = 0, Frame::OP_DATA, $payload);
        $this->writer->writeAll($frame);
    }
    
}

