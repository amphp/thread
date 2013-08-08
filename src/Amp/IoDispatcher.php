<?php

namespace Amp;

use Alert\Reactor;

class IoDispatcher extends UnserializedIoDispatcher {

    function __construct(Reactor $reactor, $userFunctionFile = '', $poolSize = 1) {
        $userFunctionFile = trim($userFunctionFile);

        if ($userFunctionFile) {
            $this->validateUserFunctionFile($userFunctionFile);
        }

        $baseWorkerScript = dirname(dirname(__DIR__)) . '/workers/php/worker.php';
        $workerCmd = $this->buildWorkerCommand($baseWorkerScript, $userFunctionFile);

        $crf = new CallResultFactory;

        parent::__construct($reactor, $workerCmd, $poolSize, $crf);
    }

    private function validateUserFunctionFile($userFunctionFile) {
        if (!(file_exists($userFunctionFile) && is_readable($userFunctionFile))) {
            throw new \InvalidArgumentException(
                "User function file does not exist: {$userFunctionFile}"
            );
        }

        $cmd = PHP_BINARY . ' -l ' . $userFunctionFile . '  && exit';
        exec($cmd, $outputLines, $exitCode);

        if ($exitCode) {
            throw new \RuntimeException(
                "User function file failed lint test: " . PHP_EOL . implode(PHP_EOL, $outputLines)
            );
        }
    }

    private function buildWorkerCommand($baseWorkerScript, $userFunctionFile) {
        $cmd = [];
        $cmd[] = PHP_BINARY;
        if ($ini = get_cfg_var('cfg_file_path')) {
            $cmd[] = "-c $ini";
        }
        $cmd[] = $userInclude;

        if ($workerCmd) {
            $cmd[] = $workerCmd;
        }

        return implode(' ', $cmd);
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
    function call($onResult, $procedure, $varArgs = NULL) {
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
