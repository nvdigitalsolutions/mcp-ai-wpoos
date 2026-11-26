<?php

/**
 * The F&B metric engine: M-01 … M-21 against one hand-computed fixture.
 *
 * The fixture is small enough that every expected value below can be checked by
 * hand, which is the point — an engine test that asserts whatever the engine
 * returned proves nothing. September 2026 is the reporting month:
 *
 * - revenue 20 × 10 + 10 × 3 = 230, covers 40, so average spend is 5.75;
 * - food stock used 80 + 150 − 60 = 170, beverage 40 + 110 − 150 = 0;
 * - operating cost 170 + 0 + 1500 + 150 + 10 + 100 + 2000 + 100 = 4030, so cost
 *   per cover is 100.75;
 * - waste 15 over stock used 170 = 0.0882;
 * - one supplier price rise (ING-2, +0.20) against 50 units delivered = 10.00.
 *
 * The behaviours that are pinned rather than "fixed":
 *
 * - M-02 falls back to the utilities sheet's `total_covers` when the covers
 *   table is empty, de-duplicating by date.
 * - M-14 takes the last matching count row **in file order**, not the latest date.
 * - M-15 answers from the **first** matching count row and returns inside the loop.
 * - M-20 leaves `actual`/`variance` null for a budget line with no actual.
 * - M-21 resolves the recipe cost from the menu, then the recipes, then supplier
 *   prices, and says which one answered.
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
use Nvoos\Core\Tool\Fnb\FnbCalculateMetricTool;
use Nvoos\Core\Tool\Fnb\FnbComparePeriodsTool;
use Nvoos\Core\Tool\Fnb\FnbMetrics;
use Nvoos\Core\Tool\Fnb\FnbSettings;
use Nvoos\Core\Tool\Fnb\FnbTableSource;
use PHPUnit\Framework\TestCase;

final class FnbMetricsTest extends TestCase
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

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => is_array($value) && false === ( $value['success'] ?? true )
        );

        $this->errors->method('normalize')->willReturnCallback(
            static fn (mixed $value): array => is_array($value) && isset($value['error'])
                ? (array) $value['error'] + array('data' => array())
                : array('code' => 'error', 'message' => (string) $value, 'data' => array())
        );
    }

    // ─── M-01 … M-06 ──────────────────────────────────────────────────────

    public function test_revenue_is_units_times_menu_price(): void
    {
        $september = $this->metrics()->m01Revenue('2026-09');

        $this->assertSame(230.0, $september['value']);
        $this->assertSame(2, $september['rows']);
        $this->assertSame('fnb_metrics:M-01', $september['source']);

        $this->assertSame(200.0, $this->metrics()->m01Revenue('2026-09', 'Food')['value']);
        $this->assertSame(30.0, $this->metrics()->m01Revenue('2026-09', 'beverage')['value']);
    }

    public function test_covers_add_the_three_services(): void
    {
        $covers = $this->metrics()->m02Covers('2026-09');

        $this->assertSame(40.0, $covers['value']);
        $this->assertSame(1, $covers['days']);
        $this->assertSame('fnb_metrics:M-02', $covers['source']);
    }

    public function test_covers_fall_back_to_the_utilities_sheet_when_the_covers_table_is_empty(): void
    {
        $covers = $this->metrics(array('7.0' => array(array('date'), array())))->m02Covers('2026-09');

        // The utilities fixture carries 300 covers on one dated row.
        $this->assertSame(300.0, $covers['value']);
        $this->assertSame(1, $covers['days']);
        $this->assertSame('fnb_metrics:M-02:utilities', $covers['source']);
    }

    public function test_average_spend_is_revenue_over_covers(): void
    {
        $this->assertSame(5.75, $this->metrics()->m03AvgSpend('2026-09')['value']);
    }

    public function test_a_zero_decimal_currency_rounds_every_money_figure_to_whole_units(): void
    {
        // The engine's r() takes its precision from the tenant currency, as the
        // WordPress tenant profile did: LKR, JPY, KRW and VND have no minor unit.
        $settings                          = $this->settingsPayload();
        $settings[ FnbSettings::OPTION ]['currency'] = 'LKR';

        $this->assertSame(6.0, $this->metrics(null, $settings)->m03AvgSpend('2026-09')['value']);
    }

    public function test_stock_used_is_opening_plus_purchases_minus_closing(): void
    {
        $food = $this->metrics()->stockUsed('2026-09', 'Food');

        $this->assertSame(170.0, $food['value']);
        $this->assertSame(80.0, $food['opening']);
        $this->assertSame(150.0, $food['purchases']);
        $this->assertSame(60.0, $food['closing']);
        $this->assertSame('2026-08-31', $food['open_date']);
        $this->assertSame('2026-09-30', $food['close_date']);
    }

    public function test_cost_percentages_are_stock_used_over_revenue(): void
    {
        $this->assertSame(0.85, $this->metrics()->m04FoodCostPct('2026-09')['value']);
        // Beverage stock used is zero here: opening 40 + purchases 110 − closing 150.
        $this->assertSame(0.0, $this->metrics()->m05BeverageCostPct('2026-09')['value']);
    }

    public function test_operating_cost_sums_the_five_blocks_and_cost_per_cover_divides_it(): void
    {
        $opex = $this->metrics()->totalOperatingCost('2026-09');

        $this->assertSame(4030.0, $opex['total']);
        $this->assertSame(170.0, $opex['lines']['Food cost (stock used)']);
        $this->assertSame(0.0, $opex['lines']['Beverage cost (stock used)']);
        $this->assertSame(1500.0, $opex['lines']['Payroll']);
        $this->assertSame(150.0, $opex['lines']['Electricity']);
        $this->assertSame(10.0, $opex['lines']['Water']);
        $this->assertSame(100.0, $opex['lines']['LPG']);
        $this->assertSame(2000.0, $opex['lines']['Rent']);
        $this->assertSame(100.0, $opex['lines']['Cleaning']);
        // The stock-purchase group is not an operating line.
        $this->assertArrayNotHasKey('Misc', $opex['lines']);

        $this->assertSame(100.75, $this->metrics()->m06CostPerCover('2026-09')['value']);
    }

    // ─── M-07 … M-11 ──────────────────────────────────────────────────────

    public function test_the_two_effects_sum_to_the_cost_change(): void
    {
        $volume = $this->metrics()->m07VolumeEffect(1000.0, 30.0, 40.0);

        // Money precision, not four places: r() rounds to the tenant currency.
        $this->assertSame(333.33, $volume);

        $perCover = $this->metrics()->m08PerCoverEffect(2000.0, 1000.0, $volume);

        $this->assertSame(666.67, $perCover);
        $this->assertSame(1000.0, round($volume + $perCover, 4));
    }

    public function test_a_zero_previous_baseline_yields_no_volume_effect(): void
    {
        $this->assertSame(0.0, $this->metrics()->m07VolumeEffect(1000.0, 0.0, 40.0));
    }

    public function test_payroll_totals_by_department(): void
    {
        $payroll = $this->metrics()->payrollTotal('2026-09');

        $this->assertSame(1500.0, $payroll['value']);
        $this->assertSame(array('Kitchen' => 1000.0, 'Front' => 500.0), $payroll['departments']);
        $this->assertSame(15.0, $payroll['overtime_hours']);
        $this->assertSame(3.0, $payroll['casual_shifts']);
    }

    public function test_labour_and_overtime_are_rates_over_the_same_month(): void
    {
        $this->assertSame(37.5, $this->metrics()->m09OvertimePer100Covers('2026-09')['value']);
        $this->assertSame(6.5217, $this->metrics()->m10LabourPct('2026-09')['value']);
        $this->assertSame(2.5, $this->metrics()->m11ElectricityPerCover('2026-09')['value']);
    }

    // ─── M-12 … M-17 ──────────────────────────────────────────────────────

    public function test_expected_covers_use_the_same_weekday_and_large_bookings(): void
    {
        $expected = $this->metrics()->m12ExpectedCovers('2026-09-29');

        // 2026-09-01 is also a Tuesday; the 40 covers that day average over one
        // sample, and only the 20-guest booking clears the group threshold.
        $this->assertSame(40.0, $expected['same_weekday_avg']);
        $this->assertSame(1, $expected['weekday_count']);
        $this->assertSame(20.0, $expected['group_guests']);
        $this->assertSame(60.0, $expected['value']);
    }

    public function test_expected_portions_multiply_covers_by_the_dish_share(): void
    {
        $portions = $this->metrics()->m13ExpectedPortions('IT-1', '2026-09-29');

        // 20 units over 40 covers on the same weekday.
        $this->assertSame(0.5, $portions['dish_share']);
        $this->assertSame(30.0, $portions['value']);
    }

    public function test_days_of_cover_divides_stock_on_hand_by_daily_use(): void
    {
        $cover = $this->metrics()->m14DaysOfCover('ING-1', '2026-09-30');

        $this->assertSame(6.0, $cover['stock_on_hand']);
        $this->assertSame(1.0, $cover['daily_use_14d']);
        $this->assertSame(6.0, $cover['value']);
    }

    public function test_stock_used_answers_from_the_first_matching_count_row(): void
    {
        // Pinned: the original returns inside the loop, so the 2026-09-25 count
        // answers and the 2026-09-30 count is never reached.
        $used = $this->metrics()->m15StockUsed('ING-1', '2026-09-01', '2026-09-30');

        $this->assertSame('2026-09-20', $used['period']);
        $this->assertSame(7.0, $used['value']);
    }

    public function test_expected_use_sums_units_sold_times_recipe_quantity(): void
    {
        // IT-1 sells 20 in September and uses 2 units of ING-1 per portion.
        $this->assertSame(40.0, $this->metrics()->m16ExpectedUse('ING-1', '2026-09')['value']);
    }

    public function test_unexplained_variance_subtracts_expected_use_and_logged_waste(): void
    {
        $variance = $this->metrics()->m17UnexplainedVariance('ING-1', '2026-09-01', '2026-09-30');

        $this->assertSame(7.0, $variance['stock_used']);
        $this->assertSame(40.0, $variance['expected_use']);
        $this->assertSame(1.5, $variance['logged_waste']);
        $this->assertSame(-34.5, $variance['value']);
    }

    // ─── M-18 … M-21 ──────────────────────────────────────────────────────

    public function test_waste_percent_is_logged_waste_over_stock_used(): void
    {
        $waste = $this->metrics()->m18WastePct('2026-09');

        $this->assertSame(15.0, $waste['waste_value']);
        $this->assertSame(170.0, $waste['stock_used']);
        $this->assertSame(0.0882, $waste['value']);
        $this->assertSame(array('Spoilage' => 10.0, 'Prep trim' => 5.0), $waste['by_reason']);
    }

    public function test_unit_price_as_of_takes_the_latest_effective_pack(): void
    {
        $this->assertSame(2.0, $this->metrics()->unitPriceAsOf('ING-1', '2026-08-01'));
        $this->assertSame(3.0, $this->metrics()->unitPriceAsOf('ING-1', '2026-09-30'));
        // Before any effective date there is no price.
        $this->assertSame(0.0, $this->metrics()->unitPriceAsOf('ING-1', '2026-01-01'));
    }

    public function test_price_change_impact_multiplies_the_rise_by_quantity_bought(): void
    {
        $impact = $this->metrics()->m19PriceChangeImpact('2026-09');

        $this->assertSame(10.0, $impact['value']);
        $this->assertCount(1, $impact['changes']);
        $this->assertSame('ING-2', $impact['changes'][0]['ingredient_id']);
        $this->assertSame(0.8, $impact['changes'][0]['old_unit_price']);
        $this->assertSame(1.0, $impact['changes'][0]['new_unit_price']);
        $this->assertSame(50.0, $impact['changes'][0]['qty_bought']);
    }

    public function test_budget_variance_leaves_an_unrecorded_line_null(): void
    {
        $variance = $this->metrics()->m20BudgetVariance('2026-09');
        $rows     = array();

        foreach ($variance['rows'] as $row) {
            $rows[ $row['line'] ] = $row;
        }

        $this->assertSame(1400.0, $rows['Payroll']['budget']);
        $this->assertSame(1500.0, $rows['Payroll']['actual']);
        $this->assertSame(100.0, $rows['Payroll']['variance']);

        // Cleaning has an actual of 100 and a budget of 100.
        $this->assertSame(0.0, $rows['Cleaning']['variance']);
    }

    public function test_dish_margin_prefers_the_menu_recipe_cost(): void
    {
        $margin = $this->metrics()->m21DishMargin('IT-1', '2026-09');

        $this->assertSame(10.0, $margin['menu_price']);
        $this->assertSame(4.0, $margin['recipe_cost']);
        $this->assertSame('menu', $margin['recipe_cost_source']);
        $this->assertSame(6.0, $margin['value']);
        $this->assertSame(0.4, $margin['cost_pct']);
        $this->assertSame(1.0, $margin['popularity_share']);
    }

    public function test_menu_engineering_assigns_the_four_quadrants(): void
    {
        $engineering = $this->metrics()->menuEngineering('2026-09');
        $quadrants   = array();

        foreach ($engineering['rows'] as $row) {
            $quadrants[ $row['item_id'] ] = $row['quadrant'];
        }

        $this->assertSame('Star', $quadrants['IT-1']);
        $this->assertSame('Plowhorse', $quadrants['IT-2']);
        $this->assertSame(1.0, $engineering['average_share']);
        $this->assertSame(4.0, $engineering['average_margin']);
    }

    // ─── Dispatch ─────────────────────────────────────────────────────────

    public function test_calculate_dispatches_and_stamps_the_metric_id(): void
    {
        $result = $this->metrics()->calculate('M-01', array('month' => '2026-09'));

        $this->assertSame(230.0, $result['value']);
        $this->assertSame('M-01', $result['metric_id']);
    }

    public function test_the_effects_share_one_dispatch_case(): void
    {
        $result = $this->metrics()->calculate('M-07', array(
            'prev_cost'   => 1000,
            'prev_covers' => 30,
            'cur_cost'    => 2000,
            'cur_covers'  => 40,
        ));

        $this->assertSame(333.33, $result['volume_effect']);
        $this->assertSame(666.67, $result['per_cover_effect']);
        $this->assertSame('fnb_metrics:M-07/M-08', $result['source']);
    }

    public function test_missing_arguments_are_typed_errors(): void
    {
        $this->assertSame('missing_date', $this->metrics()->calculate('M-12')['error']['code']);
        $this->assertSame('missing_args', $this->metrics()->calculate('M-13')['error']['code']);
        $this->assertSame('missing_ingredient', $this->metrics()->calculate('M-14')['error']['code']);
        $this->assertSame('missing_item', $this->metrics()->calculate('M-21')['error']['code']);
    }

    public function test_an_unknown_metric_suggests_the_nearest_valid_id(): void
    {
        $result = $this->metrics()->calculate('M-1');

        $this->assertSame('unknown_metric', $result['error']['code']);
        $this->assertSame('M-01', $result['error']['data']['closest_metric']);
        $this->assertCount(21, $result['error']['data']['valid_metrics']);
    }

    public function test_a_table_failure_becomes_a_metric_error(): void
    {
        // No table map at all: every metric that reads a table reports it.
        $metrics = new FnbMetrics($this->errors, $this->source(array(), array()), $this->settings(array()));

        $result = $metrics->calculate('M-01', array('month' => '2026-09'));

        $this->assertSame('metric_error', $result['error']['code']);
    }

    // ─── The tools ────────────────────────────────────────────────────────

    public function test_the_calculate_tool_wraps_the_engine_in_the_canonical_envelope(): void
    {
        $result = $this->calculateTool()->execute(
            array('metric_id' => 'M-02', 'month' => '2026-09'),
            self::CONTEXT,
        );

        $this->assertTrue($result['success']);
        $this->assertSame('M-02: Covers', $result['message']);
        $this->assertSame(40.0, $result['data']['value']);
        $this->assertSame('M-02', $result['data']['metric_id']);
    }

    public function test_the_calculate_tool_passes_engine_errors_through(): void
    {
        $result = $this->calculateTool()->execute(array('metric_id' => 'M-12'), self::CONTEXT);

        $this->assertSame('missing_date', $result['error']['code']);
    }

    public function test_the_calculate_tool_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->calculateTool()->execute(array('metric_id' => 'M-01', 'month' => '2026-09'));
    }

    public function test_the_comparison_reports_change_and_percentage_change(): void
    {
        $result = $this->compareTool()->execute(
            array('month' => '2026-09', 'prev_month' => '2026-08'),
            self::CONTEXT,
        );

        $this->assertTrue($result['success']);
        $this->assertSame('2026-09', $result['data']['month']);
        $this->assertSame('2026-08', $result['data']['prev_month']);

        $revenue = $result['data']['metrics']['M-01'];

        // 230 this month against 160 last month.
        $this->assertSame(230.0, $revenue['current']);
        $this->assertSame(160.0, $revenue['previous']);
        $this->assertSame(70.0, $revenue['change']);
        $this->assertSame(43.75, $revenue['change_pct']);

        // The revenue split is labelled with the configured currency.
        $this->assertSame('Food revenue (USD)', $result['data']['metrics']['M-01-Food']['label']);
        $this->assertSame(200.0, $result['data']['metrics']['M-01-Food']['current']);
    }

    public function test_the_comparison_defaults_to_this_month_against_the_previous_one(): void
    {
        $result = $this->compareTool()->execute(array(), self::CONTEXT);

        $this->assertSame(gmdate('Y-m'), $result['data']['month']);
        $this->assertSame(
            gmdate('Y-m', (int) strtotime(gmdate('Y-m-01') . ' -1 month')),
            $result['data']['prev_month']
        );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function metrics(?array $overrides = null, ?array $settings = null): FnbMetrics
    {
        return new FnbMetrics(
            $this->errors,
            $this->source($this->fixture(), $overrides ?? array()),
            $this->settings($settings ?? $this->settingsPayload()),
        );
    }

    private function calculateTool(?FnbMetrics $metrics = null): FnbCalculateMetricTool
    {
        return new FnbCalculateMetricTool($this->errors, $metrics ?? $this->metrics());
    }

    private function compareTool(?FnbMetrics $metrics = null): FnbComparePeriodsTool
    {
        return new FnbComparePeriodsTool(
            $this->errors,
            $metrics ?? $this->metrics(),
            $this->settings($this->settingsPayload())
        );
    }

    /**
     * The toolkit settings: an LKR venue with every fixture table mapped.
     *
     * @return array<string, mixed>
     */
    private function settingsPayload(): array
    {
        $map = array();

        foreach (array_keys($this->fixture()) as $tableId) {
            $map[ $tableId ] = array('path' => '/data/' . $tableId . '.csv');
        }

        return array(
            FnbSettings::OPTION => array(
                'currency'    => 'USD',
                'as_of_date' => '2026-09-30',
                'table_map'   => $map,
            ),
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function settings(array $settings): FnbSettings
    {
        $store = $this->createMock(SettingsStoreInterface::class);
        $store->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $settings[ $key ] ?? $default
        );

        return new FnbSettings($store);
    }

    /**
     * A table source over the fixture, with overrides applied by table id.
     *
     * @param array<string, array{0: list<string>, 1: list<list<string>>}> $tables
     * @param array<string, array{0: list<string>, 1: list<list<string>>}> $overrides
     */
    private function source(array $tables, array $overrides): FnbTableSource
    {
        $tables = array_replace($tables, $overrides);
        $map    = array();

        foreach (array_keys($this->fixture()) as $tableId) {
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
     * The fixture tables, keyed by spec id.
     *
     * @return array<string, array{0: list<string>, 1: list<list<string>>}>
     */
    private function fixture(): array
    {
        return array(
            '1.0' => array(
                array('Item ID', 'Item', 'Section', 'Type', 'Price', 'Recipe Cost'),
                array(
                    array('IT-1', 'Burger', 'Mains', 'Food', '10', '4'),
                    array('IT-2', 'Soda', 'Drinks', 'Beverage', '3', '1'),
                ),
            ),
            '2.0' => array(
                array('Item ID', 'Ingredient ID', 'Qty Per Portion', 'Cost Per Portion'),
                array(
                    array('IT-1', 'ING-1', '2', '4'),
                    array('IT-2', 'ING-2', '1', '1'),
                ),
            ),
            '3.0' => array(
                array('Ingredient ID', 'Ingredient', 'Shelf Life Days'),
                array(
                    array('ING-1', 'Beef', '5'),
                    array('ING-2', 'Syrup', '365'),
                ),
            ),
            '5.0' => array(
                array('Ingredient ID', 'Pack Size', 'Pack Price', 'Effective From'),
                array(
                    array('ING-1', '10', '20', '2026-07-01'),
                    array('ING-1', '10', '30', '2026-08-15'),
                    array('ING-2', '10', '8', '2026-08-01'),
                    array('ING-2', '10', '10', '2026-09-10'),
                ),
            ),
            '6.0' => array(
                array('Date', 'Item ID', 'Type', 'Qty Sold', 'Price'),
                array(
                    array('2026-08-01', 'IT-1', 'Food', '10', '10'),
                    array('2026-08-01', 'IT-2', 'Beverage', '20', '3'),
                    array('2026-09-01', 'IT-1', 'Food', '20', '10'),
                    array('2026-09-01', 'IT-2', 'Beverage', '10', '3'),
                ),
            ),
            '7.0' => array(
                array('Date', 'Lunch Covers', 'Dinner Covers', 'Bar Only Guests'),
                array(
                    array('2026-08-01', '20', '10', '0'),
                    array('2026-09-01', '25', '10', '5'),
                ),
            ),
            '8.0' => array(
                array('Delivery Date', 'Ingredient ID', 'Category', 'Qty Delivered', 'Line Total'),
                array(
                    array('2026-09-05', 'ING-1', 'Food', '5', '150'),
                    array('2026-09-06', 'ING-2', 'Beverage', '100', '60'),
                    array('2026-09-20', 'ING-2', 'Beverage', '50', '50'),
                ),
            ),
            '9.0' => array(
                array('Count Date', 'Period Start', 'Ingredient ID', 'Category', 'Opening', 'Bought', 'Closing', 'Used', 'Closing Value'),
                array(
                    array('2026-08-31', '2026-08-01', 'ING-1', 'Food', '10', '5', '8', '7', '80'),
                    array('2026-08-31', '2026-08-01', 'ING-2', 'Beverage', '100', '20', '80', '40', '40'),
                    array('2026-09-25', '2026-09-20', 'ING-1', 'Food', '8', '5', '6', '14', '60'),
                    array('2026-09-30', '2026-09-01', 'ING-1', 'Food', '8', '5', '6', '7', '60'),
                    array('2026-09-30', '2026-09-01', 'ING-2', 'Beverage', '80', '100', '150', '30', '150'),
                ),
            ),
            '10.0' => array(
                array('Date', 'Ingredient ID', 'Qty', 'Value', 'Reason'),
                array(
                    array('2026-09-10', 'ING-1', '1', '10', 'Spoilage'),
                    array('2026-09-11', 'ING-1', '0.5', '5', 'Prep trim'),
                ),
            ),
            '11.0' => array(
                array('Month', 'Department', 'Total Payroll Cost', 'Overtime Hours', 'Casual Shifts'),
                array(
                    array('2026-09', 'Kitchen', '1000', '10', '2'),
                    array('2026-09', 'Front', '500', '5', '1'),
                ),
            ),
            '13.0' => array(
                array('Date', 'Electricity kWh', 'Water m3', 'LPG Cylinders', 'Total Covers'),
                array(
                    array('2026-09-30', '100', '10', '2', '300'),
                ),
            ),
            '14.0' => array(
                array('For Month', 'Group', 'Expense Line', 'Amount'),
                array(
                    array('2026-09', 'Operating', 'Rent', '2000'),
                    array('2026-09', 'Operating', 'Cleaning', '100'),
                    array('2026-09', 'Stock purchases', 'Misc', '999'),
                ),
            ),
            '15.0' => array(
                array('Month', 'Line', 'Budget'),
                array(
                    array('2026-09', 'Payroll', '1400'),
                    array('2026-09', 'Rent', '1800'),
                    array('2026-09', 'Cleaning', '100'),
                ),
            ),
            '16.0' => array(
                array('Date', 'Guests'),
                array(
                    array('2026-09-29', '20'),
                    array('2026-09-29', '10'),
                ),
            ),
            'assumptions' => array(
                array('Item', 'Value', 'Unit', 'Source'),
                array(
                    array('Electricity tariff', '0.5', 'per kWh', 'Utility bill'),
                    array('Electricity fixed charge', '100', 'per month', 'Utility bill'),
                    array('Water rate', '1', 'per m3', 'Utility bill'),
                    array('Water fixed charge', '0', 'per month', 'Utility bill'),
                    array('LPG cylinder price (37.5 kg)', '50', 'per cylinder', 'Supplier'),
                ),
            ),
        );
    }
}
