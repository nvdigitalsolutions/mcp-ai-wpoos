<?php

/**
 * Utilities per cover, with costed totals.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Utilities_Per_Cover` (§164.74): the raw meters, the
 * costed lines from the Assumptions rates, and per-cover usage.
 *
 * M-11 supplies the electricity figure; water and LPG are divided here with the
 * same `covers > 0` guard, at four decimals. `assumptions_source` is the literal
 * `"Assumptions table"` the original returns — a label, not a lookup, kept so a
 * consumer that prints it does not have to special-case this runtime.
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

class FnbUtilitiesPerCoverTool extends FnbTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbMetrics $metrics,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_utilities_per_cover';
    }

    public function getName(): string
    {
        return 'F&B Utilities Per Cover';
    }

    public function getDescription(): string
    {
        return 'Utilities analysis: electricity kWh per cover (M-11), water and LPG usage, and the costed totals from the Assumptions rates.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'month' => array(
                    'type'        => 'string',
                    'description' => 'Month (Y-m).',
                ),
            ),
            'required'             => array('month'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $month = $this->monthArg($arguments);

        if ('' === $month) {
            return $this->errors->validationFailed(
                'month (Y-m) is required.',
                array('month' => array('Give the month to analyse.')),
            );
        }

        $utilities = $this->metrics->utilitiesTotals($month);
        $m11       = $this->metrics->m11ElectricityPerCover($month);
        $covers    = $this->metrics->m02Covers($month);

        $perCover = array(
            'electricity_kwh' => $m11['value'],
            'water_m3'        => $covers['value'] > 0 ? round($utilities['water_m3'] / $covers['value'], 4) : 0.0,
            'lpg_cylinders'   => $covers['value'] > 0 ? round($utilities['lpg_units'] / $covers['value'], 4) : 0.0,
        );

        return $this->success(
            sprintf('Utilities for %s: %s kWh per cover.', $month, (string) $m11['value']),
            array(
                'month'              => $month,
                'meters'             => array(
                    'electricity_kwh' => $utilities['kwh'],
                    'water_m3'        => $utilities['water_m3'],
                    'lpg_cylinders'   => $utilities['lpg_units'],
                ),
                'costed'             => array(
                    'Electricity' => $utilities['Electricity'],
                    'Water'       => $utilities['Water'],
                    'LPG'         => $utilities['LPG'],
                ),
                'per_cover'          => $perCover,
                'covers'             => round($covers['value']),
                'assumptions_source' => 'Assumptions table',
                'source'             => 'fnb_utilities_per_cover',
            )
        );
    }
}
