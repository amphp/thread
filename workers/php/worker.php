<?php

use Amp\Call, Amp\Frame;

require dirname(dirname(__DIR__)) . '/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
date_default_timezone_set(ini_get('date.timezone') ?: 'UTC');

if (!empty($argv[1])) {
    include($argv[1]);
}

define('IO_READ_GRANULARITY', 65355);
define('PF_START', 0);
define('PF_DETERMINE_LENGTH', 1);
define('PF_DETERMINE_LENGTH_254', 2);
define('PF_DETERMINE_LENGTH_255', 3);
define('PF_PAYLOAD', 4);

stream_set_blocking(STDIN, FALSE);
stream_set_blocking(STDOUT, FALSE);

$message = '';
$frameStruct = [];
$writeBuffer = '';
$parseBuffer = '';
$parseState = PF_START;
$parseStruct = [
    'opcode' => NULL,
    'length' => NULL,
    'payload'=> NULL,
    'payloadBytesRcvd' => 0
];

ob_start();

while (TRUE) {

    select: {
        $r = [STDIN];
        $w = isset($writeBuffer[0]) ? [STDOUT] : NULL;
        $e = NULL;

        if (!stream_select($r, $w, $e, 42)) {
            goto select;
        } elseif ($r) {
            goto read;
        } else {
            goto write;
        }
    }

    read: {
        $data = @fread(STDIN, IO_READ_GRANULARITY);

        if ($data || $data === '0') {
            $parseBuffer .= $data;
            goto parse_frame;
        } elseif (!is_resource(STDIN)) {
            die;
        } elseif ($w) {
            goto write;
        } else {
            goto select;
        }
    }

    parse_frame: {
        switch ($parseState) {
            case PF_START:
                goto pf_start;
            case PF_DETERMINE_LENGTH:
                goto pf_determine_length;
            case PF_DETERMINE_LENGTH_254:
                goto pf_determine_length_254;
            case PF_DETERMINE_LENGTH_255:
                goto pf_determine_length_255;
            case PF_PAYLOAD:
                goto pf_payload;
        }
    }

    pf_start: {
        $parseStruct['opcode'] = (int) $parseBuffer[0];
        $parseState = PF_DETERMINE_LENGTH;

        if (isset($parseBuffer[1])) {
            goto pf_determine_length;
        } else {
            goto pf_more_data_needed;
        }
    }

    pf_determine_length: {
        $length = (int) ord($parseBuffer[1]);

        if ($length === 254) {
            $parseState = PF_DETERMINE_LENGTH_254;
            goto pf_determine_length_254;
        } elseif ($length === 255) {
            $parseState = PF_DETERMINE_LENGTH_255;
            goto pf_determine_length_255;
        } else {
            $parseStruct['length'] = $length;
            $parseBuffer = substr($parseBuffer, 2);
            $parseState = PF_PAYLOAD;
            goto pf_payload;
        }
    }

    pf_determine_length_254: {
        if (isset($parseBuffer[3])) {
            $lenStr = $parseBuffer[2] . $parseBuffer[3];
            $parseStruct['length'] = (int) current(unpack('n', $lenStr));
            $parseBuffer = substr($parseBuffer, 4);
            $parseState = PF_PAYLOAD;
            goto pf_payload;
        } else {
            goto pf_more_data_needed;
        }
    }

    pf_determine_length_255: {
        if (isset($parseBuffer[5])) {
            $lenStr = substr($parseBuffer, 2, 4);
            $parseStruct['length'] = (int) current(unpack('N', $lenStr));
            $parseBuffer = substr($parseBuffer, 6);
            $parseState = PF_PAYLOAD;
            goto pf_payload;
        } else {
            goto pf_more_data_needed;
        }
    }

    pf_payload: {
        if (!$parseStruct['length']) {
            goto frame_read_complete;
        }

        $bytesRemaining = $parseStruct['length'] - $parseStruct['payloadBytesRcvd'];

        if (!isset($parseBuffer[$bytesRemaining - 1])) {
            $parseStruct['payloadBytesRcvd'] += strlen($parseBuffer);
            $parseStruct['payload'] .= $parseBuffer;
            $parseBuffer = '';
            goto pf_more_data_needed;
        } elseif (isset($parseBuffer[$bytesRemaining])) {
            $parseStruct['payload'] .= substr($parseBuffer, 0, $bytesRemaining);
            $parseBuffer = substr($parseBuffer, $bytesRemaining);
            goto frame_read_complete;
        } else {
            $parseStruct['payload'] .= $parseBuffer;
            $parseBuffer = '';
            goto frame_read_complete;
        }
    }

    pf_more_data_needed: {
        if ($w) {
            goto write;
        } else {
            goto select;
        }
    }

    frame_read_complete: {
        $parseState = PF_START;
        $frameStruct = [$parseStruct['opcode'], $parseStruct['payload']];
        $parseStruct = [
            'opcode' => NULL,
            'length' => NULL,
            'payload'=> NULL,
            'payloadBytesRcvd' => 0
        ];

        goto receive_data_frame;
    }

    receive_data_frame: {
        switch ($frameStruct[0]) {
            case Frame::OP_DATA_MORE:
                $message .= $frameStruct[1];
                goto pf_more_data_needed;
                break;
            case Frame::OP_DATA_FIN:
                $message .= $frameStruct[1];
                goto process_input_message;
                break;
            default:
                throw new \UnexpectedValueException(
                    "Unexpected frame opcode: {$frameStruct[0]}"
                );
        }
    }

    process_input_message: {
        $callId = substr($message, 0, 4);
        $callCode = (int) $message[4];

        if ($callCode !== Call::REQUEST) {
            throw new \UnexpectedValueException(
                "Unexpected call code: {$callCode}"
            );
        }

        $procedureLen = ord($message[5]);
        $procedure = substr($message, 6, $procedureLen);
        $workload = substr($message, $procedureLen + 6);
        $workload = ($workload === FALSE) ? [] : unserialize($workload);

        $message = '';
        $frameStruct = [];

        goto invoke_procedure;
    }

    invoke_procedure: {
        try {
            if (is_callable($procedure)) {
                $result = call_user_func_array($procedure, $workload);
                $outboundFrame = $callId . Call::RESULT . serialize($result);
            } else {
                $result = "Function does not exist: {$procedure}";
                $outboundFrame = $callId . Call::RESULT_ERROR . $result;
            }
        } catch (Exception $e) {
            $outboundFrame = $callId . Call::RESULT_ERROR . $e;
        }
        
        if ($output = ob_get_contents()) {
            $buffer = "\n--- Buffered invocation output [{$procedure}()] ---\n\n";
            $buffer.= $output;
            $buffer.= "\n--- End buffered output ---\n";
            ob_clean();
            fwrite(STDERR, $buffer);
        }

        goto buffer_outbound_frame_for_write;
    }

    buffer_outbound_frame_for_write: {
        $outboundFrameLen = strlen($outboundFrame);

        if ($outboundFrameLen > 0xFFFF) {
            $secondByte = 0xFF;
            $lengthBody = pack('N', $this->length);
        } elseif ($outboundFrameLen < 0xFE) {
            $secondByte = $outboundFrameLen;
            $lengthBody = '';
        } else {
            $secondByte = 0xFE;
            $lengthBody = pack('n', $outboundFrameLen);
        }

        $writeBuffer .= Frame::OP_DATA_FIN . chr($secondByte) . $lengthBody . $outboundFrame;

        goto write;
    }

    write: {
        $bytesToWrite = strlen($writeBuffer);
        $bytesWritten = @fwrite(STDOUT, $writeBuffer);

        if ($bytesWritten === $bytesToWrite) {
            $writeBuffer = '';
        } elseif ($bytesWritten) {
            $writeBuffer = substr($writeBuffer, $bytesWritten);
        } elseif (!is_resource($this->destination)) {
            throw new ResourceException(
                'Failed writing to destination stream'
            );
        }
        
        if (isset($parseBuffer[0])) {
            goto parse_frame;
        } else {
            goto select;
        }
    }

}
