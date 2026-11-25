<?php

/**
 * `InvoiceDocument` — the arithmetic.
 *
 * An invoice is a financial document, so this file is about money: that a line
 * total is what was printed, that a sum of cents does not drift, that tax is
 * rounded once, and that a caller-supplied total which contradicts the items is
 * refused rather than silently replaced.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Document;

use Nvoos\Core\Application\Document\InvoiceDocument;
use Nvoos\Core\Domain\Service\Document\HtmlFormatter;
use PHPUnit\Framework\TestCase;

final class InvoiceDocumentTest extends TestCase
{
    private InvoiceDocument $invoices;

    protected function setUp(): void
    {
        $this->invoices = new InvoiceDocument(new HtmlFormatter());
    }

    // ─── Line arithmetic ───────────────────────────────────────────────────

    public function test_a_line_is_quantity_times_rate_when_no_amount_is_given(): void
    {
        $built = $this->build(array(
            array('description' => 'Consulting', 'quantity' => 3, 'rate' => 20),
        ));

        $this->assertSame(6000, $built['totals']['subtotal_minor']);
        $this->assertSame(60.0, $built['totals']['subtotal']);
        $this->assertSame(60.0, $built['totals']['items'][0]['amount']);
        $this->assertSame(60.0, $built['totals']['total']);
    }

    /**
     * The WordPress tool sums `amount` and prints `quantity × rate`, so a line
     * given the latter and not the former prints 0.00 and contributes nothing.
     */
    public function test_a_supplied_amount_wins_over_quantity_and_rate(): void
    {
        $built = $this->build(array(
            array('description' => 'Retainer', 'quantity' => 3, 'rate' => 100, 'amount' => 250.50),
        ));

        $this->assertSame(25050, $built['totals']['subtotal_minor']);
        $this->assertSame(250.5, $built['totals']['items'][0]['amount']);
    }

    public function test_quantity_defaults_to_one_and_rate_to_zero(): void
    {
        $built = $this->build(array(array('description' => 'Sample')));

        $this->assertSame(1.0, $built['totals']['items'][0]['quantity']);
        $this->assertSame(0.0, $built['totals']['items'][0]['rate']);
        $this->assertSame(0, $built['totals']['subtotal_minor']);
    }

    /**
     * `0.1 + 0.2` is not `0.3` in floating point. Three ten-cent lines must sum
     * to forty cents, not thirty-nine or forty-one.
     */
    public function test_cents_are_summed_without_floating_point_drift(): void
    {
        $built = $this->build(array(
            array('description' => 'A', 'amount' => 0.10),
            array('description' => 'B', 'amount' => 0.20),
            array('description' => 'C', 'amount' => 0.10),
        ));

        $this->assertSame(40, $built['totals']['subtotal_minor']);
        $this->assertSame(0.4, $built['totals']['subtotal']);
    }

    public function test_a_sub_cent_total_rounds_to_the_nearest_cent(): void
    {
        $built = $this->build(array(
            array('description' => 'A', 'quantity' => 3, 'rate' => 0.333),
        ));

        // 0.999 rounds to 1.00, once.
        $this->assertSame(100, $built['totals']['subtotal_minor']);
    }

    // ─── Tax ───────────────────────────────────────────────────────────────

    public function test_tax_is_a_percentage_of_the_subtotal(): void
    {
        $built = $this->build(
            array(array('description' => 'Work', 'amount' => 60)),
            array('tax_rate' => 10),
        );

        $this->assertSame(600, $built['totals']['tax_minor']);
        $this->assertSame(6600, $built['totals']['total_minor']);
        $this->assertSame(66.0, $built['totals']['total']);
        $this->assertSame(10.0, $built['totals']['tax_rate']);
    }

    public function test_tax_rounds_to_a_whole_cent(): void
    {
        $built = $this->build(
            array(array('description' => 'Work', 'amount' => 33.33)),
            array('tax_rate' => 7.5),
        );

        // 2.49975 -> 2.50
        $this->assertSame(250, $built['totals']['tax_minor']);
        $this->assertSame(3583, $built['totals']['total_minor']);
    }

    public function test_no_tax_rate_means_no_tax_line(): void
    {
        $built = $this->build(array(array('description' => 'Work', 'amount' => 10)));

        $this->assertSame(0, $built['totals']['tax_minor']);
        $this->assertStringNotContainsString('Tax (', $built['body']);
    }

    // ─── The cross-check ───────────────────────────────────────────────────

    public function test_a_matching_supplied_subtotal_and_total_are_accepted(): void
    {
        $built = $this->build(
            array(array('description' => 'Work', 'amount' => 100)),
            array('tax_rate' => 10, 'subtotal' => 100, 'total' => 110),
        );

        $this->assertSame(11000, $built['totals']['total_minor']);
    }

    public function test_a_disagreeing_supplied_subtotal_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('subtotal');

        $this->build(
            array(array('description' => 'Work', 'amount' => 100)),
            array('subtotal' => 99),
        );
    }

    public function test_a_disagreeing_supplied_total_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('total');

        $this->build(
            array(array('description' => 'Work', 'amount' => 100)),
            array('tax_rate' => 10, 'total' => 100),
        );
    }

    // ─── Refusals ──────────────────────────────────────────────────────────

    public function test_an_invoice_number_is_required(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invoice_number');

        $this->invoices->build(array('items' => array(array('description' => 'Work'))));
    }

    public function test_at_least_one_item_is_required(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->invoices->build(array('invoice_number' => 'INV-1', 'items' => array()));
    }

    public function test_an_item_without_a_description_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Item 1 needs a description');

        $this->build(array(array('quantity' => 1, 'rate' => 5)));
    }

    public function test_a_negative_amount_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->build(array(array('description' => 'Credit', 'amount' => -5)));
    }

    public function test_a_non_numeric_amount_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->build(array(array('description' => 'Work', 'amount' => 'lots')));
    }

    public function test_a_tax_rate_above_one_hundred_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed 100');

        $this->build(array(array('description' => 'Work', 'amount' => 10)), array('tax_rate' => 101));
    }

    public function test_a_currency_that_is_not_three_letters_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ISO-4217');

        $this->build(array(array('description' => 'Work', 'amount' => 10)), array('currency' => 'dollars'));
    }

    public function test_an_impossible_date_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('YYYY-MM-DD');

        $this->build(array(array('description' => 'Work', 'amount' => 10)), array('date' => '2026-02-31'));
    }

    public function test_more_items_than_the_cap_is_refused(): void
    {
        $rows = array();

        for ($i = 0; $i <= InvoiceDocument::MAX_ITEMS; $i++) {
            $rows[] = array('description' => 'Line ' . $i, 'amount' => 1);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most');

        $this->build($rows);
    }

    // ─── The printed document ──────────────────────────────────────────────

    public function test_the_recipient_is_printed(): void
    {
        $built = $this->build(
            array(array('description' => 'Work', 'amount' => 10)),
            array('bill_to' => array('name' => 'Ada Lovelace', 'city' => 'George Town', 'email' => 'ada@example.test')),
        );

        $this->assertStringContainsString('Ada Lovelace', $built['body']);
        $this->assertStringContainsString('George Town', $built['body']);
        $this->assertStringContainsString('ada@example.test', $built['body']);
    }

    /**
     * The WordPress tool accepts `bill_to` and prints nothing. This one prints
     * it, and a string is as valid as a map.
     */
    public function test_a_string_recipient_is_printed(): void
    {
        $built = $this->build(
            array(array('description' => 'Work', 'amount' => 10)),
            array('bill_to' => 'Acme Ltd'),
        );

        $this->assertStringContainsString('Acme Ltd', $built['body']);
    }

    public function test_markup_in_a_description_is_escaped(): void
    {
        $built = $this->build(array(
            array('description' => '<script>alert(1)</script>', 'amount' => 1),
        ));

        $this->assertStringNotContainsString('<script>', $built['body']);
        $this->assertStringContainsString('&lt;script&gt;', $built['body']);
    }

    public function test_the_currency_and_totals_are_printed(): void
    {
        $built = $this->build(
            array(array('description' => 'Work', 'amount' => 60)),
            array('currency' => 'kyd', 'tax_rate' => 10),
        );

        $this->assertSame('KYD', $built['totals']['currency']);
        $this->assertStringContainsString('KYD 60.00', $built['body']);
        $this->assertStringContainsString('KYD 6.00', $built['body']);
        $this->assertStringContainsString('KYD 66.00', $built['body']);
    }

    public function test_the_items_reach_the_table(): void
    {
        $built = $this->build(array(
            array('description' => 'Design', 'quantity' => 2, 'rate' => 150),
        ));

        $this->assertStringContainsString('<table>', $built['body']);
        $this->assertStringContainsString('Design', $built['body']);
        $this->assertStringContainsString('150.00', $built['body']);
        $this->assertStringContainsString('300.00', $built['body']);
    }

    public function test_dates_default_to_today_and_thirty_days(): void
    {
        $built = $this->build(array(array('description' => 'Work', 'amount' => 1)));

        $this->assertSame(gmdate('Y-m-d'), $built['totals']['date']);
        $this->assertSame(gmdate('Y-m-d', (int) strtotime('+30 days')), $built['totals']['due_date']);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $extra
     *
     * @return array{body: string, totals: array<string, mixed>}
     */
    private function build(array $items, array $extra = array()): array
    {
        return $this->invoices->build(
            array('invoice_number' => 'INV-1', 'items' => $items) + $extra,
        );
    }
}
