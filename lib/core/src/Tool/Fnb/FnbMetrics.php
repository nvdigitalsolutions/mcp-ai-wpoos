<?php

/**
 * The Food & Beverage metric engine — M-01 … M-21.
 *
 * Port of `WP_MCP_AI_Fnb_Metrics` (§164.72). Every figure the toolkit reports is
 * computed here from the tables `FnbTableSource` reads; the model is never
 * asked to do arithmetic. The formulas are the WordPress ones, unchanged, and
 * each result carries the `source` label the original emitted.
 *
 * ## What changed in the port, and why
 *
 * - **Instance rather than static.** WordPress calls
 *   `WP_MCP_AI_Fnb_Data_Source::get_table()` from inside every metric, so the
 *   reading source is global. Here the source is injected, which is what lets a
 *   test hand in a fixture and what lets the host decide where rows come from.
 * - **Fluent cell accessors.** The original spells every read
 *   `float_cell( cell( '9.0', $headers, $row, 'opening' ) )`. The same three
 *   helpers are kept, with the table id bound per loop, as `num()`, `str()`,
 *   `mon()` and `day()`; the behaviour is identical and the formula you are
 *   reading is the formula.
 * - **`stock_used_lkr()` is `stockUsed()`.** The name carried a currency that
 *   the toolkit has since made configurable; the formula (opening value +
 *   purchases − closing value) is unchanged.
 * - **`r()` reads the tenant currency precision** through `FnbSettings`, as the
 *   original read `WP_MCP_AI_Fnb_Tenant_Profile::currency_decimals()`.
 *
 * ## Errors travel in the result, as they did
 *
 * A metric whose table will not read returns its usual shape plus an `error`
 * string; `calculate()` turns that into a typed error. That matters because a
 * nested metric (M-03 calls M-01 and M-02) must still return the figures it does
 * have — a missing covers table makes the average zero and says why, rather
 * than failing the whole call.
 *
 * ## Nested reads and the cache
 *
 * Several metrics call other metrics (M-06 → operating cost → payroll, utilities,
 * ledger), and `menuEngineering()` calls M-21 once per menu item. WordPress pays
 * for that with a five-minute transient per table; here it is the optional
 * `CacheStoreInterface` on `FnbTableSource`. Without a cache the same table is
 * re-read, which is correct but slower — there is deliberately no in-process
 * memo, because the same object lives across requests in a long-lived worker.
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

class FnbMetrics
{
    /** The metric catalogue, in the order the Metrics sheet lists it. */
    public const METRICS = array(
        'M-01' => 'Revenue',
        'M-02' => 'Covers',
        'M-03' => 'Average spend per cover',
        'M-04' => 'Food cost %',
        'M-05' => 'Beverage cost %',
        'M-06' => 'Cost per cover',
        'M-07' => 'Volume effect',
        'M-08' => 'Per-cover effect',
        'M-09' => 'Overtime per 100 covers (hours)',
        'M-10' => 'Labour cost %',
        'M-11' => 'Electricity per cover (kWh)',
        'M-12' => 'Expected covers',
        'M-13' => 'Expected portions',
        'M-14' => 'Days of cover',
        'M-15' => 'Stock used',
        'M-16' => 'Expected use',
        'M-17' => 'Unexplained variance',
        'M-18' => 'Waste %',
        'M-19' => 'Price change impact',
        'M-20' => 'Budget variance',
        'M-21' => 'Dish margin',
    );

    public function __construct(
        private readonly ErrorFactoryInterface $errors,
        private readonly FnbTableSource $tables,
        private readonly FnbSettings $settings,
    ) {
    }

    // ─── Reading helpers ──────────────────────────────────────────────────

    /**
     * A resolved table, or the platform error object.
     *
     * @return array<string, mixed>|mixed
     */
    private function table(string $tableId): mixed
    {
        return $this->tables->getTable($tableId);
    }

    /**
     * A canonical column's value.
     *
     * @param list<string>             $headers Normalised headers.
     * @param array<int|string, mixed> $row     Positional row.
     */
    private function cell(string $tableId, array $headers, array $row, string $canonical, mixed $fallback = ''): mixed
    {
        return FnbTableCatalogue::cell($tableId, $headers, $row, $canonical, $fallback);
    }

    /**
     * A canonical column as a float (thousands separators removed).
     *
     * @param list<string>             $headers Normalised headers.
     * @param array<int|string, mixed> $row     Positional row.
     */
    private function num(string $tableId, array $headers, array $row, string $canonical): float
    {
        return FnbTableCatalogue::floatCell($this->cell($tableId, $headers, $row, $canonical));
    }

    /**
     * A canonical column as a trimmed string.
     *
     * @param list<string>             $headers Normalised headers.
     * @param array<int|string, mixed> $row     Positional row.
     */
    private function str(string $tableId, array $headers, array $row, string $canonical): string
    {
        return (string) $this->cell($tableId, $headers, $row, $canonical);
    }

    /**
     * A canonical date column reduced to `Y-m-d` (or `Y-m`).
     *
     * @param list<string>             $headers Normalised headers.
     * @param array<int|string, mixed> $row     Positional row.
     */
    private function day(string $tableId, array $headers, array $row, string $canonical): string
    {
        return FnbTableCatalogue::dateOnly($this->cell($tableId, $headers, $row, $canonical));
    }

    /**
     * A canonical column's `Y-m` prefix.
     *
     * @param list<string>             $headers Normalised headers.
     * @param array<int|string, mixed> $row     Positional row.
     */
    private function mon(string $tableId, array $headers, array $row, string $canonical): string
    {
        return FnbTableCatalogue::monthOf($this->cell($tableId, $headers, $row, $canonical));
    }

    /**
     * Round to the tenant's money precision unless told otherwise.
     */
    private function r(float $value, ?int $dp = null): float
    {
        return round($value, $dp ?? $this->settings->currencyDecimals());
    }

    // ─── M-01 … M-06: revenue, covers, cost ───────────────────────────────

    /**
     * M-01 — revenue for a month, optionally by type.
     *
     * @return array<string, mixed>
     */
    public function m01Revenue(string $month, ?string $type = null): array
    {
        $table = $this->table('6.0');

        if ($this->errors->isError($table)) {
            return array('value' => 0.0, 'rows' => 0, 'error' => $this->message($table));
        }

        $headers = $table['header_index'];
        $total   = 0.0;
        $rows    = 0;

        foreach ($table['rows'] as $row) {
            if ($this->mon('6.0', $headers, $row, 'date') !== $month) {
                continue;
            }

            if (null !== $type && strtolower($this->str('6.0', $headers, $row, 'type')) !== strtolower($type)) {
                continue;
            }

            $total += $this->num('6.0', $headers, $row, 'qty_sold') * $this->num('6.0', $headers, $row, 'price');
            ++$rows;
        }

        return array('value' => $this->r($total), 'rows' => $rows, 'source' => 'fnb_metrics:M-01');
    }

    /**
     * M-02 — covers for a month (lunch + dinner + bar-only).
     *
     * Falls back to the daily `total_covers` on the utilities sheet when the
     * covers table is absent or empty, de-duplicating by date so several meter
     * readings for one day do not multiply the covers.
     *
     * @return array<string, mixed>
     */
    public function m02Covers(string $month): array
    {
        $total = 0.0;
        $days  = 0;
        $error = '';

        $table = $this->table('7.0');

        if ($this->errors->isError($table)) {
            $error = $this->message($table);
        } else {
            $headers = $table['header_index'];

            foreach ($table['rows'] as $row) {
                $rowMonth = $this->mon('7.0', $headers, $row, 'date');

                if ('' === $rowMonth) {
                    $rowMonth = $this->str('7.0', $headers, $row, 'month');
                }

                if ($rowMonth !== $month) {
                    continue;
                }

                $rowTotal = $this->num('7.0', $headers, $row, 'lunch_covers')
                    + $this->num('7.0', $headers, $row, 'dinner_covers')
                    + $this->num('7.0', $headers, $row, 'bar_only_guests');

                // Some exports carry only the precomputed Total covers column.
                if ($rowTotal <= 0) {
                    $rowTotal = $this->num('7.0', $headers, $row, 'total_covers');
                }

                $total += $rowTotal;
                ++$days;
            }
        }

        $source = 'fnb_metrics:M-02';

        if ($total <= 0) {
            $utilities = $this->table('13.0');

            if (! $this->errors->isError($utilities)) {
                $uHeaders = $utilities['header_index'];
                $byDate   = array();
                $undated  = 0.0;

                foreach ($utilities['rows'] as $row) {
                    $date      = $this->day('13.0', $uHeaders, $row, 'date');
                    $rowMonth  = FnbTableCatalogue::monthOf($date);

                    if ('' === $rowMonth) {
                        $rowMonth = $this->str('13.0', $uHeaders, $row, 'month');
                    }

                    if ($rowMonth !== $month) {
                        continue;
                    }

                    $covers = $this->num('13.0', $uHeaders, $row, 'total_covers');

                    if ($covers <= 0) {
                        continue;
                    }

                    if ('' !== $date) {
                        $byDate[ $date ] = $covers;
                    } else {
                        $undated += $covers;
                    }
                }

                $utilityTotal = array_sum($byDate) + $undated;

                if ($utilityTotal > 0) {
                    $total  = $utilityTotal;
                    $days   = count($byDate) + ( $undated > 0 ? 1 : 0 );
                    $source = 'fnb_metrics:M-02:utilities';
                    $error  = '';
                }
            } elseif ('' === $error) {
                $error = $this->message($utilities);
            }
        }

        $result = array('value' => $this->r($total), 'days' => $days, 'source' => $source);

        if ('' !== $error) {
            $result['error'] = $error;
        }

        return $result;
    }

    /**
     * M-03 — average spend per cover.
     *
     * @return array<string, mixed>
     */
    public function m03AvgSpend(string $month): array
    {
        $revenue = $this->m01Revenue($month);
        $covers  = $this->m02Covers($month);

        return array(
            'value'   => $covers['value'] > 0 ? $this->r($revenue['value'] / $covers['value']) : 0.0,
            'revenue' => $revenue,
            'covers'  => $covers,
            'source'  => 'fnb_metrics:M-03',
        );
    }

    /**
     * Value-based cost of goods used for a month by category.
     *
     * Opening value (the last count on or before the previous month's end) plus
     * purchases during the month minus closing value (the last count on or
     * before this month's end) — the Check totals definition. The error path
     * returns the four figures as zero plus `error`, so callers can add without
     * checking.
     *
     * @return array{
     *     value: float,
     *     opening: float,
     *     purchases: float,
     *     closing: float,
     *     open_date?: string,
     *     close_date?: string,
     *     source?: string,
     *     error?: string
     * }
     */
    public function stockUsed(string $month, string $category): array
    {
        $table = $this->table('9.0');

        if ($this->errors->isError($table)) {
            return array(
                'value'     => 0.0,
                'opening'   => 0.0,
                'purchases' => 0.0,
                'closing'   => 0.0,
                'error'     => $this->message($table),
            );
        }

        $headers    = $table['header_index'];
        $closeDates = array();

        foreach ($table['rows'] as $row) {
            $countDate = $this->day('9.0', $headers, $row, 'count_date');

            if ('' !== $countDate) {
                $closeDates[] = $countDate;
            }
        }

        $closeDates = array_values(array_unique($closeDates));
        sort($closeDates);

        $monthEnd = (string) gmdate('Y-m-d', (int) strtotime($month . '-01 +1 month -1 day'));
        $prevEnd  = (string) gmdate('Y-m-d', (int) strtotime($month . '-01 -1 day'));

        $openDate  = '';
        $closeDate = '';

        foreach ($closeDates as $countDate) {
            if ($countDate <= $prevEnd) {
                $openDate = $countDate;
            }

            if ($countDate <= $monthEnd) {
                $closeDate = $countDate;
            }
        }

        $opening = 0.0;
        $closing = 0.0;

        foreach ($table['rows'] as $row) {
            $rowDate = $this->day('9.0', $headers, $row, 'count_date');

            if (strtolower($this->str('9.0', $headers, $row, 'category')) !== strtolower($category)) {
                continue;
            }

            $value = $this->num('9.0', $headers, $row, 'closing_value');

            if ('' !== $openDate && $rowDate === $openDate) {
                $opening += $value;
            }

            if ('' !== $closeDate && $rowDate === $closeDate) {
                $closing += $value;
            }
        }

        $purchaseValue = 0.0;
        $purchases     = $this->table('8.0');

        if (! $this->errors->isError($purchases)) {
            $pHeaders = $purchases['header_index'];

            foreach ($purchases['rows'] as $row) {
                if ($this->mon('8.0', $pHeaders, $row, 'delivery_date') !== $month) {
                    continue;
                }

                if (strtolower($this->str('8.0', $pHeaders, $row, 'category')) !== strtolower($category)) {
                    continue;
                }

                $purchaseValue += $this->num('8.0', $pHeaders, $row, 'line_total');
            }
        }

        return array(
            'value'      => $this->r($opening + $purchaseValue - $closing),
            'opening'    => $this->r($opening),
            'purchases'  => $this->r($purchaseValue),
            'closing'    => $this->r($closing),
            'open_date'  => $openDate,
            'close_date' => $closeDate,
            'source'     => 'fnb_metrics:stock_used_lkr',
        );
    }

    /**
     * M-04 — food cost %.
     *
     * @return array<string, mixed>
     */
    public function m04FoodCostPct(string $month): array
    {
        $used    = $this->stockUsed($month, 'Food');
        $revenue = $this->m01Revenue($month, 'Food');

        return array(
            'value'      => $revenue['value'] > 0 ? $this->r($used['value'] / $revenue['value'], 4) : 0.0,
            'stock_used' => $used,
            'revenue'    => $revenue,
            'source'     => 'fnb_metrics:M-04',
        );
    }

    /**
     * M-05 — beverage cost %.
     *
     * @return array<string, mixed>
     */
    public function m05BeverageCostPct(string $month): array
    {
        $used    = $this->stockUsed($month, 'Beverage');
        $revenue = $this->m01Revenue($month, 'Beverage');

        return array(
            'value'      => $revenue['value'] > 0 ? $this->r($used['value'] / $revenue['value'], 4) : 0.0,
            'stock_used' => $used,
            'revenue'    => $revenue,
            'source'     => 'fnb_metrics:M-05',
        );
    }

    /**
     * Total operating cost for a month: stock used + payroll + utilities + the
     * operating lines of the expense ledger.
     *
     * @return array<string, mixed>
     */
    public function totalOperatingCost(string $month): array
    {
        $lines = array();

        $foodUsed = $this->stockUsed($month, 'Food');
        $bevUsed  = $this->stockUsed($month, 'Beverage');

        $lines['Food cost (stock used)']     = $foodUsed['value'];
        $lines['Beverage cost (stock used)'] = $bevUsed['value'];

        $payroll          = $this->payrollTotal($month);
        $lines['Payroll'] = $payroll['value'];

        // Costed lines only — the raw meters are not costs.
        $utilities = $this->utilitiesTotals($month, $this->assumptions());

        foreach ($utilities as $key => $value) {
            if (in_array($key, array('kwh', 'water_m3', 'lpg_units'), true)) {
                continue;
            }

            $lines[ $key ] = $value;
        }

        foreach ($this->expenseLinesByMonth($month, 'Operating') as $lineName => $amount) {
            $lines[ $lineName ] = $amount;
        }

        $total = 0.0;

        foreach ($lines as $amount) {
            $total += (float) $amount;
        }

        return array('lines' => $lines, 'total' => $this->r($total), 'source' => 'fnb_metrics:total_operating_cost');
    }

    /**
     * M-06 — cost per cover.
     *
     * @return array<string, mixed>
     */
    public function m06CostPerCover(string $month): array
    {
        $opex   = $this->totalOperatingCost($month);
        $covers = $this->m02Covers($month);

        return array(
            'value'  => $covers['value'] > 0 ? $this->r($opex['total'] / $covers['value']) : 0.0,
            'opex'   => $opex,
            'covers' => $covers,
            'source' => 'fnb_metrics:M-06',
        );
    }

    // ─── M-07 … M-11: effects, labour, utilities ──────────────────────────

    /**
     * M-07 — volume effect.
     */
    public function m07VolumeEffect(float $prevCost, float $prevCovers, float $curCovers): float
    {
        if ($prevCovers <= 0) {
            return 0.0;
        }

        return $this->r(( $prevCost / $prevCovers ) * $curCovers - $prevCost);
    }

    /**
     * M-08 — per-cover effect: total cost change minus the volume effect.
     */
    public function m08PerCoverEffect(float $curCost, float $prevCost, float $volume): float
    {
        return $this->r(( $curCost - $prevCost ) - $volume);
    }

    /**
     * Payroll total for a month, by department.
     *
     * @return array<string, mixed>
     */
    public function payrollTotal(string $month): array
    {
        $table = $this->table('11.0');

        if ($this->errors->isError($table)) {
            return array(
                'value'          => 0.0,
                'departments'    => array(),
                'overtime_hours' => 0.0,
                'casual_shifts'  => 0.0,
                'error'          => $this->message($table),
            );
        }

        $headers     = $table['header_index'];
        $total       = 0.0;
        $overtime    = 0.0;
        $casual      = 0.0;
        $departments = array();

        foreach ($table['rows'] as $row) {
            if ($this->mon('11.0', $headers, $row, 'month') !== $month) {
                continue;
            }

            $dept                 = $this->str('11.0', $headers, $row, 'department');
            $cost                 = $this->num('11.0', $headers, $row, 'total_payroll_cost');
            $total               += $cost;
            $overtime            += $this->num('11.0', $headers, $row, 'overtime_hours');
            $casual              += $this->num('11.0', $headers, $row, 'casual_shifts');
            $departments[ $dept ] = $this->r($cost);
        }

        return array(
            'value'          => $this->r($total),
            'departments'    => $departments,
            'overtime_hours' => $this->r($overtime),
            'casual_shifts'  => $this->r($casual),
            'source'         => 'fnb_metrics:payroll_total',
        );
    }

    /**
     * M-09 — overtime per 100 covers.
     *
     * @return array<string, mixed>
     */
    public function m09OvertimePer100Covers(string $month): array
    {
        $payroll = $this->payrollTotal($month);
        $covers  = $this->m02Covers($month);

        return array(
            'value'          => $covers['value'] > 0 ? $this->r($payroll['overtime_hours'] / $covers['value'] * 100) : 0.0,
            'overtime_hours' => $payroll['overtime_hours'],
            'covers'         => $covers['value'],
            'source'         => 'fnb_metrics:M-09',
        );
    }

    /**
     * M-10 — labour cost %.
     *
     * @return array<string, mixed>
     */
    public function m10LabourPct(string $month): array
    {
        $payroll = $this->payrollTotal($month);
        $revenue = $this->m01Revenue($month);

        return array(
            'value'   => $revenue['value'] > 0 ? $this->r($payroll['value'] / $revenue['value'], 4) : 0.0,
            'payroll' => $payroll['value'],
            'revenue' => $revenue['value'],
            'source'  => 'fnb_metrics:M-10',
        );
    }

    /**
     * Raw utility meters for a month.
     *
     * @return array<string, mixed>
     */
    public function utilityMeters(string $month): array
    {
        $table = $this->table('13.0');

        if ($this->errors->isError($table)) {
            return array('kwh' => 0.0, 'water_m3' => 0.0, 'lpg' => 0.0, 'error' => $this->message($table));
        }

        $headers = $table['header_index'];
        $kwh     = 0.0;
        $water   = 0.0;
        $lpg     = 0.0;

        foreach ($table['rows'] as $row) {
            if ($this->mon('13.0', $headers, $row, 'date') !== $month) {
                continue;
            }

            $kwh   += $this->num('13.0', $headers, $row, 'electricity_kwh');
            $water += $this->num('13.0', $headers, $row, 'water_m3');
            $lpg   += $this->num('13.0', $headers, $row, 'lpg_cylinders');
        }

        return array(
            'kwh'      => $this->r($kwh),
            'water_m3' => $this->r($water),
            'lpg'      => $this->r($lpg),
            'source'   => 'fnb_metrics:utility_meters',
        );
    }

    /**
     * Costed utility lines for a month, from the Assumptions rates.
     *
     * @param array<string, mixed>|null $assumptions Assumptions rows, when already read.
     * @return array<string, float>
     */
    public function utilitiesTotals(string $month, ?array $assumptions = null): array
    {
        $assumptions ??= $this->assumptions();

        $rates = array();

        foreach ($assumptions as $item => $meta) {
            $rates[ $item ] = FnbTableCatalogue::floatCell(is_array($meta) ? ( $meta['value'] ?? 0 ) : 0);
        }

        $meters = $this->utilityMeters($month);

        $electricity = $meters['kwh'] * ( $rates['Electricity tariff'] ?? 0.0 )
            + ( $rates['Electricity fixed charge'] ?? 0.0 );
        $water       = $meters['water_m3'] * ( $rates['Water rate'] ?? 0.0 )
            + ( $rates['Water fixed charge'] ?? 0.0 );
        $lpg         = $meters['lpg'] * ( $rates['LPG cylinder price (37.5 kg)'] ?? 0.0 );

        return array(
            'Electricity' => $this->r($electricity),
            'Water'       => $this->r($water),
            'LPG'         => $this->r($lpg),
            'kwh'         => $meters['kwh'],
            'water_m3'    => $meters['water_m3'],
            'lpg_units'   => $meters['lpg'],
        );
    }

    /**
     * M-11 — electricity per cover.
     *
     * @return array<string, mixed>
     */
    public function m11ElectricityPerCover(string $month): array
    {
        $meters = $this->utilityMeters($month);
        $covers = $this->m02Covers($month);

        return array(
            'value'  => $covers['value'] > 0 ? $this->r($meters['kwh'] / $covers['value'], 4) : 0.0,
            'kwh'    => $meters['kwh'],
            'covers' => $covers['value'],
            'source' => 'fnb_metrics:M-11',
        );
    }

    /**
     * Expense ledger lines for a month, by line and optionally by group.
     *
     * @return array<string, float>
     */
    public function expenseLinesByMonth(string $month, string $group = ''): array
    {
        $table = $this->table('14.0');

        if ($this->errors->isError($table)) {
            return array();
        }

        $headers = $table['header_index'];
        $lines   = array();

        foreach ($table['rows'] as $row) {
            // G-07: incurred, not paid — the ledger's own for_month column.
            if ($this->mon('14.0', $headers, $row, 'for_month') !== $month) {
                continue;
            }

            if ('' !== $group && strtolower($this->str('14.0', $headers, $row, 'group')) !== strtolower($group)) {
                continue;
            }

            $line   = $this->str('14.0', $headers, $row, 'expense_line');
            $amount = $this->num('14.0', $headers, $row, 'amount');

            $lines[ $line ] = ( $lines[ $line ] ?? 0.0 ) + $amount;
        }

        foreach ($lines as $line => $amount) {
            $lines[ $line ] = $this->r($amount);
        }

        return $lines;
    }

    /**
     * The Assumptions sheet as key-value rows.
     *
     * @return array<string, array{value: mixed, unit: string, source: string}>
     */
    public function assumptions(): array
    {
        $table = $this->table(FnbTableCatalogue::ASSUMPTIONS);

        if ($this->errors->isError($table)) {
            return array();
        }

        $headers = $table['header_index'];
        $out     = array();

        foreach ($table['rows'] as $row) {
            $item = $this->str(FnbTableCatalogue::ASSUMPTIONS, $headers, $row, 'item');

            if ('' === $item) {
                continue;
            }

            $out[ $item ] = array(
                'value'  => $this->cell(FnbTableCatalogue::ASSUMPTIONS, $headers, $row, 'value'),
                'unit'   => $this->str(FnbTableCatalogue::ASSUMPTIONS, $headers, $row, 'unit'),
                'source' => $this->str(FnbTableCatalogue::ASSUMPTIONS, $headers, $row, 'source'),
            );
        }

        return $out;
    }

    /**
     * The message of a platform error, as a string.
     */
    private function message(mixed $error): string
    {
        $normalised = $this->errors->normalize($error);

        return (string) ( $normalised['message'] ?? 'The table could not be read.' );
    }

    // ─── M-12 … M-17: demand and stock ───────────────────────────────────

    /**
     * M-12 — expected covers: the same weekday's average over the last four
     * weeks, plus group bookings of 15 or more guests.
     *
     * @return array<string, mixed>
     */
    public function m12ExpectedCovers(string $date): array
    {
        $weekday   = (string) gmdate('N', (int) strtotime($date . ' 12:00:00 UTC'));
        $table     = $this->table('7.0');
        $coversAvg = 0.0;
        $count     = 0;

        if (! $this->errors->isError($table)) {
            $headers = $table['header_index'];
            $start   = (string) gmdate('Y-m-d', (int) strtotime($date . ' -28 days'));
            $values  = array();

            foreach ($table['rows'] as $row) {
                $rowDate = $this->day('7.0', $headers, $row, 'date');

                if ('' === $rowDate || $rowDate < $start || $rowDate >= $date) {
                    continue;
                }

                if ((string) gmdate('N', (int) strtotime($rowDate . ' 12:00:00 UTC')) !== $weekday) {
                    continue;
                }

                $values[] = $this->num('7.0', $headers, $row, 'lunch_covers')
                    + $this->num('7.0', $headers, $row, 'dinner_covers')
                    + $this->num('7.0', $headers, $row, 'bar_only_guests');
            }

            $count = count($values);

            if ($count > 0) {
                $coversAvg = array_sum($values) / $count;
            }
        }

        $groups   = 0.0;
        $bookings = $this->table('16.0');

        if (! $this->errors->isError($bookings)) {
            $headers = $bookings['header_index'];

            foreach ($bookings['rows'] as $row) {
                if ($this->day('16.0', $headers, $row, 'date') !== $date) {
                    continue;
                }

                $guests = $this->num('16.0', $headers, $row, 'guests');

                if ($guests >= 15) {
                    $groups += $guests;
                }
            }
        }

        return array(
            'value'            => $this->r($coversAvg + $groups),
            'same_weekday_avg' => $this->r($coversAvg, 4),
            'weekday_count'    => $count,
            'group_guests'     => $this->r($groups),
            'source'           => 'fnb_metrics:M-12',
        );
    }

    /**
     * Dish share — units sold over covers for the same weekday, last four weeks.
     */
    public function dishShare(string $itemId, string $date): float
    {
        $weekday = (string) gmdate('N', (int) strtotime($date . ' 12:00:00 UTC'));
        $start   = (string) gmdate('Y-m-d', (int) strtotime($date . ' -28 days'));

        $units  = 0.0;
        $covers = 0.0;

        $sales = $this->table('6.0');

        if (! $this->errors->isError($sales)) {
            $headers = $sales['header_index'];

            foreach ($sales['rows'] as $row) {
                $rowDate = $this->day('6.0', $headers, $row, 'date');

                if ('' === $rowDate || $rowDate < $start || $rowDate >= $date) {
                    continue;
                }

                if ((string) gmdate('N', (int) strtotime($rowDate . ' 12:00:00 UTC')) !== $weekday) {
                    continue;
                }

                if ($this->str('6.0', $headers, $row, 'item_id') === $itemId) {
                    $units += $this->num('6.0', $headers, $row, 'qty_sold');
                }
            }
        }

        $coversTable = $this->table('7.0');

        if (! $this->errors->isError($coversTable)) {
            $headers = $coversTable['header_index'];

            foreach ($coversTable['rows'] as $row) {
                $rowDate = $this->day('7.0', $headers, $row, 'date');

                if ('' === $rowDate || $rowDate < $start || $rowDate >= $date) {
                    continue;
                }

                if ((string) gmdate('N', (int) strtotime($rowDate . ' 12:00:00 UTC')) !== $weekday) {
                    continue;
                }

                $covers += $this->num('7.0', $headers, $row, 'lunch_covers')
                    + $this->num('7.0', $headers, $row, 'dinner_covers')
                    + $this->num('7.0', $headers, $row, 'bar_only_guests');
            }
        }

        return $covers > 0 ? $units / $covers : 0.0;
    }

    /**
     * M-13 — expected portions for an item on a date.
     *
     * @return array<string, mixed>
     */
    public function m13ExpectedPortions(string $itemId, string $date): array
    {
        $covers = $this->m12ExpectedCovers($date);
        $share  = $this->dishShare($itemId, $date);

        return array(
            'value'           => $this->r($covers['value'] * $share, 4),
            'expected_covers' => $covers['value'],
            'dish_share'      => $this->r($share, 6),
            'source'          => 'fnb_metrics:M-13',
        );
    }

    /**
     * M-14 — days of cover: stock on hand over average daily use (14 days).
     *
     * Pinned: the closing value is the **last matching row in file order** whose
     * count date is on or before the as-of date, not the latest by date — the
     * WordPress loop overwrites as it walks. A workbook in date order behaves as
     * you would expect; an unordered one does not, and that is the original.
     *
     * @return array<string, mixed>
     */
    public function m14DaysOfCover(string $ingredientId, string $asOf = ''): array
    {
        if ('' === $asOf) {
            $asOf = $this->settings->asOfDate();
        }

        $closing = 0.0;
        $used14  = 0.0;
        $start   = (string) gmdate('Y-m-d', (int) strtotime($asOf . ' -14 days'));

        $counts = $this->table('9.0');

        if (! $this->errors->isError($counts)) {
            $headers = $counts['header_index'];

            foreach ($counts['rows'] as $row) {
                if ($this->str('9.0', $headers, $row, 'ingredient_id') !== $ingredientId) {
                    continue;
                }

                $rowDate = $this->day('9.0', $headers, $row, 'count_date');

                if ('' !== $rowDate && $rowDate <= $asOf) {
                    $closing = $this->num('9.0', $headers, $row, 'closing');
                }

                $period = $this->day('9.0', $headers, $row, 'period_start');

                if ('' !== $period && $period >= $start && $period <= $asOf) {
                    $used14 += $this->num('9.0', $headers, $row, 'used');
                }
            }
        }

        $dailyUse = $used14 / 14;

        return array(
            'value'         => $dailyUse > 0 ? $this->r($closing / $dailyUse) : 0.0,
            'stock_on_hand' => $this->r($closing),
            'daily_use_14d' => $this->r($dailyUse, 4),
            'source'        => 'fnb_metrics:M-14',
        );
    }

    /**
     * M-15 — stock used for an ingredient over a period.
     *
     * The **first** matching count row answers; the original returns inside the
     * loop. A period that spans two counts therefore reports the first, which is
     * pinned rather than summed.
     *
     * @return array<string, mixed>
     */
    public function m15StockUsed(string $ingredientId, ?string $from = null, ?string $to = null): array
    {
        $table = $this->table('9.0');

        if ($this->errors->isError($table)) {
            return array('value' => 0.0, 'error' => $this->message($table));
        }

        $headers = $table['header_index'];

        foreach ($table['rows'] as $row) {
            if ($this->str('9.0', $headers, $row, 'ingredient_id') !== $ingredientId) {
                continue;
            }

            $period = $this->day('9.0', $headers, $row, 'period_start');

            if (null !== $from && ( '' === $period || $period < $from )) {
                continue;
            }

            if (null !== $to && '' !== $period && $period > $to) {
                continue;
            }

            $opening = $this->num('9.0', $headers, $row, 'opening');
            $bought  = $this->num('9.0', $headers, $row, 'bought');
            $closing = $this->num('9.0', $headers, $row, 'closing');

            return array(
                'value'   => $this->r($opening + $bought - $closing),
                'opening' => $this->r($opening),
                'bought'  => $this->r($bought),
                'closing' => $this->r($closing),
                'period'  => $period,
                'source'  => 'fnb_metrics:M-15',
            );
        }

        return array('value' => 0.0, 'source' => 'fnb_metrics:M-15');
    }

    /**
     * M-16 — expected use: sum of units sold × recipe quantity per portion.
     *
     * @return array<string, mixed>
     */
    public function m16ExpectedUse(string $ingredientId, string $month): array
    {
        $recipes = $this->table('2.0');
        $sales   = $this->table('6.0');

        if ($this->errors->isError($recipes) || $this->errors->isError($sales)) {
            return array('value' => 0.0, 'error' => 'Recipes or sales table unavailable.');
        }

        $qtyPerItem = array();
        $rHeaders   = $recipes['header_index'];

        foreach ($recipes['rows'] as $row) {
            if ($this->str('2.0', $rHeaders, $row, 'ingredient_id') !== $ingredientId) {
                continue;
            }

            $qtyPerItem[ $this->str('2.0', $rHeaders, $row, 'item_id') ] = $this->num('2.0', $rHeaders, $row, 'qty_per_portion');
        }

        $total    = 0.0;
        $sHeaders = $sales['header_index'];

        foreach ($sales['rows'] as $row) {
            if ($this->mon('6.0', $sHeaders, $row, 'date') !== $month) {
                continue;
            }

            $itemId = $this->str('6.0', $sHeaders, $row, 'item_id');

            if (! isset($qtyPerItem[ $itemId ])) {
                continue;
            }

            $total += $this->num('6.0', $sHeaders, $row, 'qty_sold') * $qtyPerItem[ $itemId ];
        }

        return array('value' => $this->r($total), 'source' => 'fnb_metrics:M-16');
    }

    /**
     * M-17 — unexplained variance: stock used − expected use − logged waste.
     *
     * @return array<string, mixed>
     */
    public function m17UnexplainedVariance(string $ingredientId, ?string $from = null, ?string $to = null): array
    {
        $used     = $this->m15StockUsed($ingredientId, $from, $to);
        $month    = null !== $from ? substr($from, 0, 7) : '';
        $expected = '' !== $month ? $this->m16ExpectedUse($ingredientId, $month) : array('value' => 0.0);

        $waste      = 0.0;
        $wasteTable = $this->table('10.0');

        if (! $this->errors->isError($wasteTable)) {
            $headers = $wasteTable['header_index'];

            foreach ($wasteTable['rows'] as $row) {
                if ($this->str('10.0', $headers, $row, 'ingredient_id') !== $ingredientId) {
                    continue;
                }

                $date = $this->day('10.0', $headers, $row, 'date');

                if (null !== $from && ( '' === $date || $date < $from )) {
                    continue;
                }

                if (null !== $to && '' !== $date && $date > $to) {
                    continue;
                }

                $waste += $this->num('10.0', $headers, $row, 'qty');
            }
        }

        return array(
            'value'        => $this->r($used['value'] - $expected['value'] - $waste),
            'stock_used'   => $used['value'],
            'expected_use' => $expected['value'],
            'logged_waste' => $this->r($waste),
            'source'       => 'fnb_metrics:M-17',
        );
    }

    // ─── M-18 … M-21: waste, price, budget, margin ───────────────────────

    /**
     * M-18 — waste %: logged waste value over stock used value.
     *
     * @return array<string, mixed>
     */
    public function m18WastePct(string $month): array
    {
        $wasteValue = 0.0;
        $byReason   = array();
        $table      = $this->table('10.0');

        if (! $this->errors->isError($table)) {
            $headers = $table['header_index'];

            foreach ($table['rows'] as $row) {
                if ($this->mon('10.0', $headers, $row, 'date') !== $month) {
                    continue;
                }

                $value  = $this->num('10.0', $headers, $row, 'value');
                $reason = $this->str('10.0', $headers, $row, 'reason');

                $wasteValue += $value;
                $byReason[ $reason ] = ( $byReason[ $reason ] ?? 0.0 ) + $value;
            }
        }

        $usedFood = $this->stockUsed($month, 'Food');
        $usedBev  = $this->stockUsed($month, 'Beverage');
        $used     = $usedFood['value'] + $usedBev['value'];

        foreach ($byReason as $reason => $amount) {
            $byReason[ $reason ] = $this->r($amount);
        }

        return array(
            'value'       => $used > 0 ? $this->r($wasteValue / $used, 4) : 0.0,
            'waste_value' => $this->r($wasteValue),
            'stock_used'  => $this->r($used),
            'by_reason'   => $byReason,
            'source'      => 'fnb_metrics:M-18',
        );
    }

    /**
     * A supplier's unit price for an ingredient as of a date.
     */
    public function unitPriceAsOf(string $ingredientId, string $asOf): float
    {
        $table = $this->table('5.0');

        if ($this->errors->isError($table)) {
            return 0.0;
        }

        $headers   = $table['header_index'];
        $bestDate  = '';
        $bestPrice = 0.0;

        foreach ($table['rows'] as $row) {
            if ($this->str('5.0', $headers, $row, 'ingredient_id') !== $ingredientId) {
                continue;
            }

            $effective = $this->day('5.0', $headers, $row, 'effective_from');

            if ('' === $effective || $effective > $asOf) {
                continue;
            }

            if ('' === $bestDate || $effective > $bestDate) {
                $bestDate  = $effective;
                $packPrice = $this->num('5.0', $headers, $row, 'pack_price');
                $packSize  = $this->num('5.0', $headers, $row, 'pack_size');
                $bestPrice = $packSize > 0 ? $packPrice / $packSize : 0.0;
            }
        }

        return $bestPrice;
    }

    /**
     * M-19 — price change impact.
     *
     * @return array<string, mixed>
     */
    public function m19PriceChangeImpact(string $month): array
    {
        $prices  = $this->table('5.0');
        $impact  = 0.0;
        $changes = array();

        if ($this->errors->isError($prices)) {
            return array('value' => 0.0, 'changes' => array(), 'error' => $this->message($prices));
        }

        $monthStart = $month . '-01';
        $monthEnd   = (string) gmdate('Y-m-t', (int) strtotime($monthStart));

        $headers = $prices['header_index'];
        $series  = array();

        foreach ($prices['rows'] as $row) {
            $ingredient = $this->str('5.0', $headers, $row, 'ingredient_id');
            $effective  = $this->day('5.0', $headers, $row, 'effective_from');

            if ('' === $effective) {
                continue;
            }

            $packPrice = $this->num('5.0', $headers, $row, 'pack_price');
            $packSize  = $this->num('5.0', $headers, $row, 'pack_size');

            $series[ $ingredient ][] = array(
                'effective' => $effective,
                'unit'      => $packSize > 0 ? $packPrice / $packSize : 0.0,
                'pack'      => $packPrice,
            );
        }

        $purchases = $this->table('8.0');

        if ($this->errors->isError($purchases)) {
            return array('value' => 0.0, 'changes' => array(), 'error' => $this->message($purchases));
        }

        $pHeaders = $purchases['header_index'];

        foreach ($series as $ingredient => $points) {
            usort(
                $points,
                static fn (array $a, array $b): int => strcmp($a['effective'], $b['effective'])
            );

            $pointCount = count($points);

            for ($i = 1; $i < $pointCount; $i++) {
                $changeDate = $points[ $i ]['effective'];

                if ($changeDate < $monthStart || $changeDate > $monthEnd) {
                    continue;
                }

                $delta = $points[ $i ]['unit'] - $points[ $i - 1 ]['unit'];

                if ($delta <= 0) {
                    continue;
                }

                // Quantity bought at the new price: deliveries from the change
                // date to the next change (or a year out).
                $next = $points[ $i + 1 ]['effective'] ?? gmdate('Y-m-d', (int) strtotime('+1 year'));
                $qty  = 0.0;

                foreach ($purchases['rows'] as $row) {
                    if ($this->str('8.0', $pHeaders, $row, 'ingredient_id') !== $ingredient) {
                        continue;
                    }

                    $delivery = $this->day('8.0', $pHeaders, $row, 'delivery_date');

                    if ('' === $delivery || $delivery < $changeDate || $delivery >= $next) {
                        continue;
                    }

                    $qty += $this->num('8.0', $pHeaders, $row, 'qty_delivered');
                }

                if ($qty > 0) {
                    $lineImpact = $delta * $qty;
                    $impact    += $lineImpact;
                    $changes[]  = array(
                        'ingredient_id'  => $ingredient,
                        'effective_from' => $changeDate,
                        'old_unit_price' => $this->r($points[ $i - 1 ]['unit'], 2),
                        'new_unit_price' => $this->r($points[ $i ]['unit'], 2),
                        'qty_bought'     => $this->r($qty),
                        'impact'         => $this->r($lineImpact),
                    );
                }
            }
        }

        return array('value' => $this->r($impact), 'changes' => $changes, 'source' => 'fnb_metrics:M-19');
    }

    /**
     * M-20 — budget variance by line: actual − budget.
     *
     * A budget line with no actual keeps `actual` and `variance` null rather
     * than asserting zero: "not recorded" is not "nothing spent".
     *
     * @return array<string, mixed>
     */
    public function m20BudgetVariance(string $month): array
    {
        $budgets = $this->table('15.0');
        $rows    = array();

        if ($this->errors->isError($budgets)) {
            return array('rows' => array(), 'error' => $this->message($budgets));
        }

        $actualMap = $this->actualByBudgetLine($month);
        $headers   = $budgets['header_index'];

        foreach ($budgets['rows'] as $row) {
            if ($this->mon('15.0', $headers, $row, 'month') !== $month) {
                continue;
            }

            $line    = $this->str('15.0', $headers, $row, 'line');
            $budget  = $this->num('15.0', $headers, $row, 'budget');
            $actual  = $actualMap[ $line ] ?? null;

            $rows[] = array(
                'line'     => $line,
                'budget'   => $this->r($budget),
                'actual'   => null === $actual ? null : $this->r($actual),
                'variance' => null === $actual ? null : $this->r($actual - $budget),
            );
        }

        return array('rows' => $rows, 'source' => 'fnb_metrics:M-20');
    }

    /**
     * Actuals keyed by budget line for a month.
     *
     * @return array<string, float>
     */
    public function actualByBudgetLine(string $month): array
    {
        $actual = array();

        $actual['Food revenue']     = $this->m01Revenue($month, 'Food')['value'];
        $actual['Beverage revenue'] = $this->m01Revenue($month, 'Beverage')['value'];

        $actual['Food cost']     = $this->stockUsed($month, 'Food')['value'];
        $actual['Beverage cost'] = $this->stockUsed($month, 'Beverage')['value'];

        $actual['Payroll'] = $this->payrollTotal($month)['value'];

        $utilities = $this->utilitiesTotals($month);

        $actual['Electricity'] = $utilities['Electricity'];
        $actual['Water']       = $utilities['Water'];
        $actual['LPG']         = $utilities['LPG'];

        foreach ($this->expenseLinesByMonth($month, 'Operating') as $line => $amount) {
            $actual[ $line ] = $amount;
        }

        return $actual;
    }

    /**
     * M-21 — dish margin: menu price minus the recipe cost at current prices.
     *
     * The recipe cost resolves in three steps, as the original: the menu's own
     * precomputed column, then the recipes' cost-per-portion column, then a
     * recomputation from quantity × the supplier's latest unit price. The
     * `recipe_cost_source` says which one answered.
     *
     * @return array<string, mixed>
     */
    public function m21DishMargin(string $itemId, string $month = ''): array
    {
        $menu = $this->table('1.0');

        if ($this->errors->isError($menu)) {
            return array('value' => 0.0, 'error' => $this->message($menu));
        }

        $headers        = $menu['header_index'];
        $price          = 0.0;
        $type           = '';
        $menuRecipeCost = 0.0;

        foreach ($menu['rows'] as $row) {
            if ($this->str('1.0', $headers, $row, 'item_id') === $itemId) {
                $price          = $this->num('1.0', $headers, $row, 'price');
                $type           = $this->str('1.0', $headers, $row, 'type');
                $menuRecipeCost = $this->num('1.0', $headers, $row, 'recipe_cost');
                break;
            }
        }

        $asOf = $this->settings->asOfDate();

        $recipeCost = $menuRecipeCost;
        $costSource = $recipeCost > 0 ? 'menu' : 'none';

        $recipes  = $this->table('2.0');
        $rHeaders = $this->errors->isError($recipes) ? null : $recipes['header_index'];

        if ($recipeCost <= 0 && null !== $rHeaders) {
            foreach ($recipes['rows'] as $row) {
                if ($this->str('2.0', $rHeaders, $row, 'item_id') !== $itemId) {
                    continue;
                }

                $recipeCost += $this->num('2.0', $rHeaders, $row, 'cost_per_portion');
            }

            if ($recipeCost > 0) {
                $costSource = 'recipes';
            }
        }

        if ($recipeCost <= 0 && null !== $rHeaders) {
            foreach ($recipes['rows'] as $row) {
                if ($this->str('2.0', $rHeaders, $row, 'item_id') !== $itemId) {
                    continue;
                }

                $ingredientId = $this->str('2.0', $rHeaders, $row, 'ingredient_id');
                $qty          = $this->num('2.0', $rHeaders, $row, 'qty_per_portion');

                if ('' === $ingredientId || $qty <= 0) {
                    continue;
                }

                $recipeCost += $qty * $this->unitPriceAsOf($ingredientId, $asOf);
            }

            if ($recipeCost > 0) {
                $costSource = 'supplier_prices';
            }
        }

        $share = 0.0;

        if ('' !== $month) {
            $sales = $this->table('6.0');

            if (! $this->errors->isError($sales)) {
                $sHeaders    = $sales['header_index'];
                $typeNorm    = strtolower(trim($type));
                $units       = 0.0;
                $allUnits    = 0.0;
                $fallbackQty = 0.0;
                $fallbackAll = 0.0;

                foreach ($sales['rows'] as $row) {
                    if ($this->mon('6.0', $sHeaders, $row, 'date') !== $month) {
                        continue;
                    }

                    $qty      = $this->num('6.0', $sHeaders, $row, 'qty_sold');
                    $isItem   = $this->str('6.0', $sHeaders, $row, 'item_id') === $itemId;
                    $rowType  = strtolower(trim($this->str('6.0', $sHeaders, $row, 'type')));

                    $fallbackAll += $qty;

                    if ($isItem) {
                        $fallbackQty += $qty;
                    }

                    if ('' === $typeNorm || $rowType === $typeNorm) {
                        $allUnits += $qty;

                        if ($isItem) {
                            $units += $qty;
                        }
                    }
                }

                if ($allUnits > 0) {
                    $share = $units / $allUnits;
                } elseif ($fallbackAll > 0) {
                    // Type metadata missing or mismatched: share of all units.
                    $share = $fallbackQty / $fallbackAll;
                }
            }
        }

        $margin = $price - $recipeCost;

        return array(
            'value'              => $this->r($margin),
            'menu_price'         => $this->r($price),
            'recipe_cost'        => $this->r($recipeCost),
            'recipe_cost_source' => $costSource,
            'cost_pct'           => $price > 0 ? $this->r($recipeCost / $price, 4) : 0.0,
            'type'               => $type,
            'popularity_share'   => $this->r($share, 6),
            'source'             => 'fnb_metrics:M-21',
        );
    }

    /**
     * Menu-engineering quadrant (Kasavana & Smith).
     *
     * @return array<string, mixed>
     */
    public function menuEngineering(string $month): array
    {
        $menu = $this->table('1.0');

        if ($this->errors->isError($menu)) {
            return array('rows' => array(), 'error' => $this->message($menu));
        }

        $headers = $menu['header_index'];
        $items   = array();

        foreach ($menu['rows'] as $row) {
            $itemId = $this->str('1.0', $headers, $row, 'item_id');

            if ('' === $itemId) {
                continue;
            }

            $margin            = $this->m21DishMargin($itemId, $month);
            $items[ $itemId ] = array(
                'item_id'          => $itemId,
                'item'             => $this->str('1.0', $headers, $row, 'item'),
                'section'          => $this->str('1.0', $headers, $row, 'section'),
                'type'             => $this->str('1.0', $headers, $row, 'type'),
                'margin'           => $margin['value'],
                'cost_pct'         => $margin['cost_pct'],
                'popularity_share' => $margin['popularity_share'],
            );
        }

        if (array() === $items) {
            return array('rows' => array(), 'source' => 'fnb_metrics:menu_engineering');
        }

        $avgShare  = array_sum(array_column($items, 'popularity_share')) / count($items);
        $avgMargin = array_sum(array_column($items, 'margin')) / count($items);

        $rows = array();

        foreach ($items as $item) {
            $popular    = $item['popularity_share'] >= $avgShare;
            $profitable = $item['margin'] >= $avgMargin;

            if ($popular && $profitable) {
                $quadrant = 'Star';
                $action   = 'Protect and promote';
            } elseif ($popular && ! $profitable) {
                $quadrant = 'Plowhorse';
                $action   = 'Re-price or re-engineer';
            } elseif (! $popular && $profitable) {
                $quadrant = 'Puzzle';
                $action   = 'Reposition on the menu';
            } else {
                $quadrant = 'Dog';
                $action   = 'Remove or rework';
            }

            $item['quadrant'] = $quadrant;
            $item['action']   = $action;
            $rows[]           = $item;
        }

        return array(
            'rows'           => $rows,
            'average_share'  => $this->r($avgShare, 6),
            'average_margin' => $this->r($avgMargin),
            'source'         => 'fnb_metrics:menu_engineering',
        );
    }

    // ─── Dispatch ─────────────────────────────────────────────────────────

    /**
     * Dispatch a metric id with arguments.
     *
     * @param array<string, mixed> $args Metric arguments.
     * @return array<string, mixed>|mixed The metric, or the platform's error object.
     */
    public function calculate(string $metricId, array $args = array()): mixed
    {
        $month      = $this->arg($args, 'month');
        $date       = $this->arg($args, 'date');
        $item       = $this->arg($args, 'item_id');
        $ingredient = $this->arg($args, 'ingredient_id');
        $type       = isset($args['type']) ? $this->arg($args, 'type') : null;
        $from       = isset($args['from']) ? $this->arg($args, 'from') : null;
        $to         = isset($args['to']) ? $this->arg($args, 'to') : null;

        switch ($metricId) {
            case 'M-01':
                $result = $this->m01Revenue($month, $type);
                break;

            case 'M-02':
                $result = $this->m02Covers($month);
                break;

            case 'M-03':
                $result = $this->m03AvgSpend($month);
                break;

            case 'M-04':
                $result = $this->m04FoodCostPct($month);
                break;

            case 'M-05':
                $result = $this->m05BeverageCostPct($month);
                break;

            case 'M-06':
                $result = $this->m06CostPerCover($month);
                break;

            case 'M-09':
                $result = $this->m09OvertimePer100Covers($month);
                break;

            case 'M-10':
                $result = $this->m10LabourPct($month);
                break;

            case 'M-11':
                $result = $this->m11ElectricityPerCover($month);
                break;

            case 'M-12':
                if ('' === $date) {
                    return $this->errors->create('missing_date', 'M-12 requires a date.');
                }

                $result = $this->m12ExpectedCovers($date);
                break;

            case 'M-13':
                if ('' === $item || '' === $date) {
                    return $this->errors->create('missing_args', 'M-13 requires item_id and date.');
                }

                $result = $this->m13ExpectedPortions($item, $date);
                break;

            case 'M-14':
                if ('' === $ingredient) {
                    return $this->errors->create('missing_ingredient', 'M-14 requires ingredient_id.');
                }

                $result = $this->m14DaysOfCover($ingredient, $this->arg($args, 'as_of'));
                break;

            case 'M-15':
                if ('' === $ingredient) {
                    return $this->errors->create('missing_ingredient', 'M-15 requires ingredient_id.');
                }

                $result = $this->m15StockUsed($ingredient, $from, $to);
                break;

            case 'M-16':
                if ('' === $ingredient) {
                    return $this->errors->create('missing_ingredient', 'M-16 requires ingredient_id.');
                }

                $result = $this->m16ExpectedUse($ingredient, $month);
                break;

            case 'M-17':
                if ('' === $ingredient) {
                    return $this->errors->create('missing_ingredient', 'M-17 requires ingredient_id.');
                }

                $result = $this->m17UnexplainedVariance($ingredient, $from, $to);
                break;

            case 'M-18':
                $result = $this->m18WastePct($month);
                break;

            case 'M-19':
                $result = $this->m19PriceChangeImpact($month);
                break;

            case 'M-20':
                $result = $this->m20BudgetVariance($month);
                break;

            case 'M-21':
                if ('' === $item) {
                    return $this->errors->create('missing_item', 'M-21 requires item_id.');
                }

                $result = $this->m21DishMargin($item, $month);
                break;

            case 'M-07':
            case 'M-08':
                $prevCost   = isset($args['prev_cost']) ? (float) $args['prev_cost'] : 0.0;
                $prevCovers = isset($args['prev_covers']) ? (float) $args['prev_covers'] : 0.0;
                $curCost    = isset($args['cur_cost']) ? (float) $args['cur_cost'] : 0.0;
                $curCovers  = isset($args['cur_covers']) ? (float) $args['cur_covers'] : 0.0;
                $volume     = $this->m07VolumeEffect($prevCost, $prevCovers, $curCovers);

                $result = array(
                    'volume_effect'    => $volume,
                    'per_cover_effect' => $this->m08PerCoverEffect($curCost, $prevCost, $volume),
                    'source'           => 'fnb_metrics:M-07/M-08',
                );
                break;

            default:
                return $this->unknownMetric($metricId);
        }

        if (isset($result['error'])) {
            return $this->errors->create('metric_error', (string) $result['error']);
        }

        $result['metric_id'] = $metricId;

        return $result;
    }

    /**
     * A recoverable unknown-metric error naming the valid ids and the nearest.
     */
    private function unknownMetric(string $metricId): mixed
    {
        $valid   = array_keys(self::METRICS);
        $closest = $this->closestMetric($metricId);

        return $this->errors->create(
            'unknown_metric',
            sprintf(
                'Unknown metric "%1$s". Did you mean "%2$s"? Valid metric IDs are: %3$s.',
                $metricId,
                $closest,
                implode(', ', $valid)
            ),
            array('supplied' => $metricId, 'valid_metrics' => $valid, 'closest_metric' => $closest)
        );
    }

    /**
     * The valid id nearest to the supplied value, preferring a shared number.
     */
    private function closestMetric(string $metricId): string
    {
        $valid      = array_keys(self::METRICS);
        $normalised = strtoupper(trim($metricId));

        if ('' === $normalised) {
            return (string) reset($valid);
        }

        $best     = (string) reset($valid);
        $bestDist = PHP_INT_MAX;

        foreach ($valid as $candidate) {
            $dist    = levenshtein($normalised, $candidate);
            $number  = substr($candidate, 2);
            $supplied = substr($normalised, strlen($normalised) - strlen($number));

            if ($supplied === $number) {
                --$dist;
            }

            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best     = (string) $candidate;
            }
        }

        return $best;
    }

    /**
     * A sanitised string argument, `''` when absent.
     *
     * @param array<string, mixed> $args Arguments.
     */
    private function arg(array $args, string $key): string
    {
        $value = $args[ $key ] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
