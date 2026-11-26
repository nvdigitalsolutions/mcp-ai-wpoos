<?php

/**
 * Compare two months across the headline metrics.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Compare_Periods` (§164.72). For each of ten
 * metrics it computes both months, the absolute change and the percentage
 * change, then adds the Food and Beverage revenue split.
 *
 * Three decisions the original left implicit:
 *
 * - **A metric whose either month errors is dropped entirely**, not reported as
 *   zero. That is the original's `continue`, and it is right: "we could not read
 *   last month" and "last month was zero" are different answers.
 * - **The revenue split is not error-checked**, because it reads `['value']` off
 *   a metric that returns a zeroed array on failure. Pinned: a missing sales
 *   table therefore shows two zero rows rather than dropping them.
 * - **`change_pct` is null when the previous value is zero.** The divisor is
 *   `abs(prev)`, so a negative baseline still yields a same-signed percentage.
 *
 * ## The default months are derived here, not hardcoded
 *
 * The WordPress tool defaults to `2026-09` against `2026-08` — the demo
 * workbook's months. This runtime defaults to the current month against the one
 * before it, because a host with no arguments should not silently compare two
 * months that may not exist in its data. A caller that passes months gets the
 * identical behaviour.
 *
 * The split labels carry the configured currency, so an LKR venue still sees
 * "Food revenue (LKR)" while a venue in another currency is not mislabelled.
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

class FnbComparePeriodsTool extends FnbTool
{
    /** The metrics the comparison covers, in report order. */
    public const COMPARED = array('M-01', 'M-02', 'M-03', 'M-04', 'M-05', 'M-06', 'M-09', 'M-10', 'M-11', 'M-18');

    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbMetrics $metrics,
        protected readonly FnbSettings $settings,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_compare_periods';
    }

    public function getName(): string
    {
        return 'F&B Compare Periods';
    }

    public function getDescription(): string
    {
        return 'Compare two months across the headline metrics (revenue, covers, average spend, cost %, cost per cover, overtime, labour %, electricity, waste %) with change and % change. Defaults to the current month against the previous one.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'month'      => array(
                    'type'        => 'string',
                    'description' => 'Current month (Y-m). Defaults to the current month.',
                ),
                'prev_month' => array(
                    'type'        => 'string',
                    'description' => 'Previous month (Y-m). Defaults to the month before the current one.',
                ),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $month = $this->month($arguments, 'month', (string) gmdate('Y-m'));
        $prev  = $this->month(
            $arguments,
            'prev_month',
            (string) gmdate('Y-m', (int) strtotime(gmdate('Y-m-01') . ' -1 month'))
        );

        $metrics = array();

        foreach (self::COMPARED as $id) {
            $current  = $this->metrics->calculate($id, array('month' => $month));
            $previous = $this->metrics->calculate($id, array('month' => $prev));

            if ($this->errors->isError($current) || $this->errors->isError($previous)) {
                continue;
            }

            $currentValue  = (float) ( $current['value'] ?? 0.0 );
            $previousValue = (float) ( $previous['value'] ?? 0.0 );

            $metrics[ $id ] = array(
                'label'      => FnbMetrics::METRICS[ $id ],
                'current'    => $currentValue,
                'previous'   => $previousValue,
                'change'     => round($currentValue - $previousValue, 4),
                'change_pct' => 0.0 !== $previousValue
                    ? round(( $currentValue - $previousValue ) / abs($previousValue) * 100, 2)
                    : null,
            );
        }

        foreach (array('Food', 'Beverage') as $type) {
            $current  = $this->metrics->m01Revenue($month, $type);
            $previous = $this->metrics->m01Revenue($prev, $type);

            $metrics[ 'M-01-' . $type ] = array(
                'label'      => $this->splitLabel($type),
                'current'    => $current['value'],
                'previous'   => $previous['value'],
                'change'     => round($current['value'] - $previous['value'], 2),
                'change_pct' => 0.0 !== (float) $previous['value']
                    ? round(( $current['value'] - $previous['value'] ) / abs((float) $previous['value']) * 100, 2)
                    : null,
            );
        }

        return $this->success(
            sprintf('Compared %s with %s across %d metrics.', $month, $prev, count($metrics)),
            array(
                'month'      => $month,
                'prev_month' => $prev,
                'metrics'    => $metrics,
                'source'     => 'fnb_compare_periods',
            )
        );
    }

    /**
     * A `Y-m` argument, or the fallback when absent or malformed.
     *
     * WordPress substitutes rather than refusing, so a typo silently compares
     * the default months. Pinned.
     *
     * @param array<string, mixed> $arguments
     */
    private function month(array $arguments, string $key, string $fallback): string
    {
        $value = Text::singleLine(is_scalar($arguments[ $key ] ?? null) ? (string) $arguments[ $key ] : '');

        return 1 === preg_match('/^\d{4}-\d{2}$/', $value) ? $value : $fallback;
    }

    /**
     * The revenue split's label, with the configured currency when there is one.
     */
    private function splitLabel(string $type): string
    {
        $currency = $this->settings->currency();

        return '' !== $currency ? sprintf('%s revenue (%s)', $type, $currency) : $type . ' revenue';
    }
}
