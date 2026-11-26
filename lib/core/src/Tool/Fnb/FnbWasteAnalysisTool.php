<?php

/**
 * Waste analysis for a month, with an optional month-on-month change.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Waste_Analysis` (§164.74): M-18 plus
 * `month_on_month`, which compares **waste value**, not waste percentage.
 *
 * Two pins:
 *
 * - **The percentage change divides by the previous waste value, not its
 *   absolute value.** A negative baseline — possible when stock used is
 *   negative — therefore flips the sign, unlike `fnb_compare_periods`, which
 *   uses `abs()`. Two tools, two conventions, both kept.
 * - **`month_on_month` is `null` when no comparison month is given**, rather
 *   than an object of zeroes.
 *
 * Reasons are reported as logged and never inferred: a reason nobody wrote down
 * is not a finding (A2-08).
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

class FnbWasteAnalysisTool extends FnbTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbMetrics $metrics,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_waste_analysis';
    }

    public function getName(): string
    {
        return 'F&B Waste Analysis';
    }

    public function getDescription(): string
    {
        return 'Waste analysis for a month: waste % (M-18), total logged waste value, and the breakdown by logged reason. Report reasons as logged — never assume reasons that are not logged (A2-08).';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'month'      => array(
                    'type'        => 'string',
                    'description' => 'Month (Y-m).',
                ),
                'prev_month' => array(
                    'type'        => 'string',
                    'description' => 'Comparison month (Y-m) for month-on-month change.',
                ),
            ),
            'required'             => array('month'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $month     = $this->monthArg($arguments);
        $prevMonth = $this->monthArg($arguments, 'prev_month');

        if ('' === $month) {
            return $this->errors->validationFailed(
                'month (Y-m) is required.',
                array('month' => array('Give the month to analyse.')),
            );
        }

        $result = $this->metrics->m18WastePct($month);
        $change = null;

        if ('' !== $prevMonth) {
            $previous = $this->metrics->m18WastePct($prevMonth);
            $delta    = $result['waste_value'] - $previous['waste_value'];

            $change = array(
                'prev_value' => $previous['waste_value'],
                'change'     => round($delta, 2),
                'change_pct' => 0.0 !== (float) $previous['waste_value']
                    ? round($delta / $previous['waste_value'] * 100, 2)
                    : null,
            );
        }

        return $this->success(
            sprintf('%s waste: %s (%s of stock used).', $month, (string) $result['waste_value'], (string) $result['value']),
            array(
                'month'          => $month,
                'month_on_month' => $change,
                'value'          => $result['value'],
                'waste_value'    => $result['waste_value'],
                'stock_used'     => $result['stock_used'],
                'by_reason'      => $result['by_reason'],
                'source'         => $result['source'],
            )
        );
    }
}
