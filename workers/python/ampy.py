from struct import pack, unpack
from sys import stdin, stdout

OP_DATA     = 0x00
OP_CLOSE    = 0x0A
OP_PING     = 0x0B
CALL        = 1
CALL_RESULT = 2
CALL_ERROR  = 3

class Frame:
    '''
    A value object representing an AMP messaging frame
    '''
    
    fin = 0
    rsv = 0
    opcode = 0x00
    payload = ""
    
    def __init__(self, fin, rsv, opcode, payload = ""):
        self.fin = fin
        self.rsv = rsv
        self.opcode = opcode
        self.payload = str(payload)
    
    def is_fin(self):
        return self.fin
        
    def get_rsv(self):
        return self.rsv
        
    def get_opcode(self):
        return self.opcode
        
    def get_payload(self):
        return self.payload
    
    def get_raw_frame(self):
        first_byte = OP_DATA
        first_byte |= self.fin << 7
        first_byte |= self.rsv << 4
        first_byte |= self.opcode
        
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
        
        return chr(first_byte) + chr(second_byte) + length_body + self.payload;
    
    def __str__(self):
        return self.get_raw_frame()


def parse(input):
    '''
    Parse AMP data frames from an IO stream input
    '''
    
    while True:
        first_byte = input.read(1)
        
        if len(first_byte) != 0:
            first_byte = ord(first_byte)
            break
    
    fin    = bool(first_byte & 0b10000000)
    rsv    = (first_byte & 0b01110000) >> 4
    opcode = (first_byte & 0b00001111)
    
    while True:
        length = input.read(1)
        
        if len(length) != 0:
            length = ord(length)
            break
    
    if (length == 254):
        length = unpack("!H", input.read(2))[0]
    elif (length == 255):
        length = unpack("!L", input.read(4))[0]
    
    payload = input.read(length) if length else ""
    
    return Frame(fin, rsv, opcode, payload)



def listen(callables):
    '''
    Listen for AMP procedure calls and route tasks to callables dictionary
    '''
    
    payload = ""
    
    while (1):
        frame = parse(stdin)
        opcode = frame.get_opcode()
        
        if opcode != OP_DATA:
            # we don't do anything with non-data opcodes
            continue
        
        payload += frame.get_payload()
        
        if not frame.is_fin():
            continue
        
        call_id = payload[0:4]
        call_code = ord(payload[4])
        
        proc_len   = ord(payload[5]) + 6
        procedure = payload[6:proc_len]
        workload  = payload[proc_len:]
        payload   = ""
        
        try:
            func = callables[procedure]
            
            if callable(func):
                call_code = chr(CALL_RESULT)
                result = str(func(workload))
            else:
                raise Exception("Procedure `%s` not implemented" % procedure)
            
        except Exception as e:
            call_code = chr(CALL_ERROR)
            result = str(e)
        
        frame = Frame(1, 0, OP_DATA, call_id + call_code + result)
        stdout.write(frame.get_raw_frame())
        stdout.flush()

