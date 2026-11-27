<?php

/**
 * Tests for the Money value object.
 *
 * The cases that matter are the ones a float would get wrong: exact cents, a
 * currency with no minor unit, a currency with three, and a source that hands
 * over more precision than the currency has. The last must throw rather than
 * round — silently discarding a digit is how a catalog drifts.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\ValueObject;

use Nvoos\Core\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_decimal_input_is_held_as_minor_units(): void
    {
        $money = Money::fromDecimal('19.99', 'USD');

        $this->assertSame(1999, $money->minorUnits);
        $this->assertSame('USD', $money->currency);
        $this->assertSame('19.99', $money->toDecimal());
    }

    public function test_more_fractional_zeros_are_accepted(): void
    {
        $this->assertSame(1999, Money::fromDecimal('19.990', 'USD')->minorUnits);
        $this->assertSame('19.99', Money::fromDecimal('19.990', 'USD')->toDecimal());
    }

    public function test_excess_precision_is_refused_not_rounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::fromDecimal('19.999', 'USD');
    }

    public function test_a_zero_decimal_currency_has_no_fraction(): void
    {
        $yen = Money::fromDecimal('1200', 'JPY');

        $this->assertSame(1200, $yen->minorUnits);
        $this->assertSame('1200', $yen->toDecimal());
        $this->assertSame(0, $yen->exponent());
    }

    public function test_a_zero_decimal_currency_refuses_a_fraction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::fromDecimal('1200.50', 'JPY');
    }

    public function test_a_three_decimal_currency_keeps_three(): void
    {
        $dinar = Money::fromDecimal('1.234', 'KWD');

        $this->assertSame(1234, $dinar->minorUnits);
        $this->assertSame('1.234', $dinar->toDecimal());
        $this->assertSame(3, $dinar->exponent());
    }

    public function test_negative_amounts_round_trip(): void
    {
        $refund = Money::fromDecimal('-5.00', 'USD');

        $this->assertSame(-500, $refund->minorUnits);
        $this->assertSame('-5.00', $refund->toDecimal());
    }

    public function test_leading_zeros_and_integer_input(): void
    {
        $this->assertSame(50000, Money::fromDecimal('00500', 'USD')->minorUnits);
        $this->assertSame(500, Money::fromDecimal('005.00', 'USD')->minorUnits);
        $this->assertSame(2000, Money::fromDecimal(20, 'USD')->minorUnits);
    }

    public function test_float_input_is_converted(): void
    {
        $this->assertSame(1999, Money::fromDecimal(19.99, 'USD')->minorUnits);
    }

    public function test_arithmetic_stays_in_minor_units(): void
    {
        $unit = Money::fromDecimal('0.10', 'USD');
        $sum  = $unit->add($unit)->add($unit);

        $this->assertSame('0.30', $sum->toDecimal());
        $this->assertSame('0.20', $sum->subtract($unit)->toDecimal());
        $this->assertSame(1999 * 3, Money::fromDecimal('19.99', 'USD')->multiply(3)->minorUnits);
    }

    public function test_combining_different_currencies_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::fromDecimal('1.00', 'USD')->add(Money::fromDecimal('1.00', 'EUR'));
    }

    public function test_comparison_and_equality(): void
    {
        $cheap    = Money::fromDecimal('9.99', 'USD');
        $dear     = Money::fromDecimal('19.99', 'USD');
        $samePair = Money::fromDecimal('9.99', 'USD');

        $this->assertTrue($cheap->lessThan($dear));
        $this->assertFalse($dear->lessThan($cheap));
        $this->assertTrue($cheap->equals($samePair));
        $this->assertFalse($cheap->equals($dear));
        $this->assertTrue(Money::zero('USD')->isZero());
    }

    public function test_json_shape_is_decimal_string_and_currency(): void
    {
        $this->assertSame(
            array('amount' => '19.99', 'currency' => 'USD'),
            Money::fromDecimal('19.99', 'USD')->jsonSerialize()
        );
    }

    public function test_currency_must_be_a_three_letter_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Money(100, 'usd');
    }

    public function test_a_malformed_amount_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::fromDecimal('nineteen', 'USD');
    }
}
