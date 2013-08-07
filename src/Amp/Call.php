<?php

namespace Amp;

class Call {

    const MAX_ID = 2147483647;
    const REQUEST = 1;
    const CANCEL = 2;
    const RESULT = 3;
    const RESULT_PART = 4;
    const RESULT_ERROR = 5;

    public $id;
    public $frame;
    public $onResult;
    public $procedure;
    public $resultBuffer = '';

}
