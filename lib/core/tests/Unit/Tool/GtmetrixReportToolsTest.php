<?php

/**
 * The GTmetrix report readers: report, audits, resource summary and resources.
 *
 * Proposal 164 section 164.93. The cases that matter most are the two the
 * WordPress tools got wrong: a report's resource links live beside
 * `attributes`, not inside it, and the resource annotations are keyed by the
 * API's link names rather than its endpoint file names.
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
use Nvoos\Core\Tool\GTmetrix\GtmetrixGetLighthouseAuditsTool;
use Nvoos\Core\Tool\GTmetrix\GtmetrixGetReportTool;
use Nvoos\Core\Tool\GTmetrix\GtmetrixGetResourceSummaryTool;
use Nvoos\Core\Tool\GTmetrix\GtmetrixListReportResourcesTool;
use PHPUnit\Framework\TestCase;

final class GtmetrixReportToolsTest extends TestCase
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

    // ─── get_report ──────────────────────────────────────────────────────

    public function test_a_report_is_flattened_into_scores_metrics_and_page(): void
    {
        $result = ( new GtmetrixGetReportTool($this->errors, $this->client()) )->execute(
            array('report_id' => 'RGOOD'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('RGOOD', $result['data']['report_id']);
        $this->assertSame('https://example.test/', $result['data']['url']);
        $this->assertSame('A', $result['data']['grade']);
        $this->assertSame(96, $result['data']['scores']['gtmetrix']);
        $this->assertSame(94, $result['data']['scores']['performance']);
        $this->assertSame(99, $result['data']['scores']['structure']);
        $this->assertNull($result['data']['scores']['pagespeed']);
        $this->assertSame(120, $result['data']['metrics']['ttfb']);
        $this->assertSame(810, $result['data']['metrics']['lcp']);
        $this->assertSame(0.05, $result['data']['metrics']['cls']);
        $this->assertSame(2100, $result['data']['metrics']['fully_loaded']);
        $this->assertSame(850000, $result['data']['page']['bytes']);
        $this->assertSame(42, $result['data']['page']['requests']);
        $this->assertSame(1700000000, $result['data']['created']);
    }

    public function test_the_resource_names_come_from_the_links_beside_the_attributes(): void
    {
        $result = ( new GtmetrixGetReportTool($this->errors, $this->client()) )->execute(
            array('report_id' => 'RGOOD'),
            self::CONTEXT
        );

        $this->assertSame(
            array(
                'har',
                'lighthouse',
                'screenshot',
                'resource_summary',
                'optimized_images',
                'report_pdf',
                'report_pdf_full',
                'report_url',
                'analysis_options',
                'something_new',
            ),
            $result['data']['resources']
        );
    }

    public function test_a_report_response_without_a_datum_is_an_invalid_response(): void
    {
        $client            = $this->client();
        $client->responses = array('reports/RGOOD' => array('data' => null));

        $result = ( new GtmetrixGetReportTool($this->errors, $client) )->execute(
            array('report_id' => 'RGOOD'),
            self::CONTEXT
        );

        $this->assertSame('gtmetrix_invalid_response', $result['error']['code']);
    }

    public function test_an_invalid_report_id_is_refused_without_calling(): void
    {
        $client = $this->client();

        foreach (array('', '../etc/passwd', 'RGOOD;rm') as $id) {
            $result = ( new GtmetrixGetReportTool($this->errors, $client) )->execute(
                array('report_id' => $id),
                self::CONTEXT
            );

            $this->assertSame('validation_failed', $result['error']['code'], $id);
        }

        $this->assertSame(array(), $client->calls);
    }

    public function test_a_missing_report_is_classified(): void
    {
        $client                          = $this->client();
        $client->failOn['reports/RNONE'] = new GtmetrixApiException(
            'GTmetrix report not found.',
            'gtmetrix_not_found',
            404,
            'E40401'
        );

        $result = ( new GtmetrixGetReportTool($this->errors, $client) )->execute(
            array('report_id' => 'RNONE'),
            self::CONTEXT
        );

        $this->assertSame('gtmetrix_not_found', $result['error']['code']);
        $this->assertSame(404, $result['error']['data']['status']);
    }

    // ─── get_lighthouse_audits ───────────────────────────────────────────

    public function test_audits_are_ordered_by_impact_and_banded(): void
    {
        $result = ( new GtmetrixGetLighthouseAuditsTool($this->errors, $this->client()) )
            ->execute(array('report_id' => 'RGOOD'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(5, $result['data']['total_audits']);
        $this->assertSame(5, $result['data']['count']);
        $this->assertSame(
            array('render-blocking-resources', 'largest-contentful-paint', 'unused-css', 'uses-rel-preload', 'diagnostic'),
            array_column($result['data']['audits'], 'id')
        );
        $this->assertSame(
            array('High', 'High', 'Med', 'Low', 'None'),
            array_column($result['data']['audits'], 'impact_band')
        );
        $this->assertNull($result['data']['audits'][4]['impact']);
        $this->assertSame(0.6, $result['data']['audits'][0]['impact']);
        $this->assertSame('Potential savings of 600 ms', $result['data']['audits'][0]['display_value']);
    }

    public function test_the_min_impact_filter_keeps_the_bands_at_or_above_it(): void
    {
        $result = ( new GtmetrixGetLighthouseAuditsTool($this->errors, $this->client()) )
            ->execute(array('report_id' => 'RGOOD', 'min_impact' => 'Med'), self::CONTEXT);

        $this->assertSame(3, $result['data']['count']);
        $this->assertSame(5, $result['data']['total_audits']);
        $this->assertSame(
            array('High', 'High', 'Med'),
            array_column($result['data']['audits'], 'impact_band')
        );
    }

    public function test_the_limit_bounds_the_list(): void
    {
        $result = ( new GtmetrixGetLighthouseAuditsTool($this->errors, $this->client()) )
            ->execute(array('report_id' => 'RGOOD', 'limit' => 2), self::CONTEXT);

        $this->assertSame(2, $result['data']['count']);
        $this->assertSame(5, $result['data']['total_audits']);
    }

    public function test_an_unknown_impact_band_is_refused(): void
    {
        $client = $this->client();
        $result = ( new GtmetrixGetLighthouseAuditsTool($this->errors, $client) )->execute(
            array('report_id' => 'RGOOD', 'min_impact' => 'urgent'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('min_impact', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_none_is_not_a_filter_value(): void
    {
        $result = ( new GtmetrixGetLighthouseAuditsTool($this->errors, $this->client()) )
            ->execute(array('report_id' => 'RGOOD', 'min_impact' => 'None'), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_the_lcp_phase_breakdown_is_read(): void
    {
        $result = ( new GtmetrixGetLighthouseAuditsTool($this->errors, $this->client()) )
            ->execute(array('report_id' => 'RGOOD'), self::CONTEXT);

        $this->assertSame(
            array(
                'ttfb'         => 120,
                'load_delay'   => 200,
                'load_time'    => 400,
                'render_delay' => 90,
                'estimated'    => false,
            ),
            $result['data']['lcp_phases']
        );
    }

    public function test_a_lighthouse_body_without_phase_data_is_marked_estimated(): void
    {
        $client            = $this->client();
        $client->responses = array(
            'reports/RGOOD' => $this->reportRow(),
            'reports/RGOOD/resources/lighthouse.json' => array(
                'document' => array(
                    'audits' => array(
                        'largest-contentful-paint' => array('title' => 'LCP', 'numericValue' => 900),
                        'total-blocking-time'      => array('title' => 'TBT', '_impactScore' => 0.2),
                    ),
                ),
            ),
        );

        $result = ( new GtmetrixGetLighthouseAuditsTool($this->errors, $client) )
            ->execute(array('report_id' => 'RGOOD'), self::CONTEXT);

        $this->assertTrue($result['data']['lcp_phases']['estimated']);
        $this->assertSame(900, $result['data']['lcp_phases']['lcp']);
        $this->assertSame(0, $result['data']['lcp_phases']['ttfb']);
    }

    public function test_a_wrapped_resource_body_is_accepted(): void
    {
        $client            = $this->client();
        $client->responses = array(
            'reports/RGOOD' => $this->reportRow(),
            'reports/RGOOD/resources/lighthouse.json' => array(
                'document' => array(
                    'data' => array(
                        'type'       => 'lighthouse',
                        'attributes' => array(
                            'audits' => array(
                                'unused-css' => array('title' => 'Reduce unused CSS', '_impactScore' => 0.3),
                            ),
                        ),
                    ),
                ),
            ),
        );

        $result = ( new GtmetrixGetLighthouseAuditsTool($this->errors, $client) )
            ->execute(array('report_id' => 'RGOOD'), self::CONTEXT);

        $this->assertSame(1, $result['data']['count']);
        $this->assertSame('unused-css', $result['data']['audits'][0]['id']);
        $this->assertSame('Med', $result['data']['audits'][0]['impact_band']);
    }

    // ─── get_resource_summary ────────────────────────────────────────────

    public function test_categories_are_ordered_by_transferred_size(): void
    {
        $result = ( new GtmetrixGetResourceSummaryTool($this->errors, $this->client()) )
            ->execute(array('report_id' => 'RGOOD'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(
            array('font', 'script', 'document', 'other', 'media'),
            array_column($result['data']['categories'], 'type')
        );
        $this->assertSame(379252, $result['data']['categories'][0]['size_transferred']);
        $this->assertSame(0, $result['data']['categories'][4]['count']);
        $this->assertSame(11, $result['data']['total_requests']);
    }

    public function test_a_non_scalar_label_falls_back_to_the_category_name(): void
    {
        $result = ( new GtmetrixGetResourceSummaryTool($this->errors, $this->client()) )
            ->execute(array('report_id' => 'RGOOD'), self::CONTEXT);

        $labels = array_column($result['data']['categories'], 'label', 'type');

        $this->assertSame('HTML', $labels['document']);
        $this->assertSame('other', $labels['other']);
    }

    // ─── list_report_resources ───────────────────────────────────────────

    public function test_the_annotations_are_keyed_by_link_name(): void
    {
        $rows = $this->resources();

        $this->assertSame('har', $rows['har']['name']);
        $this->assertSame('net.har', $rows['har']['resource']);
        $this->assertTrue($rows['har']['sensitive']);
        $this->assertFalse($rows['har']['downloadable']);

        $this->assertTrue($rows['analysis_options']['sensitive']);
        $this->assertSame('analysis-options', $rows['analysis_options']['resource']);

        $this->assertFalse($rows['screenshot']['sensitive']);
        $this->assertTrue($rows['screenshot']['downloadable']);
        $this->assertSame('screenshot.jpg', $rows['screenshot']['resource']);
    }

    public function test_the_24_hour_resources_are_marked_and_the_pdfs_mention_credits(): void
    {
        $rows = $this->resources();

        $this->assertTrue($rows['optimized_images']['expires_24h']);
        $this->assertSame('optimized-images.tar', $rows['optimized_images']['resource']);
        $this->assertFalse($rows['screenshot']['expires_24h']);

        $this->assertStringContainsString('credits', $rows['report_pdf']['notes']);
        $this->assertStringContainsString('credits', $rows['report_pdf_full']['notes']);
        $this->assertSame('', $rows['report_url']['resource']);
    }

    public function test_an_unknown_link_gets_the_neutral_annotation(): void
    {
        $rows = $this->resources();

        $this->assertTrue($rows['something_new']['downloadable']);
        $this->assertFalse($rows['something_new']['sensitive']);
        $this->assertFalse($rows['something_new']['expires_24h']);
        $this->assertSame('', $rows['something_new']['notes']);
    }

    public function test_a_report_without_links_lists_nothing(): void
    {
        $client            = $this->client();
        $client->responses = array(
            'reports/RLINKS' => array(
                'data' => array('type' => 'report', 'id' => 'RLINKS', 'attributes' => array('url' => 'https://example.test/')),
            ),
        );

        $result = ( new GtmetrixListReportResourcesTool($this->errors, $client) )
            ->execute(array('report_id' => 'RLINKS'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['resources']);
        $this->assertSame(0, $result['data']['count']);
    }

    // ─── shared behaviour ────────────────────────────────────────────────

    public function test_every_reader_refuses_without_a_key(): void
    {
        $client             = $this->client();
        $client->configured = false;

        $readers = array(
            'gtmetrix_get_report'            => new GtmetrixGetReportTool($this->errors, $client),
            'gtmetrix_get_lighthouse_audits' => new GtmetrixGetLighthouseAuditsTool($this->errors, $client),
            'gtmetrix_get_resource_summary'  => new GtmetrixGetResourceSummaryTool($this->errors, $client),
            'gtmetrix_list_report_resources' => new GtmetrixListReportResourcesTool($this->errors, $client),
        );

        foreach ($readers as $slug => $reader) {
            $result = $reader->execute(array('report_id' => 'RGOOD'), self::CONTEXT);

            $this->assertSame('gtmetrix_not_configured', $result['error']['code'], $slug);
        }

        $this->assertSame(array(), $client->calls);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * The resource rows of the fixture report, keyed by link name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function resources(): array
    {
        $result = ( new GtmetrixListReportResourcesTool($this->errors, $this->client()) )
            ->execute(array('report_id' => 'RGOOD'), self::CONTEXT);

        return array_column($result['data']['resources'], null, 'name');
    }

    private function client(): FakeGtmetrixClient
    {
        $client            = new FakeGtmetrixClient();
        $client->responses = array(
            'reports/RGOOD' => $this->reportRow(),
            'reports/RGOOD/resources/lighthouse.json' => array('document' => $this->lighthouseBody()),
            'reports/RGOOD/resources/resource-summary' => array('document' => $this->summaryBody()),
        );

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private function reportRow(): array
    {
        return array(
            'data' => array(
                'type'       => 'report',
                'id'         => 'RGOOD',
                'attributes' => array(
                    'url'                      => 'https://example.test/',
                    'gtmetrix_grade'           => 'A',
                    'gtmetrix_score'           => 96,
                    'performance_score'        => 94,
                    'structure_score'          => 99,
                    'time_to_first_byte'       => 120,
                    'first_contentful_paint'   => 420,
                    'largest_contentful_paint' => 810,
                    'time_to_interactive'      => 1200,
                    'total_blocking_time'      => 40,
                    'cumulative_layout_shift'  => 0.05,
                    'speed_index'              => 700,
                    'onload_time'              => 1500,
                    'fully_loaded_time'        => 2100,
                    'page_bytes'               => 850000,
                    'page_requests'            => 42,
                    'html_bytes'               => 24000,
                    'created'                  => 1700000000,
                    'expires'                  => 1702592000,
                ),
                'links'      => array(
                    'har'              => '/api/2.0/reports/RGOOD/resources/net.har',
                    'lighthouse'       => '/api/2.0/reports/RGOOD/resources/lighthouse.json',
                    'screenshot'       => '/api/2.0/reports/RGOOD/resources/screenshot.jpg',
                    'resource_summary' => '/api/2.0/reports/RGOOD/resources/resource-summary',
                    'optimized_images' => '/api/2.0/reports/RGOOD/resources/optimized-images.tar',
                    'report_pdf'       => '/api/2.0/reports/RGOOD/resources/report.pdf',
                    'report_pdf_full'  => '/api/2.0/reports/RGOOD/resources/report.pdf?full=1',
                    'report_url'       => 'https://gtmetrix.com/reports/example.test/RGOOD/',
                    'analysis_options' => '/api/2.0/reports/RGOOD/resources/analysis-options',
                    'something_new'    => '/api/2.0/reports/RGOOD/resources/something-new',
                ),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function lighthouseBody(): array
    {
        return array(
            'lighthouseVersion' => '11.0.0',
            'audits'            => array(
                'render-blocking-resources' => array(
                    'title'           => 'Eliminate render-blocking resources',
                    'displayValue'    => 'Potential savings of 600 ms',
                    'score'           => 0.4,
                    '_impactScore'    => 0.6,
                    '_structureScore' => 0.3,
                ),
                'unused-css'                => array(
                    'title'        => 'Reduce unused CSS',
                    'score'        => 0.7,
                    '_impactScore' => 0.3,
                ),
                'uses-rel-preload'          => array(
                    'title'        => 'Preload key requests',
                    'score'        => 1,
                    '_impactScore' => 0.12,
                ),
                'diagnostic'                => array(
                    'title' => 'Diagnostic',
                    'score' => null,
                ),
                'largest-contentful-paint'  => array(
                    'title'        => 'Largest Contentful Paint',
                    'score'        => 0.8,
                    'numericValue' => 810,
                    '_impactScore' => 0.5,
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
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryBody(): array
    {
        return array(
            'document' => array('label' => 'HTML', 'count' => 2, 'size_transferred' => 139443, 'size_uncompressed' => 896765),
            'font'     => array('label' => 'Fonts', 'count' => 2, 'size_transferred' => 379252, 'size_uncompressed' => 379252),
            'media'    => array('label' => 'Videos', 'count' => 0, 'size_transferred' => 0, 'size_uncompressed' => 0),
            'script'   => array('label' => 'JavaScript', 'count' => 6, 'size_transferred' => 161484, 'size_uncompressed' => 441990),
            'other'    => array('label' => array('malformed'), 'count' => 1, 'size_transferred' => 7621, 'size_uncompressed' => 16127),
            'junk'     => 'not a category',
        );
    }
}
