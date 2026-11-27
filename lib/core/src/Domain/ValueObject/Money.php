<?php

/**
 * An amount of money, as integer minor units plus an ISO 4217 currency.
 *
 * Proposal 164 section 164.111. This value object exists because all three
 * sources this contract has to absorb get money wrong in a different way:
 * WooCommerce stores a price as a *string* in postmeta, Shopify's Admin API
 * returns a decimal *string*, and the Laravel article this model follows uses a
 * `float` column. A float cannot represent 0.10 exactly, so a catalog migrated
 * through one accumulates rounding drift that no later reconciliation can undo.
 *
 * The rule here is the one every payment processor uses: hold the amount as an
 * integer in the currency's smallest unit, and never do arithmetic in decimal.
 * The exponent is not always 2 — JPY has no minor unit and KWD has three — so
 * the conversion is explicit rather than assumed.
 *
 * `fromDecimal()` **refuses** more fractional digits than the currency allows
 * instead of rounding them away. A caller that passes `19.999` USD has a bug
 * upstream, and silently writing `20.00` hides it; the exception is the useful
 * answer. A float argument is accepted because PHP callers will have one, but it
 * is converted through `sprintf('%.14F')` first and is therefore only exact for
 * amounts a float can actually hold.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

final readonly class Money implements \JsonSerializable
{
    /**
     * Currencies whose minor unit is not 2 decimal places.
     *
     * ISO 4217. Everything else is treated as 2, which is correct for the
     * overwhelming majority and is the documented default rather than a guess
     * made per amount.
     *
     * @var array<string, int>
     */
    private const EXPONENTS = array(
        'BIF' => 0,
        'CLP' => 0,
        'DJF' => 0,
        'GNF' => 0,
        'ISK' => 0,
        'JPY' => 0,
        'KMF' => 0,
        'KRW' => 0,
        'PYG' => 0,
        'RWF' => 0,
        'UGX' => 0,
        'VND' => 0,
        'VUV' => 0,
        'XAF' => 0,
        'XOF' => 0,
        'XPF' => 0,
        'BHD' => 3,
        'IQD' => 3,
        'JOD' => 3,
        'KWD' => 3,
        'LYD' => 3,
        'OMR' => 3,
        'TND' => 3,
    );

    /**
     * @param int    $minorUnits Amount in the currency's smallest unit (cents for USD).
     * @param string $currency   ISO 4217 alphabetic code, e.g. `USD`.
     *
     * @throws \InvalidArgumentException When the currency is not a 3-letter code.
     */
    public function __construct(
        public int $minorUnits,
        public string $currency,
    ) {
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException(
                sprintf('A currency must be a 3-letter ISO 4217 code; got "%s".', $currency)
            );
        }
    }

    /**
     * Build from a decimal amount, e.g. `'19.99'`.
     *
     * @param string|int|float $amount   Decimal amount.
     * @param string           $currency ISO 4217 code.
     *
     * @throws \InvalidArgumentException When the amount is malformed, too precise,
     *                                   or too large for the integer range.
     */
    public static function fromDecimal(string|int|float $amount, string $currency): self
    {
        $money = new self(0, $currency);
        $scale = $money->exponent();

        if (is_float($amount)) {
            $amount = sprintf('%.14F', $amount);
        } elseif (is_int($amount)) {
            $amount = (string) $amount;
        }

        $value = trim($amount);

        if (1 !== preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $value, $matches)) {
            throw new \InvalidArgumentException(
                sprintf('"%s" is not a decimal amount.', $amount)
            );
        }

        $sign     = '-' === $matches[1] ? -1 : 1;
        $whole    = ltrim($matches[2], '0');
        $fraction = $matches[3] ?? '';

        if ('' !== $fraction && $scale < strlen($fraction)) {
            $excess = substr($fraction, $scale);

            if ('' !== rtrim($excess, '0')) {
                throw new \InvalidArgumentException(
                    sprintf('%s has %d decimal places; %s allows %d.', $value, strlen($fraction), $currency, $scale)
                );
            }

            $fraction = substr($fraction, 0, $scale);
        }

        $fraction = str_pad($fraction, $scale, '0');

        if (strlen($whole) > 15) {
            throw new \InvalidArgumentException(
                sprintf('%s is too large to hold in minor units.', $value)
            );
        }

        $minor = (int) $whole * (10 ** $scale) + (int) ('' === $fraction ? '0' : $fraction);

        return new self($minor * $sign, $currency);
    }

    /**
     * Build from minor units when the caller already holds them.
     */
    public static function fromMinorUnits(int $minorUnits, string $currency): self
    {
        return new self($minorUnits, $currency);
    }

    /**
     * Zero in the given currency.
     */
    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * The number of decimal places this currency uses.
     */
    public function exponent(): int
    {
        return self::EXPONENTS[$this->currency] ?? 2;
    }

    /**
     * The amount as a decimal string, e.g. `'19.99'`.
     */
    public function toDecimal(): string
    {
        $scale  = $this->exponent();
        $sign   = $this->minorUnits < 0 ? '-' : '';
        $value  = abs($this->minorUnits);

        if ($scale <= 0) {
            return $sign . (string) $value;
        }

        $factor = 10 ** $scale;
        $whole  = intdiv($value, $factor);
        $rest   = $value % $factor;

        return $sign . $whole . '.' . str_pad((string) $rest, $scale, '0', STR_PAD_LEFT);
    }

    /**
     * @throws \InvalidArgumentException When the currencies differ.
     */
    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    /**
     * @throws \InvalidArgumentException When the currencies differ.
     */
    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    /**
     * Multiply by a whole quantity — a line total, not a rate.
     */
    public function multiply(int $quantity): self
    {
        return new self($this->minorUnits * $quantity, $this->currency);
    }

    /**
     * @throws \InvalidArgumentException When the currencies differ.
     */
    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->minorUnits === $other->minorUnits;
    }

    public function isZero(): bool
    {
        return 0 === $this->minorUnits;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits < $other->minorUnits;
    }

    /**
     * @return array{amount: string, currency: string}
     */
    public function jsonSerialize(): array
    {
        return array(
            'amount'   => $this->toDecimal(),
            'currency' => $this->currency,
        );
    }

    /**
     * @throws \InvalidArgumentException When the currencies differ.
     */
    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException(
                sprintf('Cannot combine %s and %s.', $this->currency, $other->currency)
            );
        }
    }
}
