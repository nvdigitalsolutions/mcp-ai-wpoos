<?php

namespace DeepCopy\f013;

use BadMethodCallException;
use Doctrine\Persistence\Proxy;

class B implements Proxy
{
    private $foo;

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

    public function getFoo()
    {
        return $this->foo;
    }

    public function setFoo($foo)
    {
        $this->foo = $foo;
    }
}
