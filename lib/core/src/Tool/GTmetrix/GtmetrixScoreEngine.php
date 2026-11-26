<?php

/**
 * Deterministic GTmetrix report assessment: vitals, grades, budgets, impact.
 *
 * Port of `WP_MCP_AI_GTmetrix_Score_Engine` (Proposal 164 section 164.94).
 *
 * Everything here is arithmetic on values the API returned. Nothing calls the
 * API, nothing reads storage, and nothing depends on the host — which is why
 * the whole engine ports unchanged in behaviour and the tools stay thin.
 *
 * ## Corrections
 *
 * 1. **`budgetClass()` describes the next class, not the current one.**
 *    WordPress returned `next_class: <current class>` with
 *    `bytes_to_next: 0` for a page inside a class, and for a `very_heavy` page
 *    returned `next_class: heavy` with the number of bytes *over* the heavy
 *    limit. The pair therefore meant nothing in either case. The port reports
 *    the next heavier class (null for `very_heavy`) and the headroom left
 *    before leaving the current class.
 * 2. **Unknown metric values are unknown, not zero.** A `-1` (GTmetrix uses it
 *    for "not measured", e.g. `fully_loaded_time` with stop-onload) and an
 *    absent value both classify as `unknown`, never as a `good` zero.
 *
 * The thresholds are the 2026 Core Web Vitals table, and the budget classes are
 * the WordPress table (1 MiB / 50 requests, 2.5 MiB / 90, 5 MiB / 150), kept
 * verbatim because published guidance and existing saved reports refer to them.
 *
 * INP is a field metric. TBT is the lab proxy and cannot certify INP; the CWV
 * block says so in its note instead of implying a field measurement.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\GTmetrix;

final class GtmetrixScoreEngine
{
    /**
     * Core Web Vitals thresholds: good / poor boundaries.
     *
     * @var array<string, array{good: float, poor: float}>
     */
    public const THRESHOLDS = array(
        'lcp'          => array(
            'good' => 2500,
            'poor' => 4000,
        ),
        'cls'          => array(
            'good' => 0.1,
            'poor' => 0.25,
        ),
        'tbt'          => array(
            'good' => 200,
            'poor' => 600,
        ),
        'ttfb'         => array(
            'good' => 600,
            'poor' => 1800,
        ),
        'speed_index'  => array(
            'good' => 3400,
            'poor' => 5800,
        ),
        'onload'       => array(
            'good' => 3000,
            'poor' => 5000,
        ),
        'fully_loaded' => array(
            'good' => 3000,
            'poor' => 5000,
        ),
    );

    /**
     * Page-weight budget classes, lightest first. A page is in a class only
     * when it fits both its byte and request ceilings.
     *
     * @var array<string, array{max_bytes: int, max_requests: int}>
     */
    public const BUDGETS = array(
        'lean'       => array(
            'max_bytes'    => 1048576,
            'max_requests' => 50,
        ),
        'acceptable' => array(
            'max_bytes'    => 2621440,
            'max_requests' => 90,
        ),
        'heavy'      => array(
            'max_bytes'    => 5242880,
            'max_requests' => 150,
        ),
    );

    /**
     * Impact band → order, from None to High. The filter compares these
     * indices; `None` exists so an absent impact can be placed, not offered.
     *
     * @var array<string, int>
     */
    public const BAND_ORDER = array(
        'None'     => 0,
        'Low'      => 1,
        'Med-Low'  => 2,
        'Med'      => 3,
        'Med-High' => 4,
        'High'     => 5,
    );

    /**
     * Classify a metric value against its thresholds.
     *
     * @param string $metric One of the THRESHOLDS keys.
     * @param mixed  $value  Raw value; null, '' or a negative number is unknown.
     *
     * @return array{status: string, good: float, poor: float}
     */
    public static function assessMetric(string $metric, mixed $value): array
    {
        $thresholds = self::THRESHOLDS[ $metric ] ?? array('good' => 0.0, 'poor' => 0.0);

        if (! is_numeric($value) || (float) $value < 0) {
            return array(
                'status' => 'unknown',
                'good'   => (float) $thresholds['good'],
                'poor'   => (float) $thresholds['poor'],
            );
        }

        $number = (float) $value;

        if ($number <= $thresholds['good']) {
            $status = 'good';
        } elseif ($number < $thresholds['poor']) {
            $status = 'needs-improvement';
        } else {
            $status = 'poor';
        }

        return array(
            'status' => $status,
            'good'   => (float) $thresholds['good'],
            'poor'   => (float) $thresholds['poor'],
        );
    }

    /**
     * Assess the four report-visible Core Web Vitals.
     *
     * @param array<string, mixed> $flat Flat report attributes.
     *
     * @return array{metrics: array<string, array<string, mixed>>, overall: string, note: string}
     */
    public static function assessCwv(array $flat): array
    {
        $metrics = array(
            'lcp'  => array(
                'label' => 'Largest Contentful Paint',
                'value' => $flat['largest_contentful_paint'] ?? null,
            ),
            'tbt'  => array(
                'label' => 'Total Blocking Time (INP lab proxy)',
                'value' => $flat['total_blocking_time'] ?? null,
            ),
            'cls'  => array(
                'label' => 'Cumulative Layout Shift',
                'value' => $flat['cumulative_layout_shift'] ?? null,
            ),
            'ttfb' => array(
                'label' => 'Time to First Byte',
                'value' => $flat['time_to_first_byte'] ?? null,
            ),
        );

        $assessed = array();
        $known    = 0;
        $passing  = 0;

        foreach ($metrics as $key => $meta) {
            $row              = self::assessMetric($key, $meta['value']);
            $row['label']     = $meta['label'];
            $row['value']     = $meta['value'];
            $assessed[ $key ] = $row;

            if ('unknown' !== $row['status']) {
                ++$known;

                if ('good' === $row['status']) {
                    ++$passing;
                }
            }
        }

        if (0 === $known) {
            $overall = 'unknown';
        } elseif ($passing === $known) {
            $overall = 'pass';
        } else {
            $overall = 'fail';
        }

        return array(
            'metrics' => $assessed,
            'overall' => $overall,
            'note'    => 'INP is a field-data metric; TBT is the lab proxy. Lab data cannot certify INP.',
        );
    }

    /**
     * A 0–1 score as a GTmetrix letter grade (band floors inclusive).
     */
    public static function bandGrade(float $score): string
    {
        if ($score >= 0.9) {
            return 'A';
        }

        if ($score >= 0.8) {
            return 'B';
        }

        if ($score >= 0.7) {
            return 'C';
        }

        if ($score >= 0.6) {
            return 'D';
        }

        if ($score >= 0.5) {
            return 'E';
        }

        return 'F';
    }

    /**
     * Classify a page's weight against the budget classes.
     *
     * `next_class` is the class the page would enter by growing; `headroom_*` is
     * how much more fits before that happens. A `very_heavy` page is outside
     * every class, so it has neither.
     *
     * @param mixed $bytes    Total transferred bytes.
     * @param mixed $requests Total requests.
     *
     * @return array{
     *     class: string,
     *     bytes: int,
     *     requests: int,
     *     next_class: string|null,
     *     headroom_bytes: int,
     *     headroom_requests: int
     * }
     */
    public static function budgetClass(mixed $bytes, mixed $requests): array
    {
        $pageBytes    = is_numeric($bytes) ? max(0, (int) $bytes) : 0;
        $pageRequests = is_numeric($requests) ? max(0, (int) $requests) : 0;
        $classes      = array_keys(self::BUDGETS);

        foreach ($classes as $index => $class) {
            $limits = self::BUDGETS[ $class ];

            if ($pageBytes <= $limits['max_bytes'] && $pageRequests <= $limits['max_requests']) {
                return array(
                    'class'             => $class,
                    'bytes'             => $pageBytes,
                    'requests'          => $pageRequests,
                    // The ladder continues past the last bounded class.
                    'next_class'        => $classes[ $index + 1 ] ?? 'very_heavy',
                    'headroom_bytes'    => max(0, $limits['max_bytes'] - $pageBytes),
                    'headroom_requests' => max(0, $limits['max_requests'] - $pageRequests),
                );
            }
        }

        return array(
            'class'             => 'very_heavy',
            'bytes'             => $pageBytes,
            'requests'          => $pageRequests,
            'next_class'        => null,
            'headroom_bytes'    => 0,
            'headroom_requests' => 0,
        );
    }

    /**
     * The LCP phase breakdown, or an estimate marker when phases are absent.
     *
     * @param array<string, mixed> $lighthouse Lighthouse body.
     *
     * @return array<string, int|bool>
     */
    public static function lcpPhases(array $lighthouse): array
    {
        $audit = is_array($lighthouse['audits']['largest-contentful-paint'] ?? null)
            ? $lighthouse['audits']['largest-contentful-paint']
            : array();
        $item  = is_array($audit['details']['items'][0] ?? null) ? $audit['details']['items'][0] : array();
        $phase = is_array($item['phaseBreakdown'] ?? null) ? $item['phaseBreakdown'] : null;

        if (null !== $phase) {
            return array(
                'ttfb'         => self::int($phase['ttfb'] ?? 0),
                'load_delay'   => self::int($phase['loadDelay'] ?? 0),
                'load_time'    => self::int($phase['loadTime'] ?? 0),
                'render_delay' => self::int($phase['renderDelay'] ?? 0),
                'estimated'    => false,
            );
        }

        return array(
            'ttfb'         => 0,
            'load_delay'   => 0,
            'load_time'    => 0,
            'render_delay' => 0,
            'lcp'          => self::int($audit['numericValue'] ?? 0),
            'estimated'    => true,
        );
    }

    /**
     * The published impact band for a 0–1 score.
     */
    public static function impactBand(mixed $impact): string
    {
        if (! is_numeric($impact) || (float) $impact <= 0) {
            return 'None';
        }

        $value = (float) $impact;

        if ($value <= 0.12) {
            return 'Low';
        }

        if ($value <= 0.24) {
            return 'Med-Low';
        }

        if ($value <= 0.36) {
            return 'Med';
        }

        if ($value <= 0.48) {
            return 'Med-High';
        }

        return 'High';
    }

    /**
     * Audits ordered by impact, highest first; an absent impact sorts last.
     *
     * @param array<string, mixed> $audits Lighthouse `audits` block.
     *
     * @return list<array<string, mixed>>
     */
    public static function impactOrder(array $audits): array
    {
        $rows = array();

        foreach ($audits as $id => $audit) {
            if (! is_array($audit)) {
                continue;
            }

            $impact  = is_numeric($audit['_impactScore'] ?? null) ? $audit['_impactScore'] + 0 : null;
            $title   = is_scalar($audit['title'] ?? null) ? trim((string) $audit['title']) : '';
            $display = is_scalar($audit['displayValue'] ?? null) ? (string) $audit['displayValue'] : '';

            $rows[] = array(
                'id'              => (string) $id,
                'title'           => '' !== $title ? $title : (string) $id,
                'display_value'   => $display,
                'score'           => is_numeric($audit['score'] ?? null) ? $audit['score'] + 0 : null,
                'impact'          => $impact,
                'impact_band'     => self::impactBand($impact),
                'structure_score' => is_numeric($audit['_structureScore'] ?? null) ? $audit['_structureScore'] + 0 : null,
            );
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                $left  = null === $a['impact'] ? -1.0 : (float) $a['impact'];
                $right = null === $b['impact'] ? -1.0 : (float) $b['impact'];

                return $right <=> $left;
            }
        );

        return $rows;
    }

    /**
     * Before/after verdicts per metric.
     *
     * A metric missing on either side is omitted rather than reported as
     * unchanged, because "not measured" and "did not move" are different.
     *
     * @param array<string, mixed> $before Flat before attributes.
     * @param array<string, mixed> $after  Flat after attributes.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function reportDelta(array $before, array $after): array
    {
        $rules = array(
            'performance_score'        => array('kind' => 'score', 'threshold' => 5, 'direction' => 'down'),
            'structure_score'          => array('kind' => 'score', 'threshold' => 5, 'direction' => 'down'),
            'largest_contentful_paint' => array('kind' => 'percent', 'threshold' => 20, 'direction' => 'up'),
            'total_blocking_time'      => array('kind' => 'percent', 'threshold' => 20, 'direction' => 'up'),
            'cumulative_layout_shift'  => array('kind' => 'absolute', 'threshold' => 0.05, 'direction' => 'up'),
            'page_bytes'               => array('kind' => 'percent', 'threshold' => 10, 'direction' => 'up'),
            'page_requests'            => array('kind' => 'percent', 'threshold' => 15, 'direction' => 'up'),
            'time_to_first_byte'       => array('kind' => 'percent', 'threshold' => 20, 'direction' => 'up'),
        );

        $rows = array();

        foreach ($rules as $metric => $rule) {
            $from = $before[ $metric ] ?? null;
            $to   = $after[ $metric ] ?? null;

            if (! is_numeric($from) || ! is_numeric($to)) {
                continue;
            }

            $rows[ $metric ] = self::verdict($metric, (float) $from, (float) $to, $rule);
        }

        return $rows;
    }

    /**
     * Normalise a report datum (or a flat attribute map) to flat scalars.
     *
     * @param array<string, mixed> $report Report datum or attributes.
     *
     * @return array<string, mixed>
     */
    public static function flatReport(array $report): array
    {
        $attrs = is_array($report['attributes'] ?? null) ? $report['attributes'] : $report;
        $flat  = array();

        foreach ($attrs as $key => $value) {
            if (! is_array($value)) {
                $flat[ (string) $key ] = $value;
            }
        }

        if (isset($report['id']) && is_scalar($report['id'])) {
            $flat['id'] = $report['id'];
        }

        return $flat;
    }

    /**
     * One metric's verdict.
     *
     * @param array{kind: string, threshold: int|float, direction: string} $rule Delta rule.
     *
     * @return array<string, mixed>
     */
    private static function verdict(string $metric, float $before, float $after, array $rule): array
    {
        $delta = $after - $before;
        $pct   = 0.0 === $before ? 0.0 : ( $delta / $before ) * 100;
        $over  = 'absolute' === $rule['kind']
            ? abs($delta) >= $rule['threshold']
            : abs($pct) >= $rule['threshold'];

        if (! $over) {
            $status = 'steady';
        } elseif ('score' === $rule['kind']) {
            $status = $delta < 0 ? 'regressed' : 'improved';
        } else {
            // For a timing or a size, up is worse.
            $status = $delta > 0 ? 'regressed' : 'improved';
        }

        return array(
            'metric'         => $metric,
            'before'         => $before,
            'after'          => $after,
            'delta'          => $delta,
            'percent'        => $pct,
            'status'         => $status,
            'threshold'      => $rule['threshold'],
            'threshold_kind' => $rule['kind'],
        );
    }

    /**
     * An integer from a numeric value, or zero.
     */
    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
