<?php

/**
 * Labour analysis for a month.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Labour_Analysis` (§164.74): payroll by department,
 * overtime, M-09 and M-10, plus an optional comparison of overtime growth
 * against covers growth.
 *
 * ## The comparison is a sentence, not a verdict
 *
 * `justified` reads "Overtime growth ≤ covers growth" or "Overtime growth
 * exceeds covers growth — check R-09". It is a **string**, and both branches are
 * included here rather than collapsed to a boolean because the WordPress tool
 * puts the whole sentence in front of a model, and the R-09 reference is the
 * actionable half.
 *
 * The test itself is:
 *
 * ```
 * prev_covers > 0
 *   && (covers − prev_covers) / prev_covers >= (overtime − prev_overtime) / max(prev_overtime, 1)
 * ```
 *
 * so the divisor guard is on the overtime side only, and a zero-cover previous
 * month answers "exceeds" rather than dividing by zero. Pinned.
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

class FnbLabourAnalysisTool extends FnbTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbMetrics $metrics,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_labour_analysis';
    }

    public function getName(): string
    {
        return 'F&B Labour Analysis';
    }

    public function getDescription(): string
    {
        return 'Labour analysis for a month: payroll by department, overtime hours and overtime per 100 covers (M-09), casual shifts, and labour cost % (M-10).';
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
                    'description' => 'Comparison month (Y-m) for overtime growth vs covers growth.',
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

        $payroll = $this->metrics->payrollTotal($month);
        $m09     = $this->metrics->m09OvertimePer100Covers($month);
        $m10     = $this->metrics->m10LabourPct($month);

        $comparison = null;

        if ('' !== $prevMonth) {
            $prevPayroll = $this->metrics->payrollTotal($prevMonth);
            $prevM09     = $this->metrics->m09OvertimePer100Covers($prevMonth);
            $covers      = $this->metrics->m02Covers($month);
            $prevCovers  = $this->metrics->m02Covers($prevMonth);

            $overtimeChange = $payroll['overtime_hours'] - $prevPayroll['overtime_hours'];
            $coversChange   = $covers['value'] - $prevCovers['value'];

            $justified = $prevCovers['value'] > 0
                && $coversChange / $prevCovers['value'] >= $overtimeChange / max($prevPayroll['overtime_hours'], 1)
                ? 'Overtime growth ≤ covers growth'
                : 'Overtime growth exceeds covers growth — check R-09';

            $comparison = array(
                'overtime_change'       => round($overtimeChange, 2),
                'covers_change'         => round($coversChange),
                'overtime_per_100_prev' => $prevM09['value'],
                'justified'             => $justified,
            );
        }

        return $this->success(
            sprintf('Labour for %s: %s payroll, %s overtime hours.', $month, (string) $payroll['value'], (string) $payroll['overtime_hours']),
            array(
                'month'                => $month,
                'total_payroll'        => $payroll['value'],
                'departments'          => $payroll['departments'],
                'overtime_hours'       => $payroll['overtime_hours'],
                'casual_shifts'        => $payroll['casual_shifts'],
                'm09_overtime_per_100' => $m09['value'],
                'm10_labour_pct'       => $m10['value'],
                'comparison'           => $comparison,
                'source'               => 'fnb_labour_analysis',
            )
        );
    }
}
