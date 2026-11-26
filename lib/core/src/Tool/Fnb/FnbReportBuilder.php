<?php

/**
 * The F&B Report library (R-01…R-14), rendered from the metric engine.
 *
 * Port of `WP_MCP_AI_Fnb_Report_Builder` (Proposal 164 section 164.75). This
 * class only assembles sections: every figure comes from {@see FnbMetrics}, and
 * every table read goes through {@see FnbTableSource}, which is the same source
 * the metrics use.
 *
 * ## What is pinned rather than corrected
 *
 * - **`money()` does not round to whole units.** The WordPress docblock says it
 *   does; the code delegates to the tenant profile, whose decimals follow the
 *   currency (zero for LKR/JPY/KRW/VND). The code is authoritative.
 * - **The date filter includes undated rows.** `search_table()` skips a row when
 *   its parsed date falls outside the range, but a row whose date does not parse
 *   is never skipped — and the column filter that runs first only rejects an
 *   empty cell. So R-01's "this date" read also counts rows whose date is
 *   missing. That is the same quirk `fnb_search_table` pins.
 * - **R-01 does not fall back to `total_covers`.** M-02 does; the daily snapshot
 *   sums the three cover columns and nothing else.
 * - **R-04's docblock calls the unit price "at current supplier prices"** while
 *   the code reads it as of the configured as-of date. The code wins.
 * - **R-13 reads `deity` and the booking columns (`booking_id`, `session`,
 *   `type`, `notes`)** that the data catalogue may not carry. A missing column
 *   is an empty string, so those lines print with gaps rather than failing —
 *   which is exactly what WordPress prints.
 *
 * ## Errors
 *
 * `build()` answers an error object only for an unknown report id. A table that
 * cannot be read degrades the section that needs it, as in WordPress: four
 * reports print an "unavailable" line and the rest simply omit the figures.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Fnb;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Support\Text;

final class FnbReportBuilder
{
    /**
     * The Report library.
     *
     * R-14 is the operator's own "monthly sales report" and is addressed by the
     * client alias `client-r01` on export.
     */
    public const REPORTS = array(
        'R-01' => array(
            'title'     => 'Daily trading snapshot',
            'assistant' => 'A1',
            'approver'  => 'Restaurant manager',
        ),
        'R-02' => array(
            'title'     => 'Weekly needs attention brief',
            'assistant' => 'A1',
            'approver'  => 'CEO',
        ),
        'R-03' => array(
            'title'     => 'Weekend prep plan',
            'assistant' => 'A2',
            'approver'  => 'Head chef, bar manager',
        ),
        'R-04' => array(
            'title'     => 'Purchase request',
            'assistant' => 'A2',
            'approver'  => 'Head chef',
        ),
        'R-05' => array(
            'title'     => 'Waste and variance report',
            'assistant' => 'A2',
            'approver'  => 'Head chef',
        ),
        'R-06' => array(
            'title'     => 'Supplier price watch',
            'assistant' => 'A2',
            'approver'  => 'Head chef, finance',
        ),
        'R-07' => array(
            'title'     => 'Menu item margin report',
            'assistant' => 'A3',
            'approver'  => 'CEO, head chef',
        ),
        'R-08' => array(
            'title'     => 'Monthly cost review',
            'assistant' => 'A3',
            'approver'  => 'CEO',
        ),
        'R-09' => array(
            'title'     => 'Labour report',
            'assistant' => 'A3',
            'approver'  => 'CEO',
        ),
        'R-10' => array(
            'title'     => 'Utilities report',
            'assistant' => 'A3',
            'approver'  => 'CEO',
        ),
        'R-11' => array(
            'title'     => 'Payables report',
            'assistant' => 'A3',
            'approver'  => 'Finance',
        ),
        'R-12' => array(
            'title'     => 'Investor monthly pack',
            'assistant' => 'A3',
            'approver'  => 'CEO',
        ),
        'R-13' => array(
            'title'     => 'Weekly content brief',
            'assistant' => 'A4',
            'approver'  => 'CEO',
        ),
        'R-14' => array(
            'title'     => 'Monthly sales report',
            'assistant' => 'A1',
            'approver'  => 'Owner',
        ),
    );

    /** Row cap every `search_table()` call uses in WordPress. */
    private const SEARCH_LIMIT = 500;

    public function __construct(
        private readonly ErrorFactoryInterface $errors,
        private readonly FnbMetrics $metrics,
        private readonly FnbSettings $settings,
        private readonly FnbTableSource $tables,
    ) {
    }

    /**
     * The report ids, in library order.
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::REPORTS);
    }

    /**
     * Build a report.
     *
     * @param string               $reportId A canonical R-xx id.
     * @param array<string, mixed> $args     month, date, prev_month, supplier_id, ingredient_id, quantity.
     *
     * @return array<string, mixed>|mixed The report, or the platform's error object.
     */
    public function build(string $reportId, array $args = array()): mixed
    {
        if (! isset(self::REPORTS[ $reportId ])) {
            return $this->errors->create(
                'unknown_report',
                sprintf('Unknown F&B report "%s".', $reportId)
            );
        }

        $month = Text::singleLine($this->scalar($args, 'month'));
        $date  = Text::singleLine($this->scalar($args, 'date'));

        $sections = match ($reportId) {
            'R-01'  => $this->buildR01($month, $date),
            'R-02'  => $this->buildR02($month),
            'R-03'  => $this->buildR03($date),
            'R-04'  => $this->buildR04($date, $args),
            'R-05'  => $this->buildR05($month),
            'R-06'  => $this->buildR06($month),
            'R-07'  => $this->buildR07($month),
            'R-08'  => $this->buildR08($month, $args),
            'R-09'  => $this->buildR09($month),
            'R-10'  => $this->buildR10($month),
            'R-11'  => $this->buildR11(),
            'R-12'  => $this->buildR12($month),
            'R-13'  => $this->buildR13($date),
            default => $this->buildR14($month),
        };

        $meta = self::REPORTS[ $reportId ];

        return array(
            'report_id' => $reportId,
            'title'     => $meta['title'],
            'assistant' => $meta['assistant'],
            'approver'  => $meta['approver'],
            'sections'  => $sections,
            'draft'     => array(
                'status'         => 'Draft',
                'doc_title'      => sprintf(
                    '[%s] %s — %s — Draft — for %s',
                    $reportId,
                    $meta['title'],
                    '' !== $month ? $month : $date,
                    $meta['approver']
                ),
                'needs_approval' => $meta['approver'],
            ),
        );
    }

    /**
     * R-01 — daily trading snapshot.
     *
     * @return list<array{heading: string, lines: list<string>}>
     */
    private function buildR01(string $month, string $date): array
    {
        if ('' === $date) {
            $date = $this->settings->asOfDate();
        }

        $covers = 0.0;

        foreach ($this->rowsOn('7.0', $date) as $row) {
            $covers += $this->num('7.0', 'lunch_covers', $row)
                + $this->num('7.0', 'dinner_covers', $row)
                + $this->num('7.0', 'bar_only_guests', $row);
        }

        $revenue = 0.0;
        $byItem  = array();

        foreach ($this->rowsOn('6.0', $date) as $row) {
            $qty              = $this->num('6.0', 'qty_sold', $row);
            $price            = $this->num('6.0', 'price', $row);
            $item             = $this->str('6.0', 'item_id', $row);
            $revenue         += $qty * $price;
            $byItem[ $item ]  = isset($byItem[ $item ]) ? $byItem[ $item ] + $qty * $price : $qty * $price;
        }

        arsort($byItem);

        $wasteValue = 0.0;

        foreach ($this->rowsOn('10.0', $date) as $row) {
            $wasteValue += $this->num('10.0', 'value', $row);
        }

        return array(
            array(
                'heading' => 'Covers',
                'lines'   => array('Covers: ' . round($covers) . ' guests'),
            ),
            array(
                'heading' => 'Revenue',
                'lines'   => array('Revenue: ' . $this->money($revenue) . ' (excl. service charge)'),
            ),
            array(
                'heading' => 'Top 5 items (by revenue)',
                'lines'   => $this->formatRanking(array_slice($byItem, 0, 5, true)),
            ),
            array(
                'heading' => 'Bottom 5 items (by revenue)',
                'lines'   => $this->formatRanking(array_slice($byItem, -5, 5, true)),
            ),
            array(
                'heading' => 'Waste logged',
                'lines'   => array('Waste value: ' . $this->money($wasteValue)),
            ),
        );
    }

    /**
     * R-02 — weekly needs-attention brief (top 5 issues by impact).
     *
     * @return list<array{heading: string, lines: list<string>}>
     */
    private function buildR02(string $month): array
    {
        $issues = array();

        $budget = $this->metrics->m20BudgetVariance($month);

        if (isset($budget['rows']) && is_array($budget['rows'])) {
            foreach ($budget['rows'] as $row) {
                if (null !== $row['variance'] && $row['variance'] > 0) {
                    $issues[] = array(
                        'what'         => $row['line'] . ' over budget',
                        'figure'       => '+ ' . $this->money((float) $row['variance']) . ' vs budget',
                        'likely_cause' => 'See R-08 volume vs per-cover split.',
                        'next_check'   => 'Review expense ledger invoices for this line.',
                        'owner'        => 'Finance',
                        'impact'       => $row['variance'],
                    );
                }
            }
        }

        $waste = $this->metrics->m18WastePct($month);

        if (isset($waste['waste_value']) && $waste['waste_value'] > 0) {
            $issues[] = array(
                'what'         => 'Waste logged this month',
                'figure'       => $this->money((float) $waste['waste_value']),
                'likely_cause' => 'See logged reasons: ' . implode(', ', array_keys((array) $waste['by_reason'])),
                'next_check'   => 'R-05 waste by item and reason.',
                'owner'        => 'Head chef',
                'impact'       => $waste['waste_value'],
            );
        }

        usort(
            $issues,
            static fn (array $a, array $b): int => $b['impact'] <=> $a['impact']
        );

        $lines = array();

        foreach (array_slice($issues, 0, 5) as $index => $issue) {
            $lines[] = sprintf(
                '%d. %s — %s. Likely cause: %s. Next check: %s. Owner: %s.',
                $index + 1,
                $issue['what'],
                $issue['figure'],
                $issue['likely_cause'],
                $issue['next_check'],
                $issue['owner']
            );
        }

        if (array() === $lines) {
            $lines[] = 'No issues above threshold this week.';
        }

        return array(
            array(
                'heading' => 'Needs attention (max 5, ranked by impact)',
                'lines'   => $lines,
            ),
        );
    }

    /**
     * R-03 — weekend prep plan.
     *
     * @return list<array{heading: string, lines: list<string>}>
     */
    private function buildR03(string $date): array
    {
        if ('' === $date) {
            $date = $this->settings->asOfDate();
        }

        $days     = array(
            $date,
            (string) gmdate('Y-m-d', (int) strtotime($date . ' +1 day')),
            (string) gmdate('Y-m-d', (int) strtotime($date . ' +2 days')),
        );
        $sections = array();

        foreach ($days as $day) {
            $expected     = $this->metrics->m12ExpectedCovers($day);
            $bookingLines = array();

            foreach ($this->rowsOn('16.0', $day) as $row) {
                $bookingLines[] = sprintf(
                    '%s — %s guests — %s (%s) — %s',
                    $this->str('16.0', 'booking_id', $row),
                    round($this->num('16.0', 'guests', $row)),
                    $this->str('16.0', 'session', $row),
                    $this->str('16.0', 'type', $row),
                    $this->str('16.0', 'notes', $row)
                );
            }

            $sections[] = array(
                'heading' => $day,
                'lines'   => array_merge(
                    array(sprintf(
                        'Expected covers: %s (same-weekday avg %s + group bookings %s)',
                        round((float) $expected['value']),
                        round((float) $expected['same_weekday_avg']),
                        round((float) $expected['group_guests'])
                    )),
                    array() !== $bookingLines ? $bookingLines : array('No bookings logged.')
                ),
            );
        }

        $shortfall  = $this->weekendShortfall($days);
        $sections[] = array(
            'heading' => 'Stock shortfall vs expected portions',
            'lines'   => $shortfall,
        );

        return $sections;
    }

    /**
     * Weekend shortfall lines (A2-04/A2-05).
     *
     * @param list<string> $days Three dates, starting on the reference Friday.
     *
     * @return list<string>
     */
    private function weekendShortfall(array $days): array
    {
        $menu = $this->table('1.0');

        if (null === $menu) {
            return array('Menu table unavailable.');
        }

        $expectations = array();

        foreach ($menu['rows'] as $row) {
            $itemId = $this->str('1.0', 'item_id', $row);
            $total  = 0.0;

            foreach ($days as $day) {
                $total += (float) $this->metrics->m13ExpectedPortions($itemId, $day)['value'];
            }

            $expectations[ $itemId ] = $total;
        }

        arsort($expectations);

        $lines = array();

        foreach (array_slice($expectations, 0, 10, true) as $itemId => $expectedQty) {
            $ingredientLines = array();
            $recipes         = $this->table('2.0');

            if (null !== $recipes) {
                foreach ($recipes['rows'] as $row) {
                    if ($this->str('2.0', 'item_id', $row) !== $itemId) {
                        continue;
                    }

                    $ingredient  = $this->str('2.0', 'ingredient_id', $row);
                    $perPortion  = $this->num('2.0', 'qty_per_portion', $row);
                    $need        = $expectedQty * $perPortion;
                    $daysCover   = $this->metrics->m14DaysOfCover($ingredient, $days[0]);

                    $ingredientLines[] = sprintf(
                        '%s: need ~%.1f units, stock on hand %.1f, days of cover %.1f',
                        $ingredient,
                        $need,
                        (float) $daysCover['stock_on_hand'],
                        (float) $daysCover['value']
                    );
                }
            }

            $lines[] = sprintf(
                '%s — expected %.0f portions: %s',
                $itemId,
                $expectedQty,
                implode('; ', array_slice($ingredientLines, 0, 3))
            );
        }

        return $lines;
    }

    /**
     * R-04 — purchase request (A2-07 layout; status Draft).
     *
     * @param array<string, mixed> $args Tool arguments.
     *
     * @return list<array{heading: string, lines: list<string>}>
     */
    private function buildR04(string $date, array $args): array
    {
        $supplierId = Text::singleLine($this->scalar($args, 'supplier_id'));
        $ingredient = Text::singleLine($this->scalar($args, 'ingredient_id'));
        $quantity   = FnbTableCatalogue::floatCell($args['quantity'] ?? null);

        $supplierLine = $supplierId;
        $cutoff       = '';
        $suppliers    = $this->table('4.0');

        if (null !== $suppliers) {
            foreach ($suppliers['rows'] as $row) {
                if ($this->str('4.0', 'supplier_id', $row) === $supplierId) {
                    $supplierLine = $this->str('4.0', 'supplier', $row);
                    $cutoff       = $this->str('4.0', 'order_cutoff', $row);
                    break;
                }
            }
        }

        $unitPrice = $this->metrics->unitPriceAsOf($ingredient, $this->settings->asOfDate());
        $total     = $unitPrice * $quantity;

        return array(
            array(
                'heading' => 'Purchase request lines',
                'lines'   => array(
                    'Item: ' . $ingredient,
                    'Quantity: ' . round($quantity, 2),
                    'Supplier: ' . $supplierLine . ' (' . $supplierId . ')',
                    'Unit price: ' . $this->money(round($unitPrice, 2)),
                    'Total: ' . $this->money($total),
                    'Order by: ' . $cutoff,
                    'Needed by: ' . ('' !== $date ? $date : 'not specified'),
                    'Status: Draft',
                ),
            ),
        );
    }

    /**
     * R-05 — waste and variance report.
     *
     * @return list<array{heading: string, lines: list<string>}>
     */
    private function buildR05(string $month): array
    {
        $waste = $this->metrics->m18WastePct($month);
        $lines = array(
            'Waste % (logged waste ÷ stock used): ' . round((float) $waste['value'] * 100, 2) . '%',
        );

        foreach ((array) $waste['by_reason'] as $reason => $amount) {
            $lines[] = sprintf('%s: %s', $reason, $this->money((float) $amount));
        }

        return array(
            array(
                'heading' => 'Waste by reason (as logged)',
                'lines'   => $lines,
            ),
        );
    }

    /**
     * R-06 — supplier price watch (M-19 changes plus effective dates).
     *
     * @return list<array{heading: string, lines: list<string>}>
     */
    private function buildR06(string $month): array
    {
        $impact = $this->metrics->m19PriceChangeImpact($month);
        $lines  = array('Price-change impact this month: ' . $this->money((float) $impact['value']));

        foreach ((array) $impact['changes'] as $change) {
            $lines[] = sprintf(
                '%s: %s → %s/unit effective %s; qty bought %s; impact %s',
                $change['ingredient_id'],
                number_format((float) $change['old_unit_price'], 2),
                number_format((float) $change['new_unit_price'], 2),
                $change['effective_from'],
                $change['qty_bought'],
                $this->money((float) $change['impact'])
            );
        }

        return array(
            array(
                'heading' => 'Price changes vs last month',
                'lines'   => $lines,
            ),
        );
    }

    /**
     * R-07 — menu item margin report with engineering quadrants.
     *
     * @return list<array{heading: string, lines: list<string>}>
     */
    private function buildR07(string $month): array
    {
        $lines = array();

        foreach ((array) $this->metrics->menuEngineering($month)['rows'] as $item) {
            $lines[] = sprintf(
                '%s %s — margin %s (cost %s%%), share %s%% — %s: %s',
                $item['item_id'],
                $item['item'],
                $this->money(round((float) $item['margin'], 0)),
                round((float) $item['cost_pct'] * 100, 1),
                round((float) $item['popularity_share'] * 100, 2),
                $item['quadrant'],
                $item['action']
            );
        }

        return array(
            array(
                'heading' => 'Dish margins and menu-engineering quadrant',
                'lines'   => $lines,
            ),
        );
    }

    /**
     * R-08 — monthly cost review (actual vs budget vs last month, volume/per-cover).
     *
     * @param array<string, mixed> $args Tool arguments.
     *
     * @return list<array{heading: string, lines: list<string>}>
     */
    private function buildR08(string $month, array $args): array
    {
        $prevMonth = isset($args['prev_month']) ? Text::singleLine($this->scalar($args, 'prev_month')) : $this->prevMonth($month);

        $opex       = $this->metrics->totalOperatingCost($month);
        $prev       = $this->metrics->totalOperatingCost($prevMonth);
        $covers     = $this->metrics->m02Covers($month);
        $prevCovers = $this->metrics->m02Covers($prevMonth);

        $volume   = $this->metrics->m07VolumeEffect((float) $prev['total'], (float) $prevCovers['value'], (float) $covers['value']);
        $perCover = $this->metrics->m08PerCoverEffect((float) $opex['total'], (float) $prev['total'], $volume);

        $lines = array(
            sprintf(
                'Total operating expenses: %s (prev month %s, change %s)',
                $this->money((float) $opex['total']),
                $this->money((float) $prev['total']),
                $this->money((float) $opex['total'] - (float) $prev['total'])
            ),
            sprintf(
                'Volume effect: %s (covers %s → %s)',
                $this->money($volume),
                round((float) $prevCovers['value']),
                round((float) $covers['value'])
            ),
            sprintf('Per-cover effect: %s', $this->money($perCover)),
        );

        foreach ((array) $opex['lines'] as $line => $amount) {
            $lines[] = sprintf('%s: %s', $line, $this->money((float) $amount));
        }

        $budget      = $this->metrics->m20BudgetVariance($month);
        $budgetLines = array();

        if (isset($budget['rows']) && is_array($budget['rows'])) {
            foreach ($budget['rows'] as $row) {
                if (null === $row['variance']) {
                    continue;
                }

                $budgetLines[] = sprintf(
                    '%s: actual %s vs budget %s (%s)',
                    $row['line'],
                    null === $row['actual'] ? 'n/a' : $this->money((float) $row['actual']),
                    $this->money((float) $row['budget']),
                    $this->money((float) $row['variance'])
                );
            }
        }

        return array(
            array(
                'heading' => 'Cost change (volume vs per-cover)',
                'lines'   => $lines,
            ),
            array(
                'heading' => 'Actual vs budget by line',
                'lines'   => $budgetLines,
            ),
        );
    }

    /**
     * R-09 — labour report.
     *
     * @return list<array{heading: string, lines: list<string>}>
     */
    private function buildR09(string $month): array
    {
        $payroll = $this->metrics->payrollTotal($month);
        $m09     = $this->metrics->m09OvertimePer100Covers($month);
        $m10     = $this->metrics->m10LabourPct($month);

        $lines = array(
            sprintf('Total payroll: %s', $this->money((float) $payroll['value'])),
            sprintf('Overtime hours: %s (per 100 covers: %s)', $payroll['overtime_hours'], $m09['value']),
            sprintf('Casual shifts: %s', $payroll['casual_shifts']),
            sprintf('Labour cost %%: %s%%', round((float) $m10['value'] * 100, 2)),
        );

        foreach ((array) $payroll['departments'] as $dept => $cost) {
            $lines[] = sprintf('%s: %s', $dept, $this->money((float) $cost));
        }

        return array(
            array(
                'heading' => 'Payroll, overtime and casuals by department',
                'lines'   => $lines,
            ),
        );
    }

    /**
     * R-10 — utilities report.
     *
     * @return list<array{heading: string, lines: list<string>}>
     */
    private function buildR10(string $month): array
    {
        $utils = $this->metrics->utilitiesTotals($month);
        $m11   = $this->metrics->m11ElectricityPerCover($month);

        $lines = array(
            sprintf(
                'Electricity: %s kWh (%s per cover) — %s',
                $utils['kwh'],
                $m11['value'],
                $this->money((float) $utils['Electricity'])
            ),
            sprintf('Water: %s m³ — %s', $utils['water_m3'], $this->money((float) $utils['Water'])),
            sprintf('LPG: %s cylinders — %s', $utils['lpg_units'], $this->money((float) $utils['LPG'])),
        );

        return array(
            array(
                'heading' => 'kWh, water and LPG per cover',
                'lines'   => $lines,
            ),
        );
    }

    /**
     * R-11 — payables report (unpaid invoices, duplicates, unusual amounts).
     *
     * @return list<array{heading: string, lines: list<string>}>
     */
    private function buildR11(): array
    {
        $ledger = $this->table('14.0');

        if (null === $ledger) {
            return array(
                array(
                    'heading' => 'Payables',
                    'lines'   => array('Expense ledger unavailable.'),
                ),
            );
        }

        $unpaid     = array();
        $seen       = array();
        $duplicates = array();

        foreach ($ledger['rows'] as $row) {
            $vendor   = $this->str('14.0', 'vendor', $row);
            $invoice  = $this->str('14.0', 'vendor_invoice', $row);
            $amount   = $this->num('14.0', 'amount', $row);
            $status   = $this->str('14.0', 'status', $row);
            $dupKey   = strtolower($vendor . '|' . $invoice . '|' . round($amount));

            if (isset($seen[ $dupKey ])) {
                $duplicates[] = sprintf(
                    'Possible duplicate: %s invoice %s (%s) — flag for checking',
                    $vendor,
                    $invoice,
                    $this->money($amount)
                );
            }

            $seen[ $dupKey ] = true;

            if ('Paid' !== $status) {
                $unpaid[] = sprintf(
                    '%s — %s — %s — due %s — %s',
                    $vendor,
                    $invoice,
                    $this->money($amount),
                    $this->day('14.0', 'due_date', $row),
                    $status
                );
            }
        }

        return array(
            array(
                'heading' => 'Unpaid invoices',
                'lines'   => array() !== $unpaid ? $unpaid : array('No unpaid invoices.'),
            ),
            array(
                'heading' => 'Possible duplicates (flag, do not conclude)',
                'lines'   => array() !== $duplicates ? $duplicates : array('None flagged.'),
            ),
        );
    }

    /**
     * R-12 — investor monthly pack (declarative, outcome-first).
     *
     * @return list<array{heading: string, lines: list<string>}>
     */
    private function buildR12(string $month): array
    {
        $foodRev = $this->metrics->m01Revenue($month, 'Food');
        $bevRev  = $this->metrics->m01Revenue($month, 'Beverage');
        $covers  = $this->metrics->m02Covers($month);
        $m03     = $this->metrics->m03AvgSpend($month);
        $m04     = $this->metrics->m04FoodCostPct($month);
        $m05     = $this->metrics->m05BeverageCostPct($month);
        $opex    = $this->metrics->totalOperatingCost($month);
        $revenue = (float) $foodRev['value'] + (float) $bevRev['value'];

        $lines = array(
            sprintf(
                'Revenue for %s was %s across %s covers (average spend %s per cover).',
                $month,
                $this->money($revenue),
                round((float) $covers['value']),
                $this->money((float) $m03['value'])
            ),
            sprintf(
                'Food revenue %s; beverage revenue %s.',
                $this->money((float) $foodRev['value']),
                $this->money((float) $bevRev['value'])
            ),
            sprintf(
                'Food cost was %s%% of food revenue and beverage cost %s%% of beverage revenue, valued on stock used.',
                round((float) $m04['value'] * 100, 2),
                round((float) $m05['value'] * 100, 2)
            ),
            sprintf(
                'Total operating expenses were %s (%s%% of revenue).',
                $this->money((float) $opex['total']),
                $revenue > 0 ? round((float) $opex['total'] / $revenue * 100, 2) : 0
            ),
        );

        $budget      = $this->metrics->m20BudgetVariance($month);
        $budgetLines = array();

        if (isset($budget['rows']) && is_array($budget['rows'])) {
            foreach ($budget['rows'] as $row) {
                if (null !== $row['variance'] && $row['variance'] > 0) {
                    $budgetLines[] = sprintf(
                        '%s exceeded budget by %s.',
                        $row['line'],
                        $this->money((float) $row['variance'])
                    );
                }
            }
        }

        return array(
            array(
                'heading' => 'Headline KPIs',
                'lines'   => $lines,
            ),
            array(
                'heading' => 'Budget variance',
                'lines'   => array() !== $budgetLines ? $budgetLines : array('All lines at or under budget.'),
            ),
            array(
                'heading' => 'Key issues and actions',
                'lines'   => array('See R-02 needs-attention brief.'),
            ),
        );
    }

    /**
     * R-13 — weekly content brief (A4).
     *
     * @return list<array{heading: string, lines: list<string>}>
     */
    private function buildR13(string $date): array
    {
        $lines = array();
        $start = '' !== $date ? $date : $this->settings->asOfDate();

        $forecast = array();

        for ($i = 0; $i < 7; $i++) {
            $day              = (string) gmdate('Y-m-d', (int) strtotime($start . ' +' . $i . ' days'));
            $forecast[ $day ] = (float) $this->metrics->m12ExpectedCovers($day)['value'];
        }

        asort($forecast);
        $quiet   = array_slice(array_keys($forecast), 0, 2);
        $lines[] = 'Quiet days this week: ' . implode(', ', $quiet);

        $ingredients = $this->table('3.0');

        if (null !== $ingredients) {
            $over = array();

            foreach ($ingredients['rows'] as $row) {
                $ingredient = $this->str('3.0', 'ingredient_id', $row);
                $days       = $this->metrics->m14DaysOfCover($ingredient, $start);

                if ((float) $days['value'] > 14) {
                    $over[ $ingredient ] = (float) $days['value'];
                }
            }

            arsort($over);
            $over    = array_slice($over, 0, 3, true);
            $lines[] = 'Overstocked items to promote: ' . (array() !== $over ? implode(
                ', ',
                array_map(
                    static fn (string $key, float $value): string => $key . ' (' . round($value) . ' days)',
                    array_keys($over),
                    $over
                )
            ) : 'none');
        }

        $assets = $this->table('17.0');
        $deities = array();

        if (null !== $assets) {
            foreach ($assets['rows'] as $row) {
                $deity = $this->str('17.0', 'deity', $row);

                if ('' === $deity) {
                    continue;
                }

                $deities[ $deity ] = isset($deities[ $deity ]) ? $deities[ $deity ] + 1 : 1;
            }
        }

        $deityLines = array();

        foreach ($deities as $deity => $count) {
            $deityLines[] = $deity . ': ' . $count . ' posts';
        }

        $lines[] = 'Content-subject usage: ' . (array() !== $deityLines ? implode('; ', $deityLines) : 'none logged');

        return array(
            array(
                'heading' => 'Content ideas linked to bookings and stock',
                'lines'   => $lines,
            ),
        );
    }

    /**
     * R-14 — monthly sales report (the operator's "R-01 Monthly sales report").
     *
     * @return list<array{heading: string, lines: list<string>}>
     */
    private function buildR14(string $month): array
    {
        if ('' === $month) {
            $month = (string) gmdate('Y-m');
        }

        $revenue   = $this->metrics->m01Revenue($month);
        $foodRev   = $this->metrics->m01Revenue($month, 'Food');
        $bevRev    = $this->metrics->m01Revenue($month, 'Beverage');
        $covers    = $this->metrics->m02Covers($month);
        $avgSpend  = $this->metrics->m03AvgSpend($month);
        $byItem    = array();
        $sales     = $this->table('6.0');

        if (null !== $sales) {
            foreach ($sales['rows'] as $row) {
                if ($this->mon('6.0', 'date', $row) !== $month) {
                    continue;
                }

                $qty             = $this->num('6.0', 'qty_sold', $row);
                $price           = $this->num('6.0', 'price', $row);
                $item            = $this->str('6.0', 'item_id', $row);
                $byItem[ $item ] = isset($byItem[ $item ]) ? $byItem[ $item ] + $qty * $price : $qty * $price;
            }
        }

        arsort($byItem);

        return array(
            array(
                'heading' => 'Headline',
                'lines'   => array(
                    'Revenue: ' . $this->money((float) $revenue['value']),
                    'Covers: ' . round((float) $covers['value']),
                    'Average spend: ' . $this->money((float) $avgSpend['value']) . ' per cover',
                ),
            ),
            array(
                'heading' => 'Revenue by type',
                'lines'   => array(
                    'Food: ' . $this->money((float) $foodRev['value']),
                    'Beverage: ' . $this->money((float) $bevRev['value']),
                ),
            ),
            array(
                'heading' => 'Top 5 items (by revenue)',
                'lines'   => $this->formatRanking(array_slice($byItem, 0, 5, true)),
            ),
            array(
                'heading' => 'Bottom 5 items (by revenue)',
                'lines'   => $this->formatRanking(array_slice($byItem, -5, 5, true)),
            ),
        );
    }

    /**
     * Format an item ranking for report lines.
     *
     * @param array<string, float> $ranking item_id => revenue.
     *
     * @return list<string>
     */
    private function formatRanking(array $ranking): array
    {
        $lines = array();

        foreach ($ranking as $itemId => $revenue) {
            $lines[] = sprintf('%s: %s', $itemId, $this->money($revenue));
        }

        return array() !== $lines ? $lines : array('No sales lines.');
    }

    /**
     * Previous month for a Y-m value.
     *
     * The WordPress function feeds `$month . '-01 -1 month'` to `strtotime()`.
     * An empty month therefore produces a string PHP may refuse to parse; that
     * used to become `gmdate( 'Y-m', false )`, i.e. 1970-01. Under strict types
     * `false` cannot be passed, so the same zero is stated explicitly.
     */
    private function prevMonth(string $month): string
    {
        $timestamp = strtotime($month . '-01 -1 month');

        return (string) gmdate('Y-m', false === $timestamp ? 0 : $timestamp);
    }

    /**
     * The resolved table, or null when the source refused it.
     *
     * @return array<string, mixed>|null
     */
    private function table(string $tableId): ?array
    {
        $table = $this->tables->getTable($tableId);

        return $this->errors->isError($table) ? null : $table;
    }

    /**
     * Rows matching one date, the way `search_table()` matches them.
     *
     * WordPress filters on the column by substring **and** on the parsed date by
     * range, where a row whose date does not parse is never skipped. Both passes
     * are reproduced, so an undated row is counted on any date — the quirk
     * `fnb_search_table` documents.
     *
     * @return list<array<int|string, mixed>>
     */
    private function rowsOn(string $tableId, string $date): array
    {
        $table = $this->table($tableId);

        if (null === $table) {
            return array();
        }

        $matches = array();

        foreach ($table['rows'] as $row) {
            $raw = (string) $this->cell($tableId, 'date', $row);

            if (false === stripos($raw, $date)) {
                continue;
            }

            $cellDate = FnbTableCatalogue::dateOnly($raw);

            if ('' !== $cellDate && ($cellDate < $date || $cellDate > $date)) {
                continue;
            }

            $matches[] = $row;

            if (count($matches) >= self::SEARCH_LIMIT) {
                break;
            }
        }

        return $matches;
    }

    /**
     * A canonical cell value from a row of a table.
     *
     * @param array<int|string, mixed> $row Row.
     */
    private function cell(string $tableId, string $canonical, array $row): mixed
    {
        $table = $this->table($tableId);

        if (null === $table) {
            return '';
        }

        return FnbTableCatalogue::cell($tableId, $table['header_index'], $row, $canonical);
    }

    /**
     * A canonical cell as a string.
     *
     * @param array<int|string, mixed> $row Row.
     */
    private function str(string $tableId, string $canonical, array $row): string
    {
        return (string) $this->cell($tableId, $canonical, $row);
    }

    /**
     * A canonical cell as a float.
     *
     * @param array<int|string, mixed> $row Row.
     */
    private function num(string $tableId, string $canonical, array $row): float
    {
        return FnbTableCatalogue::floatCell($this->cell($tableId, $canonical, $row));
    }

    /**
     * A canonical date cell as `Y-m-d` (or `Y-m`).
     *
     * @param array<int|string, mixed> $row Row.
     */
    private function day(string $tableId, string $canonical, array $row): string
    {
        return FnbTableCatalogue::dateOnly($this->cell($tableId, $canonical, $row));
    }

    /**
     * A canonical date cell as its `Y-m` prefix.
     *
     * @param array<int|string, mixed> $row Row.
     */
    private function mon(string $tableId, string $canonical, array $row): string
    {
        return FnbTableCatalogue::monthOf($this->cell($tableId, $canonical, $row));
    }

    /**
     * A money figure in the tenant's currency.
     */
    private function money(float $amount): string
    {
        return $this->settings->formatMoney($amount);
    }

    /**
     * A scalar argument as a string, `''` for anything else.
     *
     * @param array<string, mixed> $arguments Arguments.
     */
    private function scalar(array $arguments, string $key): string
    {
        $value = $arguments[ $key ] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
