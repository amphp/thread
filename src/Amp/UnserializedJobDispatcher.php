<?php

namespace Amp;

use Alert\Reactor;

class UnserializedJobDispatcher implements Dispatcher {

    private $reactor;
    private $callResultFactory;
    private $jobServers = [];
    private $pendingJobServers = [];
    private $backoffCounts = [];
    private $pendingCalls = [];
    private $lastCallId = 0;
    private $readGranularity = 65355;
    private $keepAlive = TRUE;
    private $maxBackoffAttempts = 30;
    private $debug = FALSE;
    private $debugColors = FALSE;

    function __construct(Reactor $reactor, UnserializedCallResultFactory $crf = NULL) {
        $this->reactor = $reactor;
        $this->callResultFactory = $crf ?: new UnserializedCallResultFactory;
    }

    /**
     * Set multiple options at once
     *
     * @param array $options
     * @throws \DomainException On unknown option key
     * @return \Amp\JobServer Returns the current object instance
     */
    function setAllOptions(array $options) {
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }

        return $this;
    }

    /**
     * Set server options
     *
     * @param string $option
     * @param mixed $value
     * @throws \DomainException On unknown option
     * @return \Amp\JobServer Returns the current object instance
     */
    function setOption($option, $value) {
        switch (strtolower($option)) {
            case 'keepalive':
                $this->setKeepAlive($value);
                break;
            case 'maxbackoffattempts':
                $this->setMaxBackoffAttempts($value);
                break;
            case 'debug':
                $this->setDebug($value);
                break;
            case 'debugcolors':
                $this->setDebugColors($value);
                break;
            case 'readgranularity':
                $this->setReadGranularity($value);
                break;
            default:
                throw new \DomainException(
                    "Unknown option: {$option}"
                );
        }

        return $this;
    }

    private function setKeepAlive($boolFlag) {
        $this->keepAlive = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
    }

    private function setMaxBackoffAttempts($bytes) {
        $this->maxBackoffAttempts = filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => -1,
            'default' => 30
        ]]);
    }

    private function setDebug($boolFlag) {
        $this->debug = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
    }

    private function setDebugColors($boolFlag) {
        $this->debugColors = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
    }

    private function setReadGranularity($bytes) {
        $this->readGranularity = filter_var($bytes, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 1,
            'default' => 65535
        ]]);
    }

    /**
     * Connect to a job server synchronously (blocking)
     *
     * Note that the connection attempt is the only thing that is synchronous. Once established the
     * job server socket is non-blocking.
     *
     * @param string $uri The job server address and port (e.g. 127.0.0.1:1337)
     * @param int $timeout Timeout the connect attempt after N seconds
     * @throws \Amp\ResourceException On failed or timed out connection attempt
     * @return void
     */
    function connectToJobServer($uri, $timeout = 3) {
        if ($socket = @stream_socket_client($uri, $errNo, $errStr, $timeout, STREAM_CLIENT_CONNECT)) {
            stream_set_blocking($socket, FALSE);
            $server = new JobServerSession;
            $server->uri = $uri;
            $server->socket = $socket;
            $this->handleSuccessfulJobServerConnect($server);
        } else {
            throw new ResourceException(
                "Connection to {$uri} failed; Error [{$errNo}]: {$errStr}"
            );
        }
    }

    /**
     * Asynchronously connect to a job server (non-blocking)
     *
     * @param string $uri            The job server address and port (e.g. 127.0.0.1:1337)
     * @param callable $onResolution Invoked when the connection succeeds or fails. The callback is
     *                               passed TRUE on successful connects and FALSE otherwise.
     * @return void
     */
    function connectToJobServerAsync($uri, callable $onResolution) {
        if (!(isset($this->jobServers[$uri]) || isset($this->pendingJobServers[$uri]))) {
            $server = new JobServerSession;
            $server->uri = $uri;
            $server->onResolution = $onResolution;
            $this->doAsyncConnect($server);
        }
    }

    private function doAsyncConnect(JobServerSession $server) {
        $uri = $server->uri;
        $flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT;
        $fakeTimeout = 42; // <-- not used by PHP in ASYNC connects
        if ($socket = @stream_socket_client($uri, $errNo, $errStr, $fakeTimeout, $flags)) {
            $server->socket = $socket;
            $this->addPendingJobServer($server);
        } else {
            $this->handleJobServerConnectFailure($server, $errNo, $errStr);
        }
    }

    private function debug($msg, $color = NULL) {
        if ($this->debugColors && $color) {
            echo "\033[", $color, "m{$msg}\n\033[0m";
        } else {
            echo $msg, "\n";
        }
    }

    private function addPendingJobServer(JobServerSession $server) {
        if ($this->debug) {
            $msg = "Connection to {$server->uri} pending ...";
            $this->debug($msg, AnsiColor::FG_LIGHT_GRAY);
        }
        
        stream_set_blocking($server->socket, FALSE);
        
        $connectWatcher = $this->reactor->onWritable($server->socket, function() use ($server) {
            $this->resolvePendingJobServerConnection($server);
        });

        $server->connectWatcher = $connectWatcher;
        $this->pendingJobServers[$server->uri] = $server;
    }

    private function resolvePendingJobServerConnection(JobServerSession $server) {
        $this->reactor->cancel($server->connectWatcher);

        unset($this->pendingJobServers[$server->uri]);
        
        if (!@feof($server->socket)) {
            $this->handleSuccessfulJobServerConnect($server);
        } else {
            $this->handleJobServerConnectFailure($server, $errNo = 111, $errStr = 'Connection refused');
        }
    }

    private function handleSuccessfulJobServerConnect(JobServerSession $server) {
        if ($this->debug) {
            $msg = "Connection to {$server->uri} established!";
            $this->debug($msg, AnsiColor::FG_LIGHT_GREEN);
        }
        
        $uri = $server->uri;
        
        unset($this->backoffCounts[$uri]);
        
        $this->jobServers[$uri] = $server;

        $server->parser = new FrameParser;
        $server->writer = new FrameWriter($server->socket);

        $readWatcher = $this->reactor->onReadable($server->socket, function() use ($server) {
            $this->doJobServerRead($server);
        });
        
        $writeWatcher = $this->reactor->onWritable($server->socket, function() use ($server) {
            $this->doJobServerWrite($server);
        }, $enableNow = FALSE);

        $server->readWatcher = $readWatcher;
        $server->writeWatcher = $writeWatcher;

        if ($callback = $server->onResolution) {
            $callback($success = TRUE);
        }
    }

    private function handleJobServerConnectFailure(JobServerSession $server, $errNo, $errStr) {
        $uri = $server->uri;
        
        if ($this->debug) {
            $msg = "Connection to {$uri} failed; Error [{$errNo}]: {$errStr}";
            $this->debug($msg, AnsiColor::FG_LIGHT_RED);
        }

        if ($callback = $server->onResolution) {
            $callback($success = FALSE);
        }

        if ($this->canAttemptBackoff($uri)) {
            $this->doExponentialBackoff($server);
        }
    }
    
    private function canAttemptBackoff($uri) {
        if (!$this->keepAlive) {
            $canAttempt = FALSE;
        } elseif ($this->maxBackoffAttempts <= 0) {
            $canAttempt = TRUE;
        } elseif (!isset($this->backoffCounts[$uri])) {
            $canAttempt = TRUE;
        } elseif ($this->backoffCounts[$uri] < $this->maxBackoffAttempts) {
            $canAttempt = TRUE;
        } else {
            $canAttempt = FALSE;
        }
        
        return $canAttempt;
    }
    
    private function doExponentialBackoff(JobServerSession $server) {
        $uri = $server->uri;
        
        if (isset($this->backoffCounts[$uri])) {
            $maxWait = ($this->backoffCounts[$uri] * 2) - 1;
            $this->backoffCounts[$uri]++;
        } else {
            $maxWait = 1;
            $this->backoffCounts[$uri] = $maxWait;
        }
        
        $secondsUntilRetry = rand(0, $maxWait);

        if ($this->debug) {
            $msg = "Exponential connection backoff: retrying {$uri} in {$secondsUntilRetry} seconds ...";
            $this->debug($msg, AnsiColor::FG_YELLOW);
        }

        $onReconnect = $server->onResolution ?: function(){};
        
        if ($secondsUntilRetry) {
            $reconnect = function() use ($uri, $onReconnect) {
                $this->connectToJobServerAsync($uri, $onReconnect);
            };
            $this->reactor->once($reconnect, $secondsUntilRetry);
        } else {
            $this->connectToJobServerAsync($uri, $onReconnect);
        }
    }

    /**
     * Dispatch a call for asynchronous execution by a connected job server
     *
     * If no job servers are connected the call will be immediately fulfilled with an error result
     * on the next iteration of the event loop.
     *
     * @param callable $onResult Invoked when the call is fulfilled whether successful or not
     * @param string $procedure The procedure name to invoke asynchronously
     * @param mixed $workload Optional data to pass procedure on invocation
     * @return string Returns the unique call ID associated with this invocation (packed binary)
     */
    function call($onResult, $procedure, $workload = NULL) {
        if (($callId = ++$this->lastCallId) === Call::MAX_ID) {
            $this->lastCallId = 0;
        }

        $call = new Call;
        $call->id = $callId;
        $call->onResult = $onResult;
        $call->procedure = $procedure;

        $packedCallId = pack('N', $callId);
        $payload = $packedCallId . Call::REQUEST . chr(strlen($procedure)) . $procedure . $workload;
        $call->frame = new Frame($opcode = Frame::OP_DATA_FIN, $payload);

        $this->allocateCall($call);
        
        if ($this->debug) {
            $msg = "Call ID {$callId} dispatched ({$procedure})";
            $this->debug($msg, AnsiColor::FG_LIGHT_BLUE);
        }

        return $callId;
    }

    private function allocateCall(Call $call) {
        if ($this->jobServers) {
            $key = array_rand($this->jobServers);
            $jobServer = $this->jobServers[$key];
            $jobServer->pendingCalls[$call->id] = $call;
            $jobServer->writer->enqueueFrame($call->frame);
            $this->doJobServerWrite($jobServer);
        } else {
            $this->reactor->immediately(function() use ($call) {
                $this->failCallWhenNoJobServerConnected($call);
            });
        }
    }

    private function failCallWhenNoJobServerConnected(Call $call) {
        $error = new DispatchException('No job servers connected');
        $callResult = new CallResult($call->id, $result = NULL, $error);
        $callback = $call->onResult;
        $callback($callResult);
    }

    private function doJobServerWrite(JobServerSession $server) {
        try {
            if ($server->writer->write()) {
                $this->reactor->disable($server->writeWatcher);
            } else {
                $this->reactor->enable($server->writeWatcher);
            }
        } catch (ResourceException $e) {
            $this->onJobServerIoError($server);
        }
    }

    private function doJobServerRead(JobServerSession $server) {
        $data = @fread($server->socket, $this->readGranularity);

        if ($data || $data === '0') {
            $server->parser->bufferData($data);
            while ($frame = $server->parser->parse()) {
                $this->receiveJobServerFrame($server, $frame);
            }
        } elseif (!is_resource($server->socket) || @feof($server->socket)) {
            $this->onJobServerIoError($server);
        }
    }

    private function receiveJobServerFrame(JobServerSession $server, Frame $frame) {
        $payload = $frame->getPayload();

        $packedCallId = substr($payload, 0, 4);
        $callId = current(unpack('N', $packedCallId));
        $callCode = $payload[4];
        $payload = substr($payload, 5);

        $call = $server->pendingCalls[$callId];

        switch ($callCode) {
            case Call::RESULT:
                $call->resultBuffer .= $payload;
                $this->doCallResult($server, $call, $call->resultBuffer, $error = NULL);
                break;
            case Call::RESULT_PART:
                // @TODO Allow for partial result user callbacks
                $call->resultBuffer .= $payload;
                break;
            case Call::RESULT_ERROR:
                $error = new DispatchException($payload);
                $this->doCallResult($server, $call, $result = NULL, $error);
                break;
            default:
                throw new \UnexpectedValueException(
                    'Unexpected Call result code: ' . $callCode
                );
        }
    }

    private function doCallResult(JobServerSession $server, Call $call, $result, \Exception $error = NULL) {
        if ($this->debug) {
            $errorOrSuccess = ($error === NULL) ? ('SUCCESS') : ('ERROR: ' . $error);
            $color = ($error === NULL) ? AnsiColor::FG_LIGHT_PURPLE : AnsiColor::FG_LIGHT_RED;
            $msg = "Call ID {$call->id} returned ({$call->procedure}): {$errorOrSuccess}";
            $this->debug($msg, $color);
        }
        
        $callResult = $this->callResultFactory->make($call->id, $result, $error);
        $callback = $call->onResult;
        $callback($callResult);
        unset($server->pendingCalls[$call->id]);
    }

    private function onJobServerIoError(JobServerSession $server) {
        $uri = $server->uri;

        if ($this->debug) {
            $msg = "Connection to {$uri} went away :(";
            $this->debug($msg, AnsiColor::FG_YELLOW);
        }

        $this->reactor->cancel($server->readWatcher);
        $this->reactor->cancel($server->writeWatcher);

        $server->parser = NULL;
        $server->writer = NULL;
        $server->socket = NULL;

        if ($server->pendingCalls) {
            $error = new DispatchException("Lost connection to job server: {$uri}");
            foreach ($server->pendingCalls as $call) {
                $this->doCallResult($server, $call, $result = NULL, $error);
            }
        }

        unset($this->jobServers[$uri]);

        if ($this->canAttemptBackoff($uri)) {
            $this->doExponentialBackoff($server);
        }
    }

}
