# AMP CALL/RESULT/ERROR PAYLOAD FORMAT
     
       0                   1                   2                   3
      0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
     +---------------------------------------------------------------+
     |                                                               |
     |                          CALL ID (32)                         |
     |                                                               |
     +---------------+-----------------------------------------------+
     | Procedure len |                                               :
     |      (8)      |       Procedure name ...                      :
     |     0-255     |                                               :
     +---------------+ - - - - - - - - - - - - - - - - - - - - - - - +
     :                    Procedure name continued ...               |
     +---------------------------------------------------------------+
     |                                                               |
     |                          Workload                             |
     |                                                               |
     +---------------------------------------------------------------+
     
      
##### CALL ID
      
      All non-control messages MUST include a CALL_ID identifying the
      procedure call being invoked or responded to. The CALL_ID is a
      32-bit unsigned integer (the most significant bit MUST be 0).
      The CALL ID is expressed in network byte order. Muti-frame CALL,
      CALL_RESULT and CALL_ERROR messages must specify the same CALL_ID.
      
      Clients may increment CALL_IDs using the following example algorithm:
      
      call_id = 0;
      max_call_id = 2147483647;
      if (++call_id == max_call_id) {
          call_id = 0;
      }
      
##### PROCEDURE LEN
      
      An 8-bit unsigned integer denoting the length of the procedure
      to be called.
      
      
      
