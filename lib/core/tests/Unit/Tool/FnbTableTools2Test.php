<?php

/**
 * The three F&B tools that read a table directly: days of cover, weekend
 * demand, and the duplicate invoice scan.
 *
 * Each adds one thing the engine cannot know on its own — a shelf-life flag, a
 * three-day window with supplier cut-offs, and a vendor/invoice/amount triple —
 * so the assertions are about that addition rather than about arithmetic the
 * engine test already pins.
 *
 * The behaviours worth naming:
 *
 * - **The over-ordering cap is the ingredient's shelf life or fourteen days**,
 *   and it is a **string** flag or `''`, not a boolean.
 * - **Weekend demand's default Friday is derived**, and a malformed date
 *   substitutes the default rather than failing.
 * - **A duplicate invoice is a rounded amount**, so 1,250.40 and 1,250.00 are
 *   the same claim, and the stock-purchase line is never "missing a budget
 *   line" because the budget deliberately omits it.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\TabularReaderInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\Fnb\FnbDaysOfCoverTool;
use Nvoos\Core\Tool\Fnb\FnbDuplicateInvoiceScanTool;
use Nvoos\Core\Tool\Fnb\FnbMetrics;
use Nvoos\Core\Tool\Fnb\FnbSettings;
use Nvoos\Core\Tool\Fnb\FnbTableSource;
use Nvoos\Core\Tool\Fnb\FnbWeekendDemandTool;
use PHPUnit\Framework\TestCase;

final class FnbTableTools2Test extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => is_array($value) && false === ( $value['success'] ?? true )
        );

        $this->errors->method('normalize')->willReturnCallback(
            static fn (mixed $value): array => is_array($value) && isset($value['error'])
                ? (array) $value['error'] + array('data' => array())
                : array('code' => 'error', 'message' => (string) $value, 'data' => array())
        );
    }

    // ─── fnb_days_of_cover ────────────────────────────────────────────────

    public function test_days_past_the_shelf_life_raise_the_over_ordering_flag(): void
    {
        $result = $this->days()->execute(
            array('ingredient_id' => 'ING-1', 'as_of' => '2026-09-30'),
            self::CONTEXT,
        );

        $this->assertTrue($result['success']);
        $this->assertSame(6.0, $result['data']['value']);
        $this->assertSame(5.0, $result['data']['shelf_life_days']);
        $this->assertStringContainsString('shelf life (5 days)', $result['data']['over_ordering_flag']);
        $this->assertStringContainsString('6.0 days of cover', $result['data']['over_ordering_flag']);
    }

    public function test_cover_inside_a_long_shelf_life_is_not_flagged(): void
    {
        $result = $this->days()->execute(array('ingredient_id' => 'ING-2', 'as_of' => '2026-09-30'), self::CONTEXT);

        $this->assertSame(100.0, $result['data']['value']);
        $this->assertSame(365.0, $result['data']['shelf_life_days']);
        $this->assertSame('', $result['data']['over_ordering_flag']);
    }

    public function test_an_ingredient_with_no_shelf_life_uses_the_fourteen_day_cap(): void
    {
        // ING-3 has counts but no ingredients row, so the cap is long-life.
        $result = $this->days()->execute(array('ingredient_id' => 'ING-3', 'as_of' => '2026-09-30'), self::CONTEXT);

        $this->assertSame(0.0, $result['data']['shelf_life_days']);
        $this->assertStringContainsString('14 days (long-life cap)', $result['data']['over_ordering_flag']);
    }

    public function test_a_missing_ingredient_id_is_refused(): void
    {
        $result = $this->days()->execute(array(), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_days_of_cover_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->days()->execute(array('ingredient_id' => 'ING-1'));
    }

    // ─── fnb_weekend_demand ───────────────────────────────────────────────

    public function test_weekend_demand_spans_the_friday_and_two_days(): void
    {
        $result = $this->weekend()->execute(array('friday' => '2026-09-25'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('2026-09-25', $result['data']['weekend']['friday']);
        $this->assertSame('2026-09-27', $result['data']['weekend']['sunday']);
        $this->assertSame(
            array('2026-09-25', '2026-09-26', '2026-09-27'),
            array_keys($result['data']['per_day'])
        );

        foreach ($result['data']['per_day'] as $day) {
            $this->assertArrayHasKey('value', $day);
            $this->assertSame('fnb_metrics:M-12', $day['source']);
        }
    }

    public function test_the_supplier_cut_offs_are_reported(): void
    {
        $result = $this->weekend()->execute(array('friday' => '2026-09-25'), self::CONTEXT);

        $this->assertCount(1, $result['data']['supplier_cutoffs']);
        $this->assertSame('SUP-1', $result['data']['supplier_cutoffs'][0]['supplier_id']);
        $this->assertSame('14:00', $result['data']['supplier_cutoffs'][0]['order_cutoff']);
        $this->assertSame('2 days', $result['data']['supplier_cutoffs'][0]['lead_time']);
        $this->assertStringContainsString('R-03', $result['data']['report_hint']);
    }

    public function test_the_default_friday_is_the_next_friday(): void
    {
        $result = $this->weekend()->execute(array(), self::CONTEXT);
        $friday = $result['data']['weekend']['friday'];

        $this->assertSame('5', gmdate('N', (int) strtotime($friday . ' 12:00:00 UTC')));
        $this->assertGreaterThanOrEqual(gmdate('Y-m-d'), $friday);
    }

    public function test_a_malformed_friday_falls_back_to_the_default(): void
    {
        $result = $this->weekend()->execute(array('friday' => 'next friday'), self::CONTEXT);

        $this->assertSame('5', gmdate('N', (int) strtotime($result['data']['weekend']['friday'] . ' 12:00:00 UTC')));
    }

    public function test_weekend_demand_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->weekend()->execute(array('friday' => '2026-09-25'));
    }

    // ─── fnb_duplicate_invoice_scan ───────────────────────────────────────

    public function test_a_repeated_vendor_invoice_and_amount_is_flagged(): void
    {
        $result = $this->scan()->execute(array(), self::CONTEXT);

        $duplicates = $result['data']['possible_duplicates'];

        $this->assertCount(1, $duplicates);
        $this->assertSame('Acme Supplies', $duplicates[0]['vendor']);
        $this->assertSame('INV-7', $duplicates[0]['invoice']);
        $this->assertSame(1250.0, $duplicates[0]['amount']);
    }

    public function test_the_amount_is_rounded_before_it_is_compared(): void
    {
        // 1250.40 and 1250.00 collide once rounded; 900.00 does not.
        $result = $this->scan()->execute(array(), self::CONTEXT);

        $this->assertCount(1, $result['data']['possible_duplicates']);
    }

    public function test_a_line_without_a_budget_line_is_flagged_but_the_stock_line_is_not(): void
    {
        $result = $this->scan()->execute(array(), self::CONTEXT);

        $flagged = array_column($result['data']['no_budget_line'], 'expense_line');

        $this->assertContains('Consulting', $flagged);
        $this->assertNotContains('Rent', $flagged);
        $this->assertNotContains('Food and beverage stock', $flagged);
        $this->assertSame('Flags only — verify before concluding.', $result['data']['note']);
    }

    public function test_the_month_filter_narrows_the_scan(): void
    {
        $result = $this->scan()->execute(array('month' => '2026-08'), self::CONTEXT);

        $this->assertSame('2026-08', $result['data']['month']);
        $this->assertSame(array(), $result['data']['possible_duplicates']);
    }

    public function test_an_unreadable_ledger_is_a_typed_error(): void
    {
        $result = $this->scan(array())->execute(array(), self::CONTEXT);

        $this->assertSame('table_not_configured', $result['error']['code']);
    }

    public function test_duplicate_scan_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->scan()->execute(array());
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function days(?FnbTableSource $tables = null): FnbDaysOfCoverTool
    {
        $source = $tables ?? $this->source();

        return new FnbDaysOfCoverTool($this->errors, $this->metrics($source), $source);
    }

    private function weekend(?FnbTableSource $tables = null): FnbWeekendDemandTool
    {
        $source = $tables ?? $this->source();

        return new FnbWeekendDemandTool($this->errors, $this->metrics($source), $source);
    }

    private function scan(?array $tables = null): FnbDuplicateInvoiceScanTool
    {
        return new FnbDuplicateInvoiceScanTool($this->errors, $this->source($tables));
    }

    private function metrics(FnbTableSource $source): FnbMetrics
    {
        return new FnbMetrics($this->errors, $source, $this->settings());
    }

    private function settings(): FnbSettings
    {
        $store = $this->createMock(SettingsStoreInterface::class);
        $store->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => FnbSettings::OPTION === $key
                ? array('currency' => 'USD', 'as_of_date' => '2026-09-30')
                : $default
        );

        return new FnbSettings($store);
    }

    /**
     * A source over the tables, or over none when `$tables` is an empty array.
     *
     * @param array<string, array{0: list<string>, 1: list<list<string>>}>|null $tables
     */
    private function source(?array $tables = null): FnbTableSource
    {
        $tables ??= $this->fixture();

        $map = array();

        foreach (array_keys($tables) as $tableId) {
            $map[ $tableId ] = array('path' => '/data/' . $tableId . '.csv');
        }

        $store = $this->createMock(SettingsStoreInterface::class);
        $store->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => FnbSettings::OPTION === $key
                ? array('table_map' => $map)
                : $default
        );

        $reader = new class ($tables) implements TabularReaderInterface {
            /**
             * @param array<string, array{0: list<string>, 1: list<list<string>>}> $tables
             */
            public function __construct(private readonly array $tables)
            {
            }

            public function read(string $localPath, array $options = array()): array
            {
                $tableId = basename($localPath, '.csv');

                if (! isset($this->tables[ $tableId ])) {
                    throw new \RuntimeException('No fixture for ' . $localPath);
                }

                return array(
                    'columns' => $this->tables[ $tableId ][0],
                    'rows'    => $this->tables[ $tableId ][1],
                    'meta'    => array(),
                );
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getSupportedFormats(): array
            {
                return array('csv');
            }
        };

        return new FnbTableSource($this->errors, $reader, $store);
    }

    /**
     * @return array<string, array{0: list<string>, 1: list<list<string>>}>
     */
    private function fixture(): array
    {
        return array(
            '3.0' => array(
                array('Ingredient ID', 'Ingredient', 'Shelf Life Days'),
                array(
                    array('ING-1', 'Beef', '5'),
                    array('ING-2', 'Syrup', '365'),
                ),
            ),
            '4.0' => array(
                array('Supplier ID', 'Supplier', 'Order Cutoff', 'Lead Time'),
                array(
                    array('SUP-1', 'Acme Foods', '14:00', '2 days'),
                ),
            ),
            '9.0' => array(
                array('Count Date', 'Period Start', 'Ingredient ID', 'Category', 'Opening', 'Bought', 'Closing', 'Used', 'Closing Value'),
                array(
                    array('2026-09-30', '2026-09-20', 'ING-1', 'Food', '8', '5', '6', '14', '60'),
                    array('2026-09-30', '2026-09-20', 'ING-2', 'Beverage', '80', '100', '100', '14', '100'),
                    array('2026-09-30', '2026-09-20', 'ING-3', 'Food', '1400', '0', '1400', '14', '1400'),
                ),
            ),
            '7.0' => array(
                array('Date', 'Lunch Covers', 'Dinner Covers', 'Bar Only Guests'),
                array(
                    array('2026-09-25', '20', '10', '0'),
                ),
            ),
            '14.0' => array(
                array('For Month', 'Vendor', 'Vendor Invoice', 'Expense Line', 'Amount'),
                array(
                    array('2026-09', 'Acme Supplies', 'INV-7', 'Rent', '1250.40'),
                    array('2026-09', 'Acme Supplies', 'INV-7', 'Rent', '1250.00'),
                    array('2026-09', 'Acme Supplies', 'INV-8', 'Rent', '900'),
                    array('2026-09', 'Beta Ltd', 'INV-1', 'Consulting', '700'),
                    array('2026-09', 'Beta Ltd', 'INV-2', 'Food and beverage stock', '400'),
                    array('2026-08', 'Acme Supplies', 'INV-9', 'Rent', '1000'),
                ),
            ),
            '15.0' => array(
                array('Month', 'Line', 'Budget'),
                array(
                    array('2026-09', 'Rent', '1800'),
                    array('2026-09', 'Payroll', '1400'),
                ),
            ),
        );
    }
}
