<?php declare(strict_types=1);

namespace DeepCopy\f014;

class ReadonlyScalarProperty
{
    public readonly string $foo;
    public readonly int $bar;
    public readonly array $baz;

    public function __construct()
    {
        $this->foo = 'foo';
        $this->bar = 1;
        $this->baz = [];
    }
}