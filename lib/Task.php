<?php

namespace Amp\Thread;

class Task extends \Stackable {

    private $argCount;
    private $procedure;

    public function __construct($procedure) {
        $this->procedure = $procedure;
        $this->argCount = (func_num_args() - 1);

        if ($this->argCount > 0) {
            $args = func_get_args();
            array_shift($args);
            for ($i=0; $i<$this->argCount; $i++) {
                $this->{"_$i"} = $args[$i];
            }
        }
    }

    public function run() {
        try {
            if (!is_callable($this->procedure)) {
                throw new \BadFunctionCallException(
                    sprintf("Function does not exist: %s", $this->procedure)
                );
            } elseif ($this->argCount) {
                $args = [];
                for ($i=0; $i<$this->argCount; $i++) {
                    $args[] = $this->{"_$i"};
                }
                $result = call_user_func_array($this->procedure, $args);
            } else {
                $result = call_user_func($this->procedure);
            }

            $resultCode = Thread::SUCCESS;

        } catch (\Exception $e) {
            $resultCode = Thread::FAILURE;
            $result = $e->__toString();
        }

        $this->worker->resolve($resultCode, $result);
    }

}
