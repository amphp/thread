<?php

namespace Amp;

class Resolver {

    public function resolve(\Generator $generator) {
        $result = $generator->current();

        if ($result instanceof Future) {
            $this->registerFutureCallbacks($generator, $result);
        //} elseif ($result && is_array($result) && ($result = new FutureGroup($result))) {
            // figure this one out
            // this will be either an array of futures or a FutureGroup returned by:
            // all(), first(), etc.
        } else {
            $generator->next();
        }
    }

    private function registerFutureCallbacks(\Generator $generator, Future $future) {
        $future->onComplete(function(Future $future) use ($generator) {
            if ($future->succeeded()) {
                $generator->send($future->value());
            } else {
                $generator->throw($future->error());
            }
            $this->resolve($generator);
        });
    }

    private function buildFutureGroup($future) {
        return FALSE;
        /* @TODO

        if (!($future && is_array($future))) {
            return FALSE;
        }

        $isFutureGroup = TRUE;
        $group = [];
        foreach ($future as $key => $f) {
            if ($f instanceof Future) {
                $group[$key] = $f;
            } else {
                return FALSE;
            }
        }

        return new FutureGroup($group);

        */
    }

}
