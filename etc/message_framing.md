# AMP MESSAGE FRAMING

@TODO Talk about ... things.

#### Bit Map

      0                   1                   2                   3
     0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
    +---------------+---------------+-------------------------------+
    |    OPCODE     |      LEN      |    Extended payload length    |
    |      (8)      |      (8)      |           (16/32)             |
    |               |               |   (if payload len==254/255)   |
    |               |               |                               |
    +---------------+---------------+-------------------------------+
    |    Extended (len == 255)      |          Payload Data         :
    +-------------------------------+ - - - - - - - - - - - - - - - +
    :                     Payload Data continued ...                :
    + - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - +
    :                     Payload Data continued ...                |
    +---------------------------------------------------------------+

#### OPCODE:  1 byte
     
     - 0 (0x30) DATA_MORE - Data frame (more to come)
     - 1 (0x31) DATA_FIN - Data frame (message terminator)
     
     Signifies that this is the final frame/fragment in a message if
     equal to one (1). If zero (0) the OPCODE byte indicates that there
     are more frames to come before the message completes. The first
     frame in a message may also be the final frame. Subsequent
     single-byte integer values indicate control opcodes:
     
     - 2 (0x32) CLOSE - Inform of intent to close the connection
     - 3 (0x33) PING - Request a PONG frame to test connectivity
     - 4 (0x34) PONG - Respond to a received PING frame
     
     Though currently unused, this protocol reserves the opcodes 5 and 6
     (0x35-0x36) for future use. The single byte integer characters 7-9
     (0x37-0x39) are available for use by custom protocols to signify
     additional application-specific meaning.

#### LEN:  1 byte packed integer (0-255)

     The length of the message frame, in bytes: if 0-253, that is the
     payload length.  If 254, the following 2 bytes interpreted as a
     16-bit unsigned integer are the payload length. If 255, the
     following 4 bytes interpreted as a 32-bit unsigned integer (the
     most significant bit MUST be 0) are the payload length. Multibyte
     length quantities are expressed in network byte order. Note that
     in all cases, the minimal number of bytes MUST be used to encode
     the length, for example, the length of a 252-byte-long string
     can't be encoded as the sequence 254, 0, 252.
     
     Non-data messages (OPCODE > 1) must specify a LEN byte even if it
     is equal to zero.
     
#### Extended Payload Length

    @TODO

#### Payload data

    @TODO
