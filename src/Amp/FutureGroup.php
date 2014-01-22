<?php

namespace Amp;

class FutureGroup {

    private $futures;

    public function __construct(array $futures) {
        if (!$futures) {
            throw new \RuntimeException(
                sprintf('Array at %s Argument 1 must not be empty', __METHOD__)
            );
        }

        foreach ($futures as $key => $future) {
            if (!$future instanceof Future) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Array of Futures required at %s Argument 1: %s provided at index %s',
                        __METHOD__,
                        gettype($future),
                        $key
                    )
                );
            }
        }

        $this->futures = $futures;
    }

}
