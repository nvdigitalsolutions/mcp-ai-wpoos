<?php

/**
 * The GTmetrix analysis tools: analyze a report and compare two reports.
 *
 * Proposal 164 section 164.94. The two cases that matter most are the two the
 * WordPress tools got wrong: a report without a score was graded F, and a
 * failed Lighthouse fetch looked like "nothing to fix".
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\GtmetrixApiException;
use Nvoos\Core\Tests\Support\FakeGtmetrixClient;
use Nvoos\Core\Tool\GTmetrix\GtmetrixAnalyzeReportTool;
use Nvoos\Core\Tool\GTmetrix\GtmetrixCompareReportsTool;
use PHPUnit\Framework\TestCase;

final class GtmetrixAnalysisToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message = 'Access denied.', array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message, 'data' => $data),
            )
        );
    }

    // ─── analyze_report ──────────────────────────────────────────────────

    public function test_a_report_is_assessed_into_vitals_budget_and_fixes(): void
    {
        $result = ( new GtmetrixAnalyzeReportTool($this->errors, $this->analyzeClient()) )
            ->execute(array('report_id' => 'RGOOD'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('RGOOD', $result['data']['report_id']);
        $this->assertSame('A', $result['data']['grade']);
        $this->assertSame(96, $result['data']['scores']['gtmetrix']);
        $this->assertSame('pass', $result['data']['core_web_vitals']['overall']);
        $this->assertSame('lean', $result['data']['budget']['class']);
        $this->assertSame('acceptable', $result['data']['budget']['next_class']);
        $this->assertTrue($result['data']['lighthouse']['available']);
        $this->assertFalse($result['data']['lcp_phases']['estimated']);
        $this->assertSame(7, count($result['data']['top_audits']));
    }

    public function test_the_fix_list_maps_every_fix_that_names_an_audit(): void
    {
        $result = ( new GtmetrixAnalyzeReportTool($this->errors, $this->analyzeClient()) )
            ->execute(array('report_id' => 'RGOOD'), self::CONTEXT);

        $this->assertSame(4, $result['data']['fixes']['count']);
        $this->assertSame(
            array('render-blocking-resources', 'uses-optimized-images', 'third-party-summary', 'third-party-summary'),
            array_column($result['data']['fixes']['matched'], 'matched_audit')
        );
        $this->assertStringContainsString('reCAPTCHA', $result['data']['fixes']['matched'][2]['symptom']);
        $this->assertStringContainsString('analytics tag', $result['data']['fixes']['matched'][3]['symptom']);
        $this->assertSame(1, count($result['data']['fixes']['unfixable']));
        $this->assertSame('uses-long-cache-ttl', $result['data']['fixes']['unfixable'][0]['id']);
        $this->assertSame(
            array('largest-contentful-paint', 'mystery-audit'),
            array_column($result['data']['fixes']['unknown'], 'id')
        );
    }

    public function test_a_diagnostic_audit_never_becomes_a_fix_or_a_gap(): void
    {
        $result = ( new GtmetrixAnalyzeReportTool($this->errors, $this->analyzeClient()) )
            ->execute(array('report_id' => 'RGOOD'), self::CONTEXT);

        $ids = array_merge(
            array_column($result['data']['fixes']['matched'], 'matched_audit'),
            array_column($result['data']['fixes']['unfixable'], 'id'),
            array_column($result['data']['fixes']['unknown'], 'id')
        );

        $this->assertNotContains('some-diagnostic', $ids);
    }

    public function test_a_score_without_a_grade_is_banded(): void
    {
        $client            = $this->client();
        $client->responses = array(
            'reports/RBAND' => $this->reportRow('RBAND', array('gtmetrix_score' => 87)),
            'reports/RBAND/resources/lighthouse.json' => array('document' => array('audits' => array())),
        );

        $result = ( new GtmetrixAnalyzeReportTool($this->errors, $client) )
            ->execute(array('report_id' => 'RBAND'), self::CONTEXT);

        $this->assertSame('B', $result['data']['grade']);
    }

    public function test_a_report_without_a_score_is_ungraded_rather_than_f(): void
    {
        $client            = $this->client();
        $client->responses = array(
            'reports/RMETRICS' => $this->reportRow('RMETRICS', array('url' => 'https://example.test/')),
            'reports/RMETRICS/resources/lighthouse.json' => array('document' => array('audits' => array())),
        );

        $result = ( new GtmetrixAnalyzeReportTool($this->errors, $client) )
            ->execute(array('report_id' => 'RMETRICS'), self::CONTEXT);

        $this->assertSame('', $result['data']['grade']);
        $this->assertNull($result['data']['scores']['gtmetrix']);
        $this->assertStringContainsString('ungraded', $result['message']);
    }

    public function test_an_unavailable_lighthouse_resource_is_reported_not_silenced(): void
    {
        $client                                        = $this->client();
        $client->responses                             = array('reports/RMETRICS' => $this->reportRow('RMETRICS'));
        $client->failOn['reports/RMETRICS/resources/lighthouse.json'] = new GtmetrixApiException(
            'The Lighthouse JSON is unavailable for this report.',
            'gtmetrix_not_found',
            404,
            'E40410'
        );

        $result = ( new GtmetrixAnalyzeReportTool($this->errors, $client) )
            ->execute(array('report_id' => 'RMETRICS'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['lighthouse']['available']);
        $this->assertSame('gtmetrix_not_found', $result['data']['lighthouse']['error_code']);
        $this->assertSame(array(), $result['data']['top_audits']);
        $this->assertSame(0, $result['data']['fixes']['count']);
        $this->assertTrue($result['data']['lcp_phases']['estimated']);
    }

    public function test_analyze_refuses_a_url_because_there_are_no_snapshots(): void
    {
        $client = $this->client();
        $result = ( new GtmetrixAnalyzeReportTool($this->errors, $client) )
            ->execute(array('url' => 'https://example.test/'), self::CONTEXT);

        $this->assertSame('gtmetrix_no_snapshot_store', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_analyze_requires_a_report_id(): void
    {
        $client = $this->client();
        $result = ( new GtmetrixAnalyzeReportTool($this->errors, $client) )
            ->execute(array(), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('report_id', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_analyze_passes_a_classified_api_failure_through(): void
    {
        $client                          = $this->client();
        $client->failOn['reports/RNONE'] = new GtmetrixApiException(
            'GTmetrix report not found.',
            'gtmetrix_not_found',
            404,
            'E40401'
        );

        $result = ( new GtmetrixAnalyzeReportTool($this->errors, $client) )
            ->execute(array('report_id' => 'RNONE'), self::CONTEXT);

        $this->assertSame('gtmetrix_not_found', $result['error']['code']);
        $this->assertSame(404, $result['error']['data']['status']);
    }

    // ─── compare_reports ─────────────────────────────────────────────────

    public function test_every_metric_gets_a_verdict_and_the_regressions_are_counted(): void
    {
        $result = ( new GtmetrixCompareReportsTool($this->errors, $this->compareClient()) )
            ->execute(array('report_id_a' => 'RA', 'report_id_b' => 'RB'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(8, count($result['data']['deltas']));
        $this->assertSame(8, $result['data']['regressed_count']);
        $this->assertStringContainsString('8 metric(s) regressed', $result['data']['verdict']);
        $this->assertSame('RA', $result['data']['before']['report_id']);
        $this->assertSame('RB', $result['data']['after']['report_id']);
        $this->assertSame(90, $result['data']['before']['score']);
        $this->assertSame(60, $result['data']['after']['score']);

        $statuses = array_column($result['data']['deltas'], 'status', 'metric');

        $this->assertSame('regressed', $statuses['performance_score']);
        $this->assertSame('regressed', $statuses['cumulative_layout_shift']);
        $this->assertSame('regressed', $statuses['time_to_first_byte']);
    }

    public function test_identical_reports_are_steady(): void
    {
        $client            = $this->client();
        $client->responses = array(
            'reports/RA' => $this->reportRow('RA', $this->goodAttributes()),
            'reports/RB' => $this->reportRow('RB', $this->goodAttributes()),
        );

        $result = ( new GtmetrixCompareReportsTool($this->errors, $client) )
            ->execute(array('report_id_a' => 'RA', 'report_id_b' => 'RB'), self::CONTEXT);

        $this->assertSame(0, $result['data']['regressed_count']);
        $this->assertSame('No regressions detected.', $result['data']['verdict']);
        $this->assertSame(array('steady'), array_values(array_unique(array_column($result['data']['deltas'], 'status'))));
    }

    public function test_a_metric_missing_on_one_side_is_omitted(): void
    {
        $client            = $this->client();
        $client->responses = array(
            'reports/RA' => $this->reportRow('RA', $this->goodAttributes()),
            'reports/RB' => $this->reportRow(
                'RB',
                array_diff_key($this->goodAttributes(), array('cumulative_layout_shift' => null))
            ),
        );

        $result = ( new GtmetrixCompareReportsTool($this->errors, $client) )
            ->execute(array('report_id_a' => 'RA', 'report_id_b' => 'RB'), self::CONTEXT);

        $this->assertNotContains('cumulative_layout_shift', array_column($result['data']['deltas'], 'metric'));
        $this->assertContains('performance_score', array_column($result['data']['deltas'], 'metric'));
    }

    public function test_the_report_ids_fall_back_to_the_requested_ones(): void
    {
        $client            = $this->client();
        $client->responses = array(
            'reports/RA' => array('data' => array('type' => 'report', 'attributes' => $this->goodAttributes())),
            'reports/RB' => array('data' => array('type' => 'report', 'attributes' => $this->goodAttributes())),
        );

        $result = ( new GtmetrixCompareReportsTool($this->errors, $client) )
            ->execute(array('report_id_a' => 'RA', 'report_id_b' => 'RB'), self::CONTEXT);

        $this->assertSame('RA', $result['data']['before']['report_id']);
        $this->assertSame('RB', $result['data']['after']['report_id']);
    }

    public function test_compare_refuses_a_url_because_there_are_no_snapshots(): void
    {
        $client = $this->client();
        $result = ( new GtmetrixCompareReportsTool($this->errors, $client) )
            ->execute(array('url' => 'https://example.test/'), self::CONTEXT);

        $this->assertSame('gtmetrix_no_snapshot_store', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_both_report_ids_are_required(): void
    {
        $client = $this->client();
        $result = ( new GtmetrixCompareReportsTool($this->errors, $client) )
            ->execute(array('report_id_a' => 'RA'), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('report_id_b', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function client(): FakeGtmetrixClient
    {
        return new FakeGtmetrixClient();
    }

    private function analyzeClient(): FakeGtmetrixClient
    {
        $client            = new FakeGtmetrixClient();
        $client->responses = array(
            'reports/RGOOD' => $this->reportRow('RGOOD', $this->goodAttributes()),
            'reports/RGOOD/resources/lighthouse.json' => array('document' => $this->lighthouseBody()),
        );

        return $client;
    }

    private function compareClient(): FakeGtmetrixClient
    {
        $client            = new FakeGtmetrixClient();
        $client->responses = array(
            'reports/RA' => $this->reportRow('RA', $this->goodAttributes()),
            'reports/RB' => $this->reportRow(
                'RB',
                array(
                    'gtmetrix_grade'           => 'E',
                    'performance_score'        => 60,
                    'structure_score'          => 70,
                    'largest_contentful_paint' => 3000,
                    'total_blocking_time'      => 700,
                    'cumulative_layout_shift'  => 0.2,
                    'page_bytes'               => 2000000,
                    'page_requests'            => 100,
                    'time_to_first_byte'       => 900,
                )
            ),
        );

        return $client;
    }

    /**
     * @param array<string, mixed> $overrides Attributes to replace.
     *
     * @return array<string, mixed>
     */
    private function reportRow(string $id, array $overrides = array()): array
    {
        return array(
            'data' => array(
                'type'       => 'report',
                'id'         => $id,
                'attributes' => $overrides,
                'links'      => array(),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function goodAttributes(): array
    {
        return array(
            'url'                      => 'https://example.test/',
            'gtmetrix_grade'           => 'A',
            'gtmetrix_score'           => 96,
            'performance_score'        => 90,
            'structure_score'          => 95,
            'largest_contentful_paint' => 1500,
            'total_blocking_time'      => 100,
            'cumulative_layout_shift'  => 0.05,
            'time_to_first_byte'       => 300,
            'page_bytes'               => 800000,
            'page_requests'            => 40,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function lighthouseBody(): array
    {
        return array(
            'audits' => array(
                'largest-contentful-paint'  => array(
                    'title'        => 'Largest Contentful Paint',
                    'numericValue' => 810,
                    '_impactScore' => 0.7,
                    'details'      => array(
                        'items' => array(
                            array(
                                'phaseBreakdown' => array(
                                    'ttfb'        => 120,
                                    'loadDelay'   => 200,
                                    'loadTime'    => 400,
                                    'renderDelay' => 90,
                                ),
                            ),
                        ),
                    ),
                ),
                'render-blocking-resources' => array('title' => 'Eliminate render-blocking resources', '_impactScore' => 0.6),
                'uses-optimized-images'     => array('title' => 'Serve images in modern formats', '_impactScore' => 0.5),
                'third-party-summary'       => array('title' => 'Reduce the impact of third-party code', '_impactScore' => 0.3),
                'uses-long-cache-ttl'       => array('title' => 'Serve static assets with a cache policy', '_impactScore' => 0.2),
                'mystery-audit'             => array('title' => 'Something new', '_impactScore' => 0.1),
                'some-diagnostic'           => array('title' => 'A diagnostic', 'score' => null),
            ),
        );
    }
}
