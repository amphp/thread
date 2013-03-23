<?php

echo "Starting Gearman worker ...\n";
$gmworker= new GearmanWorker();



// Include userland functions from the specified file. Otherwise, only native functions are available.
if (!empty($argv[1])) {
    echo "Including userland functions from `", $argv[1], "` ...\n";
    @include($argv[1]);
}



echo "Registering available functions ...\n"
$availableFunctions = get_defined_functions();

$functionMap = [];

foreach ($availableFunctions['internal'] as $internalFunction) {
    $functionMap[$internalFunction] = TRUE;
    $gmworker->addFunction($internalFunction, "__ampAsyncGearmanProcedureRouter");
}

$internalFuncKey = array_search('__ampAsyncGearmanProcedureRouter', $availableFunctions['user']);
if (FALSE !== $internalFuncKey) {
    unset($availableFunctions['user'][$internalFuncKey]);
}

foreach ($availableFunctions['user'] as $userFunction) {
    $functionMap[$userFunction] = TRUE;
    $gmworker->addFunction($userFunction, "__ampAsyncGearmanProcedureRouter");
}







function __ampAsyncGearmanProcedureRouter(GearmanJob $job) {
    global $functionMap;
    
    list($procedure, $workload) = unserialize($job->workload());
    
    if (isset($functionMap[$procedure])) {
        try {
            $resultCode = 0; // success
            $result = call_user_func_array($procedure, $workload);
        } catch (Exception $e) {
            $resultCode = 1; // error
            $result = $e->__toString();
        }
    } else {
        $resultCode = 1; // error
        $result = (new ProcedureException(
            'Specified procedure does not exist: ' . $procedure
        ))->__toString();
    }
    
    return serialize([$resultCode, $result]);
}




print "Waiting for job...\n";
$gmworker->addServer();

while ($gmworker->work()) {
    if ($gmworker->returnCode() != GEARMAN_SUCCESS) {
        echo "return_code: " . $gmworker->returnCode() . "\n";
        break;
    }
}

