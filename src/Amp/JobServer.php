<?php

namespace Amp;

use Alert\Reactor;

class JobServer {

    private $reactor;
    private $dispatcher;
    private $serverSocket;
    private $acceptWatcher;
    private $onDispatchResult;
    private $onAcceptableClient;
    private $clients = [];
    private $lastInternalCallId = 0;
    private $internalCallClientMap = [];
    private $listenOn;
    private $readGranularity = 65355;
    private $serializeResults = TRUE;
    private $debug = FALSE;
    private $debugColors = FALSE;

    function __construct(Reactor $reactor, Dispatcher $dispatcher) {
        $this->reactor = $reactor;
        $this->dispatcher = $dispatcher;
        $this->onDispatchResult = function(CallResult $callResult) {
            $this->passthruCallResult($callResult);
        };
        $this->onAcceptableClient = function() {
            $this->acceptClients();
        };
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
            case 'listenon':
                $this->setListenOn($value);
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
            case 'serializeresults':
                $this->setSerializeResults($value);
            default:
                throw new \DomainException(
                    "Unknown option: {$option}"
                );
        }

        return $this;
    }

    private function setListenOn($address) {
        $this->listenOn = $address;
    }
    
    private function setSerializeResults($boolFlag) {
        $this->serializeResults = filter_var($boolFlag, FILTER_VALIDATE_BOOLEAN);
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
     * Bind to the specified TCP address and start the job server
     *
     * @param string $listenOn
     * @throws \RuntimeException On empty listening address or socket bind failure
     * @return void
     */
    function start($listenOn = NULL) {
        $listenOn = $listenOn ?: $this->listenOn;
        
        if (!$listenOn) {
            throw new \RuntimeException(
                'No server listening address specified'
            );
        }
        
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        $address = "tcp://{$listenOn}";

        if (!$sock = @stream_socket_server($address, $errNo, $errStr, $flags)) {
            throw new \RuntimeException(
                "Failed binding server to $address: [Error# $errNo] $errStr"
            );
        }

        stream_set_blocking($sock, FALSE);

        $this->serverSocket = $sock;
        $this->acceptWatcher = $this->reactor->onReadable($sock, $this->onAcceptableClient);

        if ($this->debug) {
            $address = substr(str_replace('0.0.0.0:', '*:', $address), 6);
            $msg = "BIND: {$address}";
            $this->debug($msg, AnsiColor::FG_LIGHT_GREEN);
        }
        
        $this->reactor->run();
    }

    /**
     * Stop the job server
     * 
     * @TODO Gracefully stop (allow pending jobs to complete)
     */
    function stop() {
        @fclose($this->serverSocket);
        $this->reactor->stop();
    }

    private function debug($msg, $color = NULL) {
        if ($this->debugColors && $color) {
            echo "\033[", $color, "m{$msg}\n\033[0m";
        } else {
            echo $msg, "\n";
        }
    }

    private function acceptClients() {
        while ($clientSock = @stream_socket_accept($this->serverSocket, $timeout = 0)) {
            $this->onClient($clientSock);
        }
    }

    private function onClient($socket) {
        stream_set_blocking($socket, FALSE);

        $client = new JobClientSession;

        $client->id = (int) $socket;
        $client->name = stream_socket_get_name($socket, TRUE);
        $client->socket = $socket;
        $client->parser = new FrameParser;
        $client->writer = new FrameWriter($socket);
        $client->readWatcher = $this->reactor->onReadable($socket, function() use ($client) {
            $this->doClientRead($client);
        });
        $client->writeWatcher = $this->reactor->onWritable($socket, function() use ($client) {
            $this->doClientWrite($client);
        }, $enableNow = FALSE);

        $this->clients[$client->id] = $client;

        if ($this->debug) {
            $msg = "CONN: {$client->name}";
            $this->debug($msg, AnsiColor::FG_LIGHT_GREEN);
        }
    }

    private function doClientRead(JobClientSession $client) {
        $data = @fread($client->socket, $this->readGranularity);

        if ($data || $data === '0') {
            $client->parser->bufferData($data);
            while ($frame = $client->parser->parse()) {
                $this->receiveClientDataFrame($client, $frame);
            }
        } elseif (!is_resource($client->socket) || @feof($client->socket)) {
            $this->unloadClient($client);
        }
    }

    private function unloadClient(JobClientSession $client) {
        $client->parser = NULL;
        $client->writer = NULL;

        $this->reactor->cancel($client->readWatcher);
        $this->reactor->cancel($client->writeWatcher);

        if ($client->internalCallMap) {
            $updatedCallMap = array_diff_key($this->internalCallClientMap, $client->internalCallMap);
            $this->internalCallClientMap = $updatedCallMap;
        }

        unset($this->clients[$client->id]);

        if ($this->debug) {
            $msg = "GONE: {$client->name}";
            $this->debug($msg, AnsiColor::FG_YELLOW);
        }
    }

    private function receiveClientDataFrame(JobClientSession $client, Frame $frame) {
        switch ($frame->getOpcode()) {
            case Frame::OP_DATA_FIN:
                $client->msgBuffer .= $frame->getPayload();
                $this->onClientDataMessage($client);
                break;
            case Frame::OP_DATA_MORE:
                $client->msgBuffer .= $frame->getPayload();
                break;
            case Frame::OP_DATA_CLOSE:
                // @TODO
                break;
            case Frame::OP_DATA_PING:
                // @TODO
                break;
            case Frame::OP_DATA_PONG:
                // @TODO
                break;
            default:
                // @TODO Close client for not adhering to the protocol
                break;
        }
    }

    private function onClientDataMessage(JobClientSession $client) {
        $msg = $client->msgBuffer;
        $client->msgBuffer = '';

        $callCode = (int) $msg[4];
        $clientCallId = substr($msg, 0, 4);
        $callPayload = substr($msg, 5);

        $procedureLength = ord($callPayload[0]);
        $procedure = substr($callPayload, 1, $procedureLength);

        if (($workload = substr($callPayload, $procedureLength + 1)) === FALSE) {
            $workload = NULL;
        }

        $internalCallId = $this->dispatcher->call($this->onDispatchResult, $procedure, $workload);
        $client->internalCallMap[$internalCallId] = $clientCallId;
        $this->internalCallClientMap[$internalCallId] = $client;

        if ($this->debug) {
            $uicid = current(unpack('N', $internalCallId));
            $msg = "CALL: ({$uicid}) {$client->name} | {$procedure}";
            $this->debug($msg, AnsiColor::FG_LIGHT_BLUE);
        }
    }

    private function passthruCallResult(CallResult $callResult) {
        $internalCallId = $callResult->getCallId();

        // This conditional is needed because the client may have disconnected while we were
        // working. In such cases the internal call ID reference will no longer exist and we
        // simply go on about our business.
        if (!isset($this->internalCallClientMap[$internalCallId])) {
            return;
        }

        $client = $this->internalCallClientMap[$internalCallId];
        $clientCallId = $client->internalCallMap[$internalCallId];

        if ($callResult->isSuccess()) {
            $callCode = Call::RESULT;
            $payload = $callResult->getResult();
            $payload = $this->serializeResults ? serialize($payload) : $payload;
        } else {
            $callCode = Call::RESULT_ERROR;
            $payload = (string) $callResult->getError();
        }

        $resultPayload = $clientCallId . $callCode . $payload;
        $resultFrame = new Frame(Frame::OP_DATA_FIN, $resultPayload);

        $client->writer->enqueueFrame($resultFrame);

        unset(
            $client->internalCallMap[$internalCallId],
            $this->internalCallClientMap[$internalCallId]
        );

        $this->doClientWrite($client);

        if ($this->debug) {
            $uicid = current(unpack('N', $internalCallId));
            $msg = "RSLT: ({$uicid}) {$client->name}";
            $this->debug($msg, AnsiColor::FG_LIGHT_PURPLE);
        }
    }

    private function doClientWrite(JobClientSession $client) {
        try {
            if ($client->writer->write()) {
                $this->reactor->disable($client->writeWatcher);
            } else {
                $this->reactor->enable($client->writeWatcher);
            }
        } catch (ResourceException $e) {
            $this->unloadClient($client);
        }
    }
    
    function __destruct() {
        $this->reactor->cancel($this->acceptWatcher);
        
        if ($this->debug) {
            $msg = "DOWN: Goodbye";
            $this->debug($msg, AnsiColor::FG_LIGHT_RED);
        }
    }

}
