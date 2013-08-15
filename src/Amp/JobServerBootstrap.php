<?php

namespace Amp;

use Alert\Reactor;

class JobServerBootstrap {

    private $shortOpts = 'l:i:b:w:udch';
    private $longOpts = [
        'listen:',
        'include:',
        'binary:',
        'workers:',
        'unserialized',
        'debug',
        'colors',
        'help'
    ];
    private $optNameMap = [
        'l' => 'listen',
        'i' => 'include',
        'b' => 'binary',
        'w' => 'workers',
        'u' => 'unserialized',
        'd' => 'debug',
        'c' => 'colors',
        'h' => 'help'
    ];
    private $options = [
        'listen' => NULL,
        'include' => NULL,
        'binary' => NULL,
        'workers' => 4,
        'unserialized' => NULL,
        'debug' => NULL,
        'colors' => NULL
    ];

    function loadOptions() {
        $options = getopt($this->shortOpts, $this->longOpts);

        if (isset($options['h']) || isset($options['help'])) {
            $isSuccess = FALSE;
        } else {
            $isSuccess = $this->doOptions($options);
        }

        return $isSuccess ?: $this->displayHelp();
    }

    private function displayHelp() {
        echo <<<EOT

php amp.php --listen="*:1337" --include="/path/to/user/functions.php"

-l, --listen         The listening address (e.g. 127.0.0.1:1337 or *:1337)
-i, --include        PHP userland include file
-b, --binary         Binary worker command
-w, --workers        Worker pool size (default: 4)
-u, --unserialized   Don't apply PHP serialization to call results
-d, --debug          Write debug info to STDOUT
-c, --colors         Use ANSI color codes in debug output
-h, --help           Display help screen


EOT;
        return FALSE;
    }

    private function doOptions(array $options) {
        try {
            $this->normalizeOptions($options);
            $this->validateOptions();
            return $canContinue = TRUE;
        } catch (\RuntimeException $e) {
            echo "\n", $e->getMessage(), "\n";
        }
    }

    private function normalizeOptions(array $options) {
        foreach ($options as $key => $value) {
            if (isset($this->optNameMap[$key])) {
                $this->options[$this->optNameMap[$key]] = $value;
            } else {
                $this->options[$key] = $value;
            }
        }
    }

    private function validateOptions() {
        if (empty($this->options['listen'])) {
            throw new \RuntimeException(
                'Listen address required (e.g. --listen=*:1337 or -l"127.0.0.1:1337")'
            );
        } elseif (isset($this->options['include'], $this->options['binary'])) {
            throw new \RuntimeException(
                'Cannot specify both include (-i, --include) AND binary options (-b, --binary)'
            );
        } elseif (!filter_var($this->options['workers'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            throw new \RuntimeException(
                'Workers option (-w, --workers) requires an integer value greater than or equal to 1'
            );
        }
    }

    function createJobServer(Reactor $reactor) {
        $poolSize = $this->options['workers'];

        if (isset($this->options['include'])) {
            $include = $this->options['include'];
            $dispatcher = new IoDispatcher($reactor, $include, $poolSize);
        } elseif ($this->options['binary']) {
            $binaryCmd = $this->options['binary'];
            $dispatcher = new UnserializedIoDispatcher($reactor, $binaryCmd, $poolSize);
        } else {
            $include = '';
            $dispatcher = new IoDispatcher($reactor, $include, $poolSize);
        }

        $jobServer = new JobServer($reactor, $dispatcher);

        $listenOn = str_replace('*:', '0.0.0.0:', $this->options['listen']);
        $jobServer->setOption('listenOn', $listenOn);

        if (isset($this->options['debug'])) {
            $jobServer->setOption('debug', TRUE);
        }

        if (isset($this->options['colors'])) {
            $jobServer->setOption('debugColors', TRUE);
        }

        if (isset($this->options['unserialized'])) {
            $jobServer->setOption('serializeResults', FALSE);
        }

        return $jobServer;
    }

}
