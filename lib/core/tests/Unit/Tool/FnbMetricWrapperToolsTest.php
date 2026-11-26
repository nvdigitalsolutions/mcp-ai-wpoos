<?php

/**
 * The ten F&B metric wrappers.
 *
 * Almost every one is argument handling over an engine that already answers its
 * metric, so these tests assert the two things the wrapper owns: **what it
 * refuses** and **the payload it assembles**. The arithmetic is
 * `FnbMetricsTest`'s job and is not repeated here; the numbers below are read
 * from the same fixture so a drift in either shows up.
 *
 * The behaviours that are not obvious from the code:
 *
 * - `fnb_dish_margin` switches shape on `item_id`; without one it needs a month.
 * - `fnb_stock_used` and `fnb_stock_variance` merge the metric rather than
 *   rebuild it, so a miss carries no `opening`/`bought`/`closing`.
 * - `fnb_budget_variance` answers an empty success when the budget sheet cannot
 *   be read — the WordPress `is_wp_error()` check on the result is dead, and
 *   that is pinned.
 * - `fnb_variance_split` lists this month's lines only, and a line new this
 *   month is compared against zero.
 * - `fnb_labour_analysis` returns a **sentence** for `justified`, and a
 *   zero-cover previous month answers "exceeds".
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\Fnb\FnbBudgetVarianceTool;
use Nvoos\Core\Tool\Fnb\FnbDishMarginTool;
use Nvoos\Core\Tool\Fnb\FnbExpectedUseTool;
use Nvoos\Core\Tool\Fnb\FnbLabourAnalysisTool;
use Nvoos\Core\Tool\Fnb\FnbMetrics;
use Nvoos\Core\Tool\Fnb\FnbPriceChangeImpactTool;
use Nvoos\Core\Tool\Fnb\FnbSettings;
use Nvoos\Core\Tool\Fnb\FnbStockUsedTool;
use Nvoos\Core\Tool\Fnb\FnbStockVarianceTool;
use Nvoos\Core\Tool\Fnb\FnbTableSource;
use Nvoos\Core\Tool\Fnb\FnbUtilitiesPerCoverTool;
use Nvoos\Core\Tool\Fnb\FnbVarianceSplitTool;
use Nvoos\Core\Tool\Fnb\FnbWasteAnalysisTool;
use PHPUnit\Framework\TestCase;

final class FnbMetricWrapperToolsTest extends TestCase
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

    // ─── fnb_dish_margin ──────────────────────────────────────────────────

    public function test_a_single_dish_returns_its_margin(): void
    {
        $result = $this->invoke($this->dishMargin(), array('item_id' => 'IT-1', 'month' => '2026-09'));

        $this->assertTrue($result['success']);
        $this->assertSame('IT-1', $result['data']['item_id']);
        $this->assertSame(6.0, $result['data']['value']);
        $this->assertSame('menu', $result['data']['recipe_cost_source']);
        $this->assertSame(0.4, $result['data']['cost_pct']);
    }

    public function test_without_an_item_the_matrix_needs_a_month(): void
    {
        $result = $this->invoke($this->dishMargin(), array('item_id' => 'IT-1'));

        // An item with no month is allowed: M-21 answers without popularity.
        $this->assertTrue($result['success']);
        $this->assertSame(0.0, $result['data']['popularity_share']);

        $refused = $this->invoke($this->dishMargin(), array());

        $this->assertSame('validation_failed', $refused['error']['code']);
    }

    public function test_the_matrix_returns_every_item_with_its_quadrant(): void
    {
        $result = $this->invoke($this->dishMargin(), array('month' => '2026-09'));

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']['rows']);
        $this->assertSame(4.0, $result['data']['average_margin']);
        $this->assertSame('fnb_metrics:menu_engineering', $result['data']['source']);
    }

    // ─── fnb_price_change_impact ──────────────────────────────────────────

    public function test_the_price_change_impact_names_the_rise(): void
    {
        $result = $this->invoke($this->priceChange(), array('month' => '2026-09'));

        $this->assertSame(10.0, $result['data']['value']);
        $this->assertCount(1, $result['data']['changes']);
        $this->assertSame('ING-2', $result['data']['changes'][0]['ingredient_id']);
    }

    public function test_the_price_change_impact_requires_a_month(): void
    {
        $result = $this->invoke($this->priceChange(), array('month' => 'September'));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── fnb_stock_used / fnb_stock_variance ──────────────────────────────

    public function test_stock_used_merges_the_metric_so_a_hit_carries_the_breakdown(): void
    {
        $result = $this->invoke($this->stockUsed(), array(
            'ingredient_id' => 'ING-1',
            'from'          => '2026-09-01',
            'to'            => '2026-09-30',
        ));

        $this->assertSame('ING-1', $result['data']['ingredient_id']);
        $this->assertSame(7.0, $result['data']['value']);
        $this->assertSame(8.0, $result['data']['opening']);
        $this->assertSame(5.0, $result['data']['bought']);
        $this->assertSame(6.0, $result['data']['closing']);
        $this->assertSame('2026-09-20', $result['data']['period']);
    }

    public function test_stock_used_without_a_matching_row_carries_only_the_value(): void
    {
        $result = $this->invoke($this->stockUsed(), array('ingredient_id' => 'ING-9'));

        $this->assertSame(0.0, $result['data']['value']);
        $this->assertArrayNotHasKey('opening', $result['data']);
    }

    public function test_stock_variance_subtracts_expected_use_and_waste(): void
    {
        $result = $this->invoke($this->stockVariance(), array(
            'ingredient_id' => 'ING-1',
            'from'          => '2026-09-01',
            'to'            => '2026-09-30',
        ));

        $this->assertSame(-34.5, $result['data']['value']);
        $this->assertSame(7.0, $result['data']['stock_used']);
        $this->assertSame(40.0, $result['data']['expected_use']);
        $this->assertSame(1.5, $result['data']['logged_waste']);
    }

    public function test_the_stock_tools_refuse_a_missing_ingredient(): void
    {
        $this->assertSame('validation_failed', $this->invoke($this->stockUsed(), array())['error']['code']);
        $this->assertSame('validation_failed', $this->invoke($this->stockVariance(), array())['error']['code']);
    }

    // ─── fnb_expected_use ─────────────────────────────────────────────────

    public function test_expected_use_multiplies_sales_by_the_recipe_quantity(): void
    {
        $result = $this->invoke($this->expectedUse(), array('ingredient_id' => 'ING-1', 'month' => '2026-09'));

        $this->assertSame(40.0, $result['data']['value']);
        $this->assertSame('2026-09', $result['data']['month']);
    }

    public function test_expected_use_needs_both_arguments(): void
    {
        $this->assertSame(
            'validation_failed',
            $this->invoke($this->expectedUse(), array('ingredient_id' => 'ING-1'))['error']['code']
        );
    }

    // ─── fnb_waste_analysis ───────────────────────────────────────────────

    public function test_waste_analysis_reports_the_percentage_and_the_reasons(): void
    {
        $result = $this->invoke($this->waste(), array('month' => '2026-09'));

        $this->assertSame(0.0882, $result['data']['value']);
        $this->assertSame(15.0, $result['data']['waste_value']);
        $this->assertSame(array('Spoilage' => 10.0, 'Prep trim' => 5.0), $result['data']['by_reason']);
        $this->assertNull($result['data']['month_on_month']);
    }

    public function test_a_month_on_month_comparison_with_a_zero_baseline_has_no_percentage(): void
    {
        // August has no logged waste in the fixture.
        $result = $this->invoke($this->waste(), array('month' => '2026-09', 'prev_month' => '2026-08'));

        $this->assertSame(0.0, $result['data']['month_on_month']['prev_value']);
        $this->assertSame(15.0, $result['data']['month_on_month']['change']);
        $this->assertNull($result['data']['month_on_month']['change_pct']);
    }

    // ─── fnb_budget_variance ──────────────────────────────────────────────

    public function test_budget_variance_sorts_the_largest_gap_first(): void
    {
        $result = $this->invoke($this->budgetVariance(), array('month' => '2026-09'));

        $this->assertSame('Rent', $result['data']['rows'][0]['line']);
        $this->assertSame(200.0, $result['data']['rows'][0]['variance']);
        $this->assertSame('Payroll', $result['data']['rows'][1]['line']);
        $this->assertSame('fnb_budget_variance', $result['data']['source']);
    }

    public function test_an_unreadable_budget_sheet_is_an_empty_success(): void
    {
        // Pinned: the WordPress is_wp_error() check is dead, because the metric
        // returns an array with an `error` key rather than a WP_Error.
        $result = $this->invoke($this->budgetVariance(array()), array('month' => '2026-09'));

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['rows']);
    }

    // ─── fnb_variance_split ───────────────────────────────────────────────

    public function test_the_split_reports_both_effects_per_line(): void
    {
        $result = $this->invoke($this->varianceSplit(), array('month' => '2026-09', 'prev_month' => '2026-08'));

        $this->assertSame(4050.0, $result['data']['total_change']);
        $this->assertSame(40.0, $result['data']['covers']);
        $this->assertSame(30.0, $result['data']['prev_covers']);

        $payroll = null;

        foreach ($result['data']['lines'] as $line) {
            if ('Payroll' === $line['line']) {
                $payroll = $line;
            }
        }

        $this->assertNotNull($payroll);
        // New this month, so the whole 1500 is per-cover.
        $this->assertSame(1500.0, $payroll['current']);
        $this->assertSame(0.0, $payroll['previous']);
        $this->assertSame(0.0, $payroll['volume_effect']);
        $this->assertSame(1500.0, $payroll['per_cover_effect']);
    }

    public function test_the_split_needs_both_months(): void
    {
        $result = $this->invoke($this->varianceSplit(), array('month' => '2026-09'));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── fnb_labour_analysis ──────────────────────────────────────────────

    public function test_labour_analysis_reports_payroll_and_the_two_rates(): void
    {
        $result = $this->invoke($this->labour(), array('month' => '2026-09'));

        $this->assertSame(1500.0, $result['data']['total_payroll']);
        $this->assertSame(37.5, $result['data']['m09_overtime_per_100']);
        $this->assertSame(6.5217, $result['data']['m10_labour_pct']);
        $this->assertNull($result['data']['comparison']);
    }

    public function test_overtime_growth_beyond_covers_growth_is_flagged_in_words(): void
    {
        // August has covers (30) but no payroll, so overtime grew 15 hours while
        // covers grew 10.
        $result = $this->invoke($this->labour(), array('month' => '2026-09', 'prev_month' => '2026-08'));

        $this->assertSame(15.0, $result['data']['comparison']['overtime_change']);
        $this->assertSame(10.0, $result['data']['comparison']['covers_change']);
        $this->assertStringContainsString('exceeds', $result['data']['comparison']['justified']);
        $this->assertStringContainsString('R-09', $result['data']['comparison']['justified']);
    }

    // ─── fnb_utilities_per_cover ──────────────────────────────────────────

    public function test_utilities_report_meters_costed_lines_and_per_cover_use(): void
    {
        $result = $this->invoke($this->utilities(), array('month' => '2026-09'));

        $this->assertSame(100.0, $result['data']['meters']['electricity_kwh']);
        $this->assertSame(150.0, $result['data']['costed']['Electricity']);
        $this->assertSame(2.5, $result['data']['per_cover']['electricity_kwh']);
        $this->assertSame(0.25, $result['data']['per_cover']['water_m3']);
        $this->assertSame(0.05, $result['data']['per_cover']['lpg_cylinders']);
        $this->assertSame(40.0, $result['data']['covers']);
        $this->assertSame('Assumptions table', $result['data']['assumptions_source']);
    }

    // ─── One authorization assertion for the family ───────────────────────

    public function test_every_wrapper_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->priceChange()->execute(array('month' => '2026-09'));
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function invoke(object $tool, array $arguments): array
    {
        /** @var array<string, mixed> $result */
        $result = $tool->execute($arguments, self::CONTEXT);

        return $result;
    }

    /**
     * The metric engine over the shared fixture, or over none.
     *
     * @param array<string, array{0: list<string>, 1: list<list<string>>}>|null $tables
     */
    private function metrics(?array $tables = null): FnbMetrics
    {
        return new FnbMetrics($this->errors, $this->source($tables), $this->settings());
    }

    /**
     * @param array<string, array{0: list<string>, 1: list<list<string>>}>|null $tables
     */
    private function source(?array $tables = null): FnbTableSource
    {
        $tables ??= FnbFixture::tables();

        $store = $this->createMock(SettingsStoreInterface::class);
        $store->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => FnbSettings::OPTION === $key
                ? array('table_map' => FnbFixture::map($tables))
                : $default
        );

        return new FnbTableSource($this->errors, FnbFixture::reader($tables), $store);
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

    /** @param array<string, array{0: list<string>, 1: list<list<string>>}>|null $tables */
    private function dishMargin(?array $tables = null): FnbDishMarginTool
    {
        return new FnbDishMarginTool($this->errors, $this->metrics($tables));
    }

    /** @param array<string, array{0: list<string>, 1: list<list<string>>}>|null $tables */
    private function priceChange(?array $tables = null): FnbPriceChangeImpactTool
    {
        return new FnbPriceChangeImpactTool($this->errors, $this->metrics($tables));
    }

    /** @param array<string, array{0: list<string>, 1: list<list<string>>}>|null $tables */
    private function stockUsed(?array $tables = null): FnbStockUsedTool
    {
        return new FnbStockUsedTool($this->errors, $this->metrics($tables));
    }

    /** @param array<string, array{0: list<string>, 1: list<list<string>>}>|null $tables */
    private function stockVariance(?array $tables = null): FnbStockVarianceTool
    {
        return new FnbStockVarianceTool($this->errors, $this->metrics($tables));
    }

    /** @param array<string, array{0: list<string>, 1: list<list<string>>}>|null $tables */
    private function expectedUse(?array $tables = null): FnbExpectedUseTool
    {
        return new FnbExpectedUseTool($this->errors, $this->metrics($tables));
    }

    /** @param array<string, array{0: list<string>, 1: list<list<string>>}>|null $tables */
    private function waste(?array $tables = null): FnbWasteAnalysisTool
    {
        return new FnbWasteAnalysisTool($this->errors, $this->metrics($tables));
    }

    /** @param array<string, array{0: list<string>, 1: list<list<string>>}>|null $tables */
    private function budgetVariance(?array $tables = null): FnbBudgetVarianceTool
    {
        return new FnbBudgetVarianceTool($this->errors, $this->metrics($tables));
    }

    /** @param array<string, array{0: list<string>, 1: list<list<string>>}>|null $tables */
    private function varianceSplit(?array $tables = null): FnbVarianceSplitTool
    {
        return new FnbVarianceSplitTool($this->errors, $this->metrics($tables));
    }

    /** @param array<string, array{0: list<string>, 1: list<list<string>>}>|null $tables */
    private function labour(?array $tables = null): FnbLabourAnalysisTool
    {
        return new FnbLabourAnalysisTool($this->errors, $this->metrics($tables));
    }

    /** @param array<string, array{0: list<string>, 1: list<list<string>>}>|null $tables */
    private function utilities(?array $tables = null): FnbUtilitiesPerCoverTool
    {
        return new FnbUtilitiesPerCoverTool($this->errors, $this->metrics($tables));
    }
}
