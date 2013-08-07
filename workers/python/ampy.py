from struct import pack, unpack
from sys import stdin, stdout

OP_DATA_MORE = 0;
OP_DATA_FIN = 1;
OP_CLOSE = 2;
OP_PING = 3;
OP_PONG = 4;

CALL_REQUEST = 1;
CALL_CANCEL = 2;
CALL_RESULT = 3;
CALL_RESULT_PART = 4;
CALL_RESULT_ERROR = 5;

class Frame:
    '''
    A value object representing an AMP messaging frame
    '''
    
    opcode = 0
    payload = ""
    
    def __init__(self, opcode, payload = ""):
        self.opcode = opcode
        self.payload = str(payload)
        
    def get_opcode(self):
        return self.opcode
        
    def get_payload(self):
        return self.payload
    
    def get_header(self):
        length = len(self.payload)
        
        if (length > 0xFFFF):
            second_byte = 0xFF;
            length_body = pack('!L', length)
        elif (length > 0xFE):
            second_byte = 0xFE
            length_body = pack('!H', length)
        else:
            second_byte = length
            length_body = ""
        
        return str(self.opcode) + chr(second_byte) + length_body
    
    def __str__(self):
        return self.get_header() + self.payload


def parse(input):
    '''
    Parse AMP data frames from an IO stream input
    '''
    
    while True:
        opcode = input.read(1)
        if len(opcode) != 0:
            break
    
    while True:
        length_byte = input.read(1)
        if len(length_byte) != 0:
            length = ord(length_byte)
            break
    
    if (length == 254):
        length = unpack("!H", input.read(2))[0]
    elif (length == 255):
        length = unpack("!L", input.read(4))[0]
    
    payload = input.read(length) if length else ""
    
    return Frame(opcode, payload)


def listen(callables):
    '''
    Listen for AMP procedure calls and route tasks to callables dictionary
    '''
    
    payload = ""
    
    while (1):
        frame = parse(stdin)
        payload += frame.get_payload()
        
        if not int(frame.get_opcode()) == OP_DATA_FIN:
            continue
        
        call_id   = payload[0:4]
        call_code = payload[4]
        proc_len  = ord(payload[5]) + 6
        procedure = payload[6:proc_len]
        workload  = payload[proc_len:]
        payload   = ""
        
        try:
            func = callables[procedure]
            
            if callable(func):
                call_response_code = str(CALL_RESULT)
                result = str(func(workload))
            else:
                raise Exception("Procedure `%s` not implemented" % procedure)
            
        except Exception as e:
            call_response_code = str(CALL_RESULT_ERROR)
            result = str(e)
        
        frame = Frame(OP_DATA_FIN, call_id + call_response_code + result)
        stdout.write(frame.get_header() + frame.get_payload())
        stdout.flush()

