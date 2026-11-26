<?php

/**
 * Statistical pattern analysis over a supplied dataset.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Analyze_Data_Patterns` (Proposal 164 section
 * 164.77). Four analyses share one summary block; the arithmetic is the
 * original's, including the parts a reader might expect to be different:
 *
 * - **Non-numeric entries are dropped, keys and all.** `is_numeric` keeps
 *   numeric strings, so `min`/`max` can return the *string* they were given
 *   (`"9"`, not `9`) while the mean and median are numeric. The payload shows
 *   both, exactly as WordPress does.
 * - **Frequency buckets round to two decimals**, so 1.001 and 1.004 share a
 *   bucket and the bucket reports the first value it saw.
 * - **Correlation is against position, not a second series** — the tool has one
 *   dataset, so Pearson's x is the index. Under two points it answers
 *   “insufficient data” with a zero coefficient.
 * - **Outliers use nearest-rank percentiles** (`ceil(p × n)`), not an
 *   interpolating quartile.
 * - **An unknown `analysis_type` adds no block**; it does not error.
 *
 * One crash is fixed rather than pinned: a one-element dataset divided by an
 * empty second half in `trend` and raised `DivisionByZeroError` (PHP 8). A
 * single point is `stable`, which is the only defensible trend, and the
 * readiness row records the deviation.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Tool\AbstractTool;

class AnalyzeDataPatternsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(ErrorFactoryInterface $e)
    {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'analyze_data_patterns';
    }

    public function getName(): string
    {
        return 'Analyze Data Patterns';
    }

    public function getDescription(): string
    {
        return 'Analyze datasets to identify trends, patterns, anomalies, and insights using statistical analysis. Non-numeric values are filtered out; an error is returned when no numeric data remains.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'dataset'       => array(
                    'type'        => 'array',
                    'description' => 'Array of data points to analyze.',
                    'items'       => array(
                        'type' => array('number', 'string'),
                    ),
                ),
                'analysis_type' => array(
                    'type'        => 'string',
                    'enum'        => array('trend', 'frequency', 'correlation', 'outliers'),
                    'description' => 'Type of analysis to perform.',
                    'default'     => 'trend',
                ),
            ),
            'required'             => array('dataset'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('read-only', 'idempotent', 'cacheable', 'local-only');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $dataset = isset($arguments['dataset']) && is_array($arguments['dataset'])
            ? $arguments['dataset']
            : array();
        $type    = $this->stringParam($arguments, 'analysis_type', 'trend');

        $numeric = array_filter($dataset, 'is_numeric');

        if (array() === $numeric) {
            return $this->errors->create(
                'no_numeric_data',
                'Dataset must contain numeric values.'
            );
        }

        $analysis = array(
            'count'  => count($numeric),
            'min'    => min($numeric),
            'max'    => max($numeric),
            'mean'   => array_sum($numeric) / count($numeric),
            'median' => $this->median($numeric),
            'range'  => max($numeric) - min($numeric),
        );

        if ('trend' === $type) {
            $analysis['trend'] = $this->trend($numeric);
        } elseif ('frequency' === $type) {
            $analysis['frequency'] = $this->frequency($numeric);
        } elseif ('correlation' === $type) {
            $analysis['correlation'] = $this->correlation($numeric);
        } elseif ('outliers' === $type) {
            $analysis['outliers'] = $this->outliers($numeric);
        }

        return $this->success(
            sprintf('Analyzed %d numeric value(s).', $analysis['count']),
            array(
                'analysis' => $analysis,
                'insights' => $this->insights($analysis),
            )
        );
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function median(array $data): float
    {
        sort($data);

        $count = count($data);
        $mid   = (int) floor($count / 2);

        return 0 === $count % 2
            ? ( (float) $data[ $mid - 1 ] + (float) $data[ $mid ] ) / 2
            : (float) $data[ $mid ];
    }

    /**
     * First half against second half, with the original's 10% band.
     *
     * @param array<int|string, mixed> $data
     */
    private function trend(array $data): string
    {
        $count = count($data);

        if ($count < 2) {
            return 'stable';
        }

        $split       = (int) ceil($count / 2);
        $first       = array_slice($data, 0, $split);
        $second      = array_slice($data, $split);
        $averageA    = array_sum($first) / count($first);
        $averageB    = array_sum($second) / count($second);

        if ($averageB > $averageA * 1.1) {
            return 'increasing';
        }

        if ($averageB < $averageA * 0.9) {
            return 'decreasing';
        }

        return 'stable';
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return list<array{value: float, count: int}>
     */
    private function frequency(array $data): array
    {
        $counts = array();

        foreach ($data as $value) {
            $key = (string) round((float) $value, 2);

            if (! isset($counts[ $key ])) {
                $counts[ $key ] = array('value' => (float) $value, 'count' => 0);
            }

            ++$counts[ $key ]['count'];
        }

        usort(
            $counts,
            static fn (array $a, array $b): int => $b['count'] <=> $a['count']
        );

        return array_slice($counts, 0, 10);
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return array{coefficient: float, interpretation: string}
     */
    private function correlation(array $data): array
    {
        $values = array_values(array_map('floatval', $data));
        $count  = count($values);

        if ($count < 2) {
            return array('coefficient' => 0.0, 'interpretation' => 'insufficient data');
        }

        $sumX  = 0.0;
        $sumY  = 0.0;
        $sumXy = 0.0;
        $sumX2 = 0.0;
        $sumY2 = 0.0;

        foreach ($values as $index => $y) {
            $x      = $index + 1;
            $sumX  += $x;
            $sumY  += $y;
            $sumXy += $x * $y;
            $sumX2 += $x * $x;
            $sumY2 += $y * $y;
        }

        $denominator = sqrt(( $count * $sumX2 - $sumX * $sumX ) * ( $count * $sumY2 - $sumY * $sumY ));
        $coefficient = $denominator > 0 ? ( $count * $sumXy - $sumX * $sumY ) / $denominator : 0.0;

        if ($coefficient > 0.7) {
            $interpretation = 'strong positive correlation';
        } elseif ($coefficient > 0.3) {
            $interpretation = 'moderate positive correlation';
        } elseif ($coefficient < -0.7) {
            $interpretation = 'strong negative correlation';
        } elseif ($coefficient < -0.3) {
            $interpretation = 'moderate negative correlation';
        } else {
            $interpretation = 'weak or no correlation';
        }

        return array(
            'coefficient'    => round($coefficient, 4),
            'interpretation' => $interpretation,
        );
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return array{outliers: list<float>, lower_bound: float, upper_bound: float}
     */
    private function outliers(array $data): array
    {
        $values = array_map('floatval', array_values($data));
        sort($values);

        $q1  = $this->percentile($values, 0.25);
        $q3  = $this->percentile($values, 0.75);
        $iqr = $q3 - $q1;

        $lower = $q1 - 1.5 * $iqr;
        $upper = $q3 + 1.5 * $iqr;
        $found = array();

        foreach ($values as $value) {
            if ($value < $lower || $value > $upper) {
                $found[] = $value;
            }
        }

        return array(
            'outliers'    => $found,
            'lower_bound' => round($lower, 4),
            'upper_bound' => round($upper, 4),
        );
    }

    /**
     * Nearest-rank percentile over an ascending list.
     *
     * @param list<float> $sorted
     */
    private function percentile(array $sorted, float $p): float
    {
        $count = count($sorted);

        if (0 === $count) {
            return 0.0;
        }

        $index = (int) ceil($p * $count);
        $index = max(1, min($index, $count));

        return $sorted[ $index - 1 ];
    }

    /**
     * @param array<string, mixed> $analysis
     *
     * @return list<string>
     */
    private function insights(array $analysis): array
    {
        $insights = array();

        if (isset($analysis['trend'])) {
            $insights[] = "Data shows a {$analysis['trend']} trend";
        }

        if ($analysis['range'] > $analysis['mean'] * 2) {
            $insights[] = 'High variance detected - data is widely spread';
        }

        return $insights;
    }
}
