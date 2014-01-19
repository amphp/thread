<?php

namespace Amp;

class ForgetNotifier extends \Stackable {
    public function run() {
        $this->worker->registerResult(Thread::FORGET, NULL);
        $this->worker->notifyDispatcher();
    }
}
