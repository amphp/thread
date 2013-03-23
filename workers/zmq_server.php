<?php

$context = new ZMQContext(1);
$server = new ZMQSocket($context, ZMQ::SOCKET_XREP);
$server->bind("tcp://127.0.0.1:5555");

$requests = 0;

while (TRUE) {
    $request = $server->recv();
    var_dump($request);
    if (!$requestArr = @unserialize($request)) {
        //var_dump($request);
        continue;
    }
    
    
    
    list($callId, $procedure, $workload) = $requestArr;
    
    try {
        $responseCode = 1; // success
        $result = call_user_func_array($procedure, $workload);
    } catch (\Exception $e) {
        $responseCode = 0; // error
        $result = $e->__toString();
    }
    
    $response = serialize([$callId, $responseCode, $result]);
    
    try {
        $server->send($response);
    } catch (ZMQSocketException $e) {
        echo $e->getMessage(), "\n";
        // do something with the error
    }
}
