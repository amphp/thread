# AMP MULTIPROCESS DISPATCHER CALL PAYLOAD FORMAT
     
       0                   1                   2                   3
      0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
     +---------------------------------------------------------------+
     |                                                               |
     |                          CALL ID (32)                         |
     |                                                               |
     +---------------+-----------------------------------------------+
     |      CALL     | Procedure len |                               :
     |      CODE     |      (8)      |       Procedure name ...      :
     |      (8)      |     0-255     |                               :
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
      
      Clients may increment CALL IDs using the following example algorithm:
      
      ```
      call_id = 0;
      max_call_id = 2147483647;
      if (++call_id == max_call_id) {
          call_id = 0;
      }
      ```
      
##### CALL CODE
      
      An 8-bit integer 0-255 (the most significant bit MUST be 0)
      identifying the message type. Valid values are:
      
      CALL          1
      CALL RESULT   2
      CALL ERROR    3
      
##### PROCEDURE LEN
      
      An 8-bit integer 0-255 (the most significant bit MUST be 0) denoting
      the length of the procedure to be called. Only present for CALL message
      types.
      
##### PROCEDURE NAME
      
      0-255 characters specifying the name of the procedure to invoke.
      Only present if the CALL CODE == 1.
      
##### WORKLOAD
      
      Zero or more character representing the workload to pass the
      procedure on CALL messages or the results of the invocation for
      CALL RESULT and CALL ERROR messages.
      
