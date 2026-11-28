<?php

/**
 * Assertion helper for kernel refusals.
 *
 * Every denying path throws {@see DatabaseRefusal}; asserting the *code* rather
 * than the message keeps tests coupled to the contract instead of the wording.
 *
 * @package Nvoos\Database\Tests
 * @since   3.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Tests\Unit\Domain\Service\Database;

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\RefusalCode;

trait RefusalAssertions
{
    private function assertRefusal(RefusalCode $expected, callable $operation): void
    {
        try {
            $operation();
        } catch (DatabaseRefusal $refusal) {
            self::assertSame($expected, $refusal->getRefusal(), $refusal->getLogDetail());

            return;
        }

        self::fail('Expected a refusal with code ' . $expected->value . '.');
    }
}
