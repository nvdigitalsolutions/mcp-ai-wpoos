<?php

namespace DeepCopy\f013;

use BadMethodCallException;
use Doctrine\Persistence\Proxy;

class A implements Proxy
{
    public $foo = 1;

    /**
     * @inheritdoc
     */
    public function __load(): void
    {
    }

    /**
     * @inheritdoc
     */
    public function __isInitialized(): bool
    {
        throw new BadMethodCallException();
    }
}
