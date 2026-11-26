<?php

/**
 * The deterministic GTmetrix assessment engine.
 *
 * Proposal 164 section 164.94. The boundary values are the WordPress smoke-run
 * oracles (`addons/pro/includes/tools/gtmetrix/tests/smoke-run.php`), so the
 * port is pinned to the same table rather than to a re-reading of it.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Tool\GTmetrix\GtmetrixScoreEngine;
use PHPUnit\Framework\TestCase;

final class GtmetrixScoreEngineTest extends TestCase
{
    public function test_the_cwv_thresholds_are_inclusive_at_the_good_boundary(): void
    {
        $this->assertSame('good', GtmetrixScoreEngine::assessMetric('lcp', 1800)['status']);
        $this->assertSame('good', GtmetrixScoreEngine::assessMetric('lcp', 2500)['status']);
        $this->assertSame('needs-improvement', GtmetrixScoreEngine::assessMetric('lcp', 2501)['status']);
        $this->assertSame('poor', GtmetrixScoreEngine::assessMetric('lcp', 4000)['status']);

        $this->assertSame('good', GtmetrixScoreEngine::assessMetric('cls', 0.10)['status']);
        $this->assertSame('needs-improvement', GtmetrixScoreEngine::assessMetric('cls', 0.11)['status']);
        $this->assertSame('poor', GtmetrixScoreEngine::assessMetric('cls', 0.25)['status']);

        $this->assertSame('good', GtmetrixScoreEngine::assessMetric('tbt', 200)['status']);
        $this->assertSame('poor', GtmetrixScoreEngine::assessMetric('tbt', 600)['status']);
    }

    public function test_an_unmeasured_value_is_unknown_rather_than_good(): void
    {
        foreach (array(null, '', -1, 'n/a') as $value) {
            $assessed = GtmetrixScoreEngine::assessMetric('fully_loaded', $value);

            $this->assertSame('unknown', $assessed['status'], (string) var_export($value, true));
            $this->assertSame(3000.0, $assessed['good']);
        }
    }

    public function test_the_overall_vitals_verdict(): void
    {
        $pass = GtmetrixScoreEngine::assessCwv(
            array(
                'largest_contentful_paint' => 1800,
                'total_blocking_time'      => 100,
                'cumulative_layout_shift'  => 0.05,
                'time_to_first_byte'       => 400,
            )
        );

        $this->assertSame('pass', $pass['overall']);
        $this->assertSame(4, count($pass['metrics']));
        $this->assertSame('Largest Contentful Paint', $pass['metrics']['lcp']['label']);

        $fail = GtmetrixScoreEngine::assessCwv(array('largest_contentful_paint' => 5000));

        $this->assertSame('fail', $fail['overall']);
        $this->assertSame('unknown', $fail['metrics']['ttfb']['status']);

        $unknown = GtmetrixScoreEngine::assessCwv(array());

        $this->assertSame('unknown', $unknown['overall']);
    }

    public function test_the_grade_band_floors(): void
    {
        $this->assertSame('A', GtmetrixScoreEngine::bandGrade(0.90));
        $this->assertSame('B', GtmetrixScoreEngine::bandGrade(0.80));
        $this->assertSame('C', GtmetrixScoreEngine::bandGrade(0.70));
        $this->assertSame('D', GtmetrixScoreEngine::bandGrade(0.60));
        $this->assertSame('E', GtmetrixScoreEngine::bandGrade(0.50));
        $this->assertSame('F', GtmetrixScoreEngine::bandGrade(0.49));
    }

    public function test_the_budget_classes_need_both_ceilings(): void
    {
        $this->assertSame('lean', GtmetrixScoreEngine::budgetClass(911360, 46)['class']);
        $this->assertSame('acceptable', GtmetrixScoreEngine::budgetClass(2516582, 89)['class']);
        $this->assertSame('heavy', GtmetrixScoreEngine::budgetClass(2621440, 91)['class']);
        $this->assertSame('very_heavy', GtmetrixScoreEngine::budgetClass(5347737, 150)['class']);
    }

    public function test_the_budget_reports_the_next_class_and_the_headroom(): void
    {
        $lean = GtmetrixScoreEngine::budgetClass(911360, 46);

        $this->assertSame('acceptable', $lean['next_class']);
        $this->assertSame(137216, $lean['headroom_bytes']);
        $this->assertSame(4, $lean['headroom_requests']);

        $heavy = GtmetrixScoreEngine::budgetClass(4000000, 120);

        $this->assertSame('heavy', $heavy['class']);
        $this->assertSame('very_heavy', $heavy['next_class']);
        $this->assertSame(1242880, $heavy['headroom_bytes']);

        $over = GtmetrixScoreEngine::budgetClass(5347737, 150);

        $this->assertNull($over['next_class']);
        $this->assertSame(0, $over['headroom_bytes']);
        $this->assertSame(0, $over['headroom_requests']);
    }

    public function test_the_lcp_phases_are_read_or_marked_estimated(): void
    {
        $phases = GtmetrixScoreEngine::lcpPhases(
            array(
                'audits' => array(
                    'largest-contentful-paint' => array(
                        'numericValue' => 1800,
                        'details'      => array(
                            'items' => array(
                                array(
                                    'phaseBreakdown' => array(
                                        'ttfb'        => 300,
                                        'loadDelay'   => 500,
                                        'loadTime'    => 700,
                                        'renderDelay' => 300,
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            )
        );

        $this->assertFalse($phases['estimated']);
        $this->assertSame(1800, $phases['ttfb'] + $phases['load_delay'] + $phases['load_time'] + $phases['render_delay']);

        $estimated = GtmetrixScoreEngine::lcpPhases(
            array('audits' => array('largest-contentful-paint' => array('numericValue' => 900)))
        );

        $this->assertTrue($estimated['estimated']);
        $this->assertSame(900, $estimated['lcp']);
    }

    public function test_the_impact_bands_and_the_order(): void
    {
        $this->assertSame('None', GtmetrixScoreEngine::impactBand(null));
        $this->assertSame('None', GtmetrixScoreEngine::impactBand(0));
        $this->assertSame('Low', GtmetrixScoreEngine::impactBand(0.12));
        $this->assertSame('Med-Low', GtmetrixScoreEngine::impactBand(0.13));
        $this->assertSame('Med-Low', GtmetrixScoreEngine::impactBand(0.24));
        $this->assertSame('Med', GtmetrixScoreEngine::impactBand(0.25));
        $this->assertSame('Med', GtmetrixScoreEngine::impactBand(0.36));
        $this->assertSame('Med-High', GtmetrixScoreEngine::impactBand(0.37));
        $this->assertSame('Med-High', GtmetrixScoreEngine::impactBand(0.48));
        $this->assertSame('High', GtmetrixScoreEngine::impactBand(0.49));
        $this->assertSame('High', GtmetrixScoreEngine::impactBand(1));

        $rows = GtmetrixScoreEngine::impactOrder(
            array(
                'a-diagnostic' => array('title' => 'Diagnostic'),
                'b-low'        => array('title' => 'Low', '_impactScore' => 0.1),
                'c-high'       => array('title' => 'High', '_impactScore' => 0.9, 'score' => 0),
                'not-an-audit' => 'ignore me',
            )
        );

        $this->assertSame(array('c-high', 'b-low', 'a-diagnostic'), array_column($rows, 'id'));
        $this->assertSame('High', $rows[0]['impact_band']);
        $this->assertNull($rows[2]['impact']);
    }

    public function test_the_delta_verdicts(): void
    {
        $deltas = GtmetrixScoreEngine::reportDelta(
            array(
                'performance_score'        => 94,
                'largest_contentful_paint' => 810,
                'cumulative_layout_shift'  => 0.05,
                'page_bytes'               => 850000,
            ),
            array(
                'performance_score'        => 80,
                'largest_contentful_paint' => 1200,
                'cumulative_layout_shift'  => 0.12,
                'page_bytes'               => 900000,
            )
        );

        $this->assertSame('regressed', $deltas['performance_score']['status']);
        $this->assertSame(-14.0, $deltas['performance_score']['delta']);
        $this->assertSame('regressed', $deltas['largest_contentful_paint']['status']);
        $this->assertSame('regressed', $deltas['cumulative_layout_shift']['status']);
        $this->assertSame('steady', $deltas['page_bytes']['status']);
    }

    public function test_a_small_move_is_steady_and_a_drop_in_a_timing_is_improved(): void
    {
        $steady = GtmetrixScoreEngine::reportDelta(
            array('performance_score' => 94, 'largest_contentful_paint' => 810),
            array('performance_score' => 92, 'largest_contentful_paint' => 900)
        );

        $this->assertSame('steady', $steady['performance_score']['status']);
        $this->assertSame('steady', $steady['largest_contentful_paint']['status']);

        $improved = GtmetrixScoreEngine::reportDelta(
            array('largest_contentful_paint' => 3000, 'time_to_first_byte' => 900),
            array('largest_contentful_paint' => 2000, 'time_to_first_byte' => 400)
        );

        $this->assertSame('improved', $improved['largest_contentful_paint']['status']);
        $this->assertSame('improved', $improved['time_to_first_byte']['status']);
    }

    public function test_a_metric_missing_on_either_side_is_omitted(): void
    {
        $deltas = GtmetrixScoreEngine::reportDelta(
            array('performance_score' => 90, 'cumulative_layout_shift' => 0.05),
            array('performance_score' => 95)
        );

        $this->assertArrayHasKey('performance_score', $deltas);
        $this->assertArrayNotHasKey('cumulative_layout_shift', $deltas);
    }

    public function test_a_report_is_flattened_without_arrays_and_keeps_the_id(): void
    {
        $flat = GtmetrixScoreEngine::flatReport(
            array(
                'type'       => 'report',
                'id'         => 'RGOOD',
                'attributes' => array(
                    'url'       => 'https://example.test/',
                    'page_tags' => array('t1'),
                    'created'   => 1700000000,
                ),
            )
        );

        $this->assertSame('RGOOD', $flat['id']);
        $this->assertSame('https://example.test/', $flat['url']);
        $this->assertArrayNotHasKey('page_tags', $flat);
    }
}
