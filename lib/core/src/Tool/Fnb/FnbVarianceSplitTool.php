<?php

/**
 * Split a cost change between the volume effect and the per-cover effect.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Variance_Split` (§164.74). M-07/M-08 applied
 * twice: once to the operating total, and once per line, so a rising cost is
 * attributed to more covers or to a higher cost per cover rather than asserted.
 *
 * Three pins:
 *
 * - **Cover counts are rounded to whole covers** in the payload (`round()` with
 *   no precision), because a fractional cover is an artefact of the fallback
 *   path, not a fact.
 * - **A line present this month but not last month is compared against zero**,
 *   so a new cost reads as entirely per-cover.
 * - **`lines` lists this month's lines only.** A line that disappeared is not
 *   reported, which is the original's choice and is worth knowing when reading a
 *   split that does not sum to the total change.
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

class FnbVarianceSplitTool extends FnbTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbMetrics $metrics,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_variance_split';
    }

    public function getName(): string
    {
        return 'F&B Variance Split';
    }

    public function getDescription(): string
    {
        return 'Split a cost change between two months into the volume effect (M-07: cover-count driven) and the per-cover effect (M-08: everything else).';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'month'      => array(
                    'type'        => 'string',
                    'description' => 'Current month (Y-m).',
                ),
                'prev_month' => array(
                    'type'        => 'string',
                    'description' => 'Previous month (Y-m).',
                ),
            ),
            'required'             => array('month', 'prev_month'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $month     = $this->monthArg($arguments);
        $prevMonth = $this->monthArg($arguments, 'prev_month');

        if ('' === $month || '' === $prevMonth) {
            return $this->errors->validationFailed(
                'Both month and prev_month (Y-m) are required.',
                array(
                    'month'      => array('Give the current month.'),
                    'prev_month' => array('Give the month to compare against.'),
                ),
            );
        }

        $current     = $this->metrics->totalOperatingCost($month);
        $previous    = $this->metrics->totalOperatingCost($prevMonth);
        $covers      = $this->metrics->m02Covers($month);
        $prevCovers  = $this->metrics->m02Covers($prevMonth);

        $volume   = $this->metrics->m07VolumeEffect($previous['total'], $prevCovers['value'], $covers['value']);
        $perCover = $this->metrics->m08PerCoverEffect($current['total'], $previous['total'], $volume);

        $lines = array();

        foreach ($current['lines'] as $line => $amount) {
            $previousAmount = $previous['lines'][ $line ] ?? 0.0;
            $change         = $amount - $previousAmount;
            $lineVolume     = $this->metrics->m07VolumeEffect($previousAmount, $prevCovers['value'], $covers['value']);

            $lines[] = array(
                'line'             => $line,
                'current'          => round($amount, 2),
                'previous'         => round($previousAmount, 2),
                'change'           => round($change, 2),
                'volume_effect'    => round($lineVolume, 2),
                'per_cover_effect' => round($change - $lineVolume, 2),
            );
        }

        return $this->success(
            sprintf('Split %s against %s.', $month, $prevMonth),
            array(
                'month'            => $month,
                'prev_month'       => $prevMonth,
                'total_change'     => round($current['total'] - $previous['total'], 2),
                'volume_effect'    => $volume,
                'per_cover_effect' => $perCover,
                'covers'           => round($covers['value']),
                'prev_covers'      => round($prevCovers['value']),
                'lines'            => $lines,
                'source'           => 'fnb_variance_split',
            )
        );
    }
}
