<?php

/**
 * Turn invoice arguments into printable HTML and auditable totals.
 *
 * Shared by `generate_invoice_pdf` and `generate_invoice_batch` so the two
 * cannot disagree about arithmetic.
 *
 * ## Money is counted in minor units
 *
 * Floating point is fine for a rate and wrong for a sum: `0.1 + 0.2` is not
 * `0.3`, and an invoice whose printed total is a cent away from its printed
 * lines is the kind of defect a customer finds first. Every amount is converted
 * to integer minor units on the way in and rendered as a decimal only at the
 * end. Rounding happens once per line, once for tax, and nowhere else.
 *
 * ## The WordPress arithmetic is corrected
 *
 * The tool this ports sums `items[].amount` and ignores `quantity` and `rate`
 * entirely — while still *printing* them. An item given as "3 × 20.00" with no
 * `amount` therefore prints a line of 0.00 and contributes nothing to the
 * total. Here a line's amount is `amount` when supplied, otherwise
 * `quantity × rate`, and the same number is both printed and summed.
 *
 * ## A supplied total is a cross-check, not an override
 *
 * WordPress accepts `subtotal` and `total` and then silently recomputes them,
 * so a caller who sent a wrong total received a right invoice and never knew.
 * A financial document should not quietly contradict its caller: when the
 * supplied figure disagrees with the items, that is refused, because either the
 * caller's arithmetic or ours is wrong and printing it would hide which.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Document;

use Nvoos\Core\Domain\Service\Document\HtmlFormatter;

final class InvoiceDocument
{
    /**
     * Line items per invoice. A hundred lines is far more than a printable
     * invoice should carry, and the cap stops a runaway array from being
     * rendered page after page.
     */
    public const MAX_ITEMS = 100;

    /** ISO-4217 is three letters; anything else is not a currency code. */
    public const CURRENCY_PATTERN = '/^[A-Za-z]{3}$/';

    public function __construct(private readonly HtmlFormatter $formatter)
    {
    }

    /**
     * Build the invoice body and its totals.
     *
     * @param array<string, mixed> $arguments Invoice arguments.
     *
     * @return array{body: string, totals: array<string, mixed>}
     *
     * @throws \InvalidArgumentException When the data cannot be printed as stated.
     */
    public function build(array $arguments): array
    {
        $number = $this->text($arguments['invoice_number'] ?? '');

        if ('' === $number) {
            throw new \InvalidArgumentException('An invoice_number is required.');
        }

        $currency = $this->currency($arguments['currency'] ?? 'USD');
        $items = $this->items($arguments['items'] ?? null);

        if (array() === $items) {
            throw new \InvalidArgumentException('At least one item with a description is required.');
        }

        $taxRate = $this->rate($arguments['tax_rate'] ?? 0);
        $date = $this->date($arguments['date'] ?? '', 'date', gmdate('Y-m-d'));
        $dueDate = $this->date($arguments['due_date'] ?? '', 'due_date', gmdate('Y-m-d', (int) strtotime('+30 days')));

        $subtotal = 0;

        foreach ($items as $item) {
            $subtotal += $item['amount_minor'];
        }

        $tax = (int) round($subtotal * $taxRate / 100);
        $total = $subtotal + $tax;

        $totals = array(
            'number' => $number,
            'date' => $date,
            'due_date' => $dueDate,
            'currency' => $currency,
            'tax_rate' => $taxRate,
            'subtotal' => $this->major($subtotal),
            'tax' => $this->major($tax),
            'total' => $this->major($total),
            'subtotal_minor' => $subtotal,
            'tax_minor' => $tax,
            'total_minor' => $total,
            'items' => array_map(
                static fn (array $item): array => array(
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    // `round()` rather than plain division: PHP returns an int
                    // from an exact division, so half the money fields would be
                    // ints and half floats depending on the amount.
                    'rate' => round($item['rate_minor'] / 100, 2),
                    'amount' => round($item['amount_minor'] / 100, 2),
                ),
                $items,
            ),
        );

        $this->assertTotalsAgree($totals, $arguments);

        return array(
            'body' => $this->body($totals, $items, $arguments['bill_to'] ?? null),
            'totals' => $totals,
        );
    }

    /**
     * Refuse a supplied `subtotal` or `total` that the items do not support.
     *
     * @param array<string, mixed> $totals    Computed totals.
     * @param array<string, mixed> $arguments Originating arguments.
     *
     * @throws \InvalidArgumentException When a supplied figure disagrees.
     */
    private function assertTotalsAgree(array $totals, array $arguments): void
    {
        foreach (array('subtotal' => 'subtotal_minor', 'total' => 'total_minor') as $key => $minor) {
            $claimed = $arguments[$key] ?? null;

            if (null === $claimed || (is_string($claimed) && '' === trim($claimed))) {
                continue;
            }

            $claimedMinor = $this->minor($this->number($claimed, $key));

            if ($claimedMinor !== (int) $totals[$minor]) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'The supplied %s (%s) disagrees with the item arithmetic (%s). Drop it, or correct the items.',
                        $key,
                        $this->money($claimedMinor),
                        $this->money((int) $totals[$minor]),
                    ),
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $totals Computed totals.
     * @param list<array{description: string, quantity: float, rate_minor: int, amount_minor: int}> $items
     * @param mixed                $billTo Whatever the caller passed.
     */
    private function body(array $totals, array $items, mixed $billTo): string
    {
        $currency = (string) $totals['currency'];

        $body = array(
            '<h1>INVOICE</h1>',
            '<p><strong>Invoice #:</strong> ' . $this->e((string) $totals['number']) . '</p>',
            '<p><strong>Date:</strong> ' . $this->e((string) $totals['date']) . '</p>',
            '<p><strong>Due date:</strong> ' . $this->e((string) $totals['due_date']) . '</p>',
        );

        $payee = $this->billTo($billTo);

        if ('' !== $payee) {
            $body[] = '<p><strong>Bill to:</strong></p>' . $payee;
        }

        $rows = array();

        foreach ($items as $item) {
            $rows[] = array(
                $item['description'],
                $this->quantity($item['quantity']),
                $this->money($item['rate_minor']),
                $this->money($item['amount_minor']),
            );
        }

        $body[] = $this->formatter->createTable(
            array(
                'headers' => array('Description', 'Qty', 'Rate', 'Amount'),
                'rows' => $rows,
            ),
        );

        $body[] = '<p style="text-align:right;"><strong>Subtotal:</strong> ' . $currency . ' '
            . $this->money((int) $totals['subtotal_minor']) . '</p>';

        if ((float) $totals['tax_rate'] > 0) {
            $body[] = '<p style="text-align:right;"><strong>Tax (' . $this->quantity((float) $totals['tax_rate'])
                . '%):</strong> ' . $currency . ' ' . $this->money((int) $totals['tax_minor']) . '</p>';
        }

        $body[] = '<p style="text-align:right;"><strong>Total:</strong> ' . $currency . ' '
            . $this->money((int) $totals['total_minor']) . '</p>';

        return implode("\n", $body);
    }

    /**
     * Render the billing block as escaped paragraphs.
     *
     * The WordPress tool accepts `bill_to` and never prints it. An invoice
     * without a recipient is not an invoice, so it is rendered here — as a
     * string, or as a map of the fields an invoice actually carries.
     */
    private function billTo(mixed $raw): string
    {
        if (is_string($raw)) {
            $raw = trim($raw);

            return '' === $raw ? '' : '<p>' . $this->e($raw) . '</p>';
        }

        if (!is_array($raw)) {
            return '';
        }

        $lines = array();

        foreach (array('name', 'company', 'address', 'address_2', 'city', 'postcode', 'country', 'email', 'phone') as $key) {
            $value = $raw[$key] ?? null;

            if (!is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ('' !== $value) {
                $lines[] = '<p>' . $this->e($value) . '</p>';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Normalise the line items.
     *
     * @return list<array{description: string, quantity: float, rate_minor: int, amount_minor: int}>
     *
     * @throws \InvalidArgumentException When an item cannot be read.
     */
    private function items(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('items must be an array of objects.');
        }

        if (count($raw) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException('An invoice carries at most ' . self::MAX_ITEMS . ' items.');
        }

        $items = array();

        foreach (array_values($raw) as $index => $item) {
            $label = 'Item ' . ($index + 1);

            if (!is_array($item)) {
                throw new \InvalidArgumentException($label . ' is not an object.');
            }

            $description = $this->text($item['description'] ?? '');

            if ('' === $description) {
                throw new \InvalidArgumentException($label . ' needs a description.');
            }

            $quantity = $this->number($item['quantity'] ?? 1, $label . ' quantity');
            $rate = $this->number($item['rate'] ?? 0, $label . ' rate');
            $amount = $item['amount'] ?? null;

            $amountMinor = null === $amount || (is_string($amount) && '' === trim($amount))
                ? $this->minor($quantity * $rate)
                : $this->minor($this->number($amount, $label . ' amount'));

            $items[] = array(
                'description' => $description,
                'quantity' => $quantity,
                'rate_minor' => $this->minor($rate),
                'amount_minor' => $amountMinor,
            );
        }

        return $items;
    }

    /**
     * A non-negative finite number.
     *
     * @throws \InvalidArgumentException When the value is not one.
     */
    private function number(mixed $value, string $label): float
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException($label . ' must be a number.');
        }

        $number = (float) $value;

        if (!is_finite($number) || $number < 0) {
            throw new \InvalidArgumentException($label . ' must be a non-negative number.');
        }

        return $number;
    }

    /**
     * A tax rate in percent, 0–100.
     *
     * @throws \InvalidArgumentException When out of range.
     */
    private function rate(mixed $value): float
    {
        $rate = $this->number($value, 'tax_rate');

        if ($rate > 100) {
            throw new \InvalidArgumentException('tax_rate is a percentage, so it cannot exceed 100.');
        }

        return $rate;
    }

    /**
     * @throws \InvalidArgumentException When the code is not three letters.
     */
    private function currency(mixed $value): string
    {
        $code = strtoupper(trim((string) (is_scalar($value) ? $value : '')));

        if ('' === $code) {
            return 'USD';
        }

        if (1 !== preg_match(self::CURRENCY_PATTERN, $code)) {
            throw new \InvalidArgumentException('currency must be a three-letter ISO-4217 code, e.g. USD.');
        }

        return $code;
    }

    /**
     * An ISO date, or the fallback when the caller gave none.
     *
     * @throws \InvalidArgumentException When a value was given and is not a real date.
     */
    private function date(mixed $value, string $label, string $fallback): string
    {
        $date = trim((string) (is_scalar($value) ? $value : ''));

        if ('' === $date) {
            return $fallback;
        }

        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException($label . ' must be a real date in YYYY-MM-DD form.');
        }

        return $date;
    }

    /**
     * Major units as integer minor units, rounded once.
     */
    private function minor(float $major): int
    {
        return (int) round($major * 100);
    }

    /**
     * Minor units back to major units for the payload.
     */
    private function major(int $minor): float
    {
        return round($minor / 100, 2);
    }

    /**
     * Minor units as a printable amount, without a currency symbol.
     */
    private function money(int $minor): string
    {
        return number_format($minor / 100, 2, '.', ',');
    }

    /**
     * A quantity without trailing zeros, so "3" does not print as "3.0000".
     */
    private function quantity(float $value): string
    {
        $formatted = number_format($value, 4, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    /**
     * A trimmed string, or empty for a non-scalar.
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Escape a value for HTML.
     *
     * `HtmlFormatter` escapes its own inputs but does not expose that step, and
     * the invoice prose is assembled here rather than in the formatter.
     */
    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
