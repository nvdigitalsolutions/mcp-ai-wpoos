<?php

/**
 * Calculate a Food & Beverage metric.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Calculate_Metric` (§164.72). The WordPress tool is
 * a passthrough: it hands the whole argument array to
 * `WP_MCP_AI_Fnb_Metrics::calculate()` and wraps the result in `success`. The
 * port does the same, with the canonical envelope, so the engine is the one
 * place a formula lives.
 *
 * The schema enumerates `FnbMetrics::METRICS`, which is the same list the
 * dispatcher answers for — so a caller can only ask for a metric that exists,
 * and an id outside the enum still gets the recoverable
 * "did you mean …" error rather than a dead end.
 *
 * `as_of` is read by M-14 but is **not** a declared property here, exactly as in
 * WordPress: the engine falls back to the toolkit's configured as-of date.
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

class FnbCalculateMetricTool extends FnbTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbMetrics $metrics,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_calculate_metric';
    }

    public function getName(): string
    {
        return 'F&B Calculate Metric';
    }

    public function getDescription(): string
    {
        return 'Calculate an F&B metric (M-01…M-21) using the Metric definitions. Use this for every total, average, percentage and comparison — never compute figures yourself.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'metric_id'     => array(
                    'type'        => 'string',
                    'enum'        => array_keys(FnbMetrics::METRICS),
                    'description' => 'Metric ID from the Metric definitions sheet.',
                ),
                'month'         => array(
                    'type'        => 'string',
                    'description' => 'Month (Y-m), e.g. 2026-09.',
                ),
                'date'          => array(
                    'type'        => 'string',
                    'description' => 'Reference date (Y-m-d) for M-12/M-13.',
                ),
                'item_id'       => array(
                    'type'        => 'string',
                    'description' => 'Menu item ID for M-13/M-21.',
                ),
                'ingredient_id' => array(
                    'type'        => 'string',
                    'description' => 'Ingredient ID for M-14…M-17.',
                ),
                'type'          => array(
                    'type'        => 'string',
                    'enum'        => array('Food', 'Beverage'),
                    'description' => 'Revenue type filter for M-01.',
                ),
                'from'          => array(
                    'type'        => 'string',
                    'description' => 'Period start (Y-m-d) for M-15/M-17.',
                ),
                'to'            => array(
                    'type'        => 'string',
                    'description' => 'Period end (Y-m-d) for M-15/M-17.',
                ),
                'prev_cost'     => array(
                    'type'        => 'number',
                    'description' => 'Previous period cost for M-07/M-08.',
                ),
                'prev_covers'   => array(
                    'type'        => 'number',
                    'description' => 'Previous period covers for M-07/M-08.',
                ),
                'cur_cost'      => array(
                    'type'        => 'number',
                    'description' => 'Current period cost for M-08.',
                ),
                'cur_covers'    => array(
                    'type'        => 'number',
                    'description' => 'Current period covers for M-07/M-08.',
                ),
            ),
            'required'   => array('metric_id'),
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $metricId = Text::singleLine($this->scalar($arguments, 'metric_id'));

        $result = $this->metrics->calculate($metricId, $arguments);

        if ($this->errors->isError($result)) {
            return $result;
        }

        return $this->success(
            sprintf('%s: %s', $metricId, $this->label($metricId)),
            $result
        );
    }

    /**
     * The metric's human label, or the id when it is not in the catalogue.
     */
    private function label(string $metricId): string
    {
        return FnbMetrics::METRICS[ $metricId ] ?? 'metric';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function scalar(array $arguments, string $key): string
    {
        $value = $arguments[ $key ] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
