<?php

namespace Amp;

class Task extends \Stackable {

    private $argCount;
    private $procedure;

    function __construct($procedure) {
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
            if ($this->argCount) {
                $args = [];
                for ($i=0; $i<$this->argCount; $i++) {
                    $args[] = $this->{"_$i"};
                }
                $result = call_user_func_array($this->procedure, $args);
            } else {
                $result = call_user_func($this->procedure);
            }
            $failed = FALSE;
        } catch (\Exception $e) {
            $result = $e->__toString();
            $failed = TRUE;
        }

        $this->worker->fufill($result, $failed);
    }

}
