<?php

namespace Amp\Dispatch\Process;

use Amp\Reactor;

class PhpDispatcher extends Dispatcher {
    
    /**
     * Normalizes and validates PHP worker commands and allows automatic result unserialization
     */
    function __construct(Reactor $reactor, $workerCmd, $poolSize = 1, WorkerSessionFactory $wsf = NULL) {
        $workerCmd = trim($workerCmd);
        
        if (!$workerCmd || (file_exists($workerCmd) && is_readable($workerCmd))) {
            $workerCmd = $this->buildWorkerCmdFromFunctionFile($workerCmd);
        } else {
            throw new \InvalidArgumentException(
                'Async function file does not exist: ' . $workerCmd
            );
        }
        
        $crf = new PhpCallResultFactory;
        
        parent::__construct($reactor, $workerCmd, $poolSize, $wsf, $crf);
    }
    
    private function buildWorkerCmdFromFunctionFile($workerCmd) {
        if ($workerCmd) {
            $this->validateWorkerLint($workerCmd);
        }
        
        $cmd = [];
        $cmd[] = PHP_BINARY;
        if ($ini = get_cfg_var('cfg_file_path')) {
            $cmd[] = "-c $ini";
        }
        $cmd[] = dirname(dirname(dirname(dirname(__DIR__)))) . '/workers/php/worker.php';
        
        if ($workerCmd) {
            $cmd[] = $workerCmd;
        }
        
        return implode(' ', $cmd);
    }
    
    private function validateWorkerLint($workerCmd) {
        $cmd = PHP_BINARY . ' -l ' . $workerCmd . '  && exit';
        exec($cmd, $outputLines, $exitCode);
        
        if ($exitCode) {
            throw new \RuntimeException(
                "Worker lint validation failed: " . PHP_EOL . implode(PHP_EOL, $outputLines)
            );
        }
    }
    
    /**
     * Automatically serializes variable arguments for transport to PHP workers
     * 
     * @param callable $onResult  The function to notify when the async result returns
     * @param string   $procedure The procedure to invoke asynchronously
     * @param mixed    $arg1, $arg2, ... $argN
     * 
     * @return int Returns the call ID associated with this invocation
     */
    function call(callable $onResult, $procedure, $varArgs = NULL) {
        if ($varArgs === NULL) {
            $args = [];
        } else {
            $args = func_get_args();
            unset($args[0], $args[1]);
            $args = array_values($args);
        }
        
        $serializedArgs = serialize($args);
        
        return parent::call($onResult, $procedure, $serializedArgs);
    }
    
}

