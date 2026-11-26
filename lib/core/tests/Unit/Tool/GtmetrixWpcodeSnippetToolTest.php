<?php

/**
 * The WPCode snippet generator.
 *
 * Proposal 164 section 164.95. The cases that matter most are the two the
 * WordPress tool got wrong: every fetch failure was reported as "no Lighthouse
 * JSON", and a configuration-only fix was counted as a code block.
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
use Nvoos\Core\Tool\GTmetrix\GtmetrixGenerateWpcodeSnippetTool;
use PHPUnit\Framework\TestCase;

final class GtmetrixWpcodeSnippetToolTest extends TestCase
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

    public function test_the_snippet_carries_only_the_matching_blocks(): void
    {
        $result = ( new GtmetrixGenerateWpcodeSnippetTool($this->errors, $this->client()) )
            ->execute(array('report_id' => 'RSNIP'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $code = $result['data']['code'];

        $this->assertStringStartsWith('<?php', $code);
        $this->assertStringContainsString('Generated from GTmetrix report: RSNIP', $code);
        $this->assertStringContainsString(' * Page: https://example.test/', $code);
        $this->assertStringContainsString("if ( ! defined( 'ABSPATH' ) ) {", $code);
        $this->assertStringContainsString("add_filter( 'elementor_pro_custom_fonts_font_display'", $code);
        $this->assertStringContainsString('.site-header svg', $code);
        $this->assertStringNotContainsString('wp_omit_loading_attr_threshold', $code);
    }

    public function test_a_high_risk_block_is_commented_out(): void
    {
        $result = ( new GtmetrixGenerateWpcodeSnippetTool($this->errors, $this->client()) )
            ->execute(array('report_id' => 'RSNIP'), self::CONTEXT);

        $code = $result['data']['code'];

        $this->assertStringContainsString('TEST ON STAGING BEFORE ENABLING - RISK: HIGH.', $code);
        $this->assertStringContainsString("wp_dequeue_style( 'font-awesome-4-shim-css' );", $code);

        $marker = strpos($code, 'TEST ON STAGING BEFORE ENABLING');
        $opener = strpos($code, '/*', (int) $marker);
        $closer = strpos($code, '*/', (int) $opener);
        $dequeue = strpos($code, 'wp_dequeue_style');

        $this->assertNotFalse($opener);
        $this->assertNotFalse($closer);
        $this->assertGreaterThan((int) $opener, (int) $dequeue);
        $this->assertLessThan((int) $closer, (int) $dequeue);
    }

    public function test_a_config_only_fix_is_counted_apart_from_the_emitted_blocks(): void
    {
        $result = ( new GtmetrixGenerateWpcodeSnippetTool($this->errors, $this->client()) )
            ->execute(array('report_id' => 'RSNIP'), self::CONTEXT);

        $this->assertSame(5, $result['data']['count']);
        $this->assertSame(4, $result['data']['emitted']);

        $blocks = array_column($result['data']['blocks'], 'block');

        $this->assertContains('', $blocks);
        $this->assertSame(
            array('dequeue-fa4-shims', 'font-display-swap', 'cls-stabilize', 'lazy-recaptcha', ''),
            $blocks
        );
    }

    public function test_the_risky_blocks_can_be_excluded(): void
    {
        $result = ( new GtmetrixGenerateWpcodeSnippetTool($this->errors, $this->client()) )
            ->execute(array('report_id' => 'RSNIP', 'include_risky' => false), self::CONTEXT);

        $this->assertSame(3, $result['data']['count']);
        $this->assertSame(2, $result['data']['emitted']);
        $this->assertStringNotContainsString('TEST ON STAGING', $result['data']['code']);
        $this->assertStringNotContainsString('wp_dequeue_style', $result['data']['code']);
    }

    public function test_a_string_false_also_excludes_the_risky_blocks(): void
    {
        $result = ( new GtmetrixGenerateWpcodeSnippetTool($this->errors, $this->client()) )
            ->execute(array('report_id' => 'RSNIP', 'include_risky' => 'false'), self::CONTEXT);

        $this->assertSame(2, $result['data']['emitted']);
        $this->assertStringNotContainsString('TEST ON STAGING', $result['data']['code']);
    }

    public function test_a_report_with_no_matching_fixes_returns_no_code(): void
    {
        $client            = $this->client();
        $client->responses = array(
            'reports/RSNIP' => $this->reportRow(),
            'reports/RSNIP/resources/lighthouse.json' => array(
                'document' => array(
                    'audits' => array(
                        'uses-long-cache-ttl' => array('title' => 'Cache policy', '_impactScore' => 0.4),
                        'mystery-audit'       => array('title' => 'Something new', '_impactScore' => 0.3),
                        'a-diagnostic'        => array('title' => 'A diagnostic'),
                    ),
                ),
            ),
        );

        $result = ( new GtmetrixGenerateWpcodeSnippetTool($this->errors, $client) )
            ->execute(array('report_id' => 'RSNIP'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('', $result['data']['code']);
        $this->assertSame(array(), $result['data']['blocks']);
        $this->assertSame(0, $result['data']['count']);
        $this->assertSame(0, $result['data']['emitted']);
        $this->assertStringContainsString('No known fixes', $result['message']);
        $this->assertSame('uses-long-cache-ttl', $result['data']['unfixable'][0]['id']);
        $this->assertSame('mystery-audit', $result['data']['unknown'][0]['id']);
    }

    public function test_a_metrics_only_report_answers_no_lighthouse(): void
    {
        $client            = $this->client();
        $client->responses = array('reports/RSNIP' => $this->reportRow());
        $client->failOn['reports/RSNIP/resources/lighthouse.json'] = new GtmetrixApiException(
            'The Lighthouse JSON is unavailable for this report.',
            'gtmetrix_not_found',
            404,
            'E40410'
        );

        $result = ( new GtmetrixGenerateWpcodeSnippetTool($this->errors, $client) )
            ->execute(array('report_id' => 'RSNIP'), self::CONTEXT);

        $this->assertSame('gtmetrix_no_lighthouse', $result['error']['code']);
        $this->assertSame(404, $result['error']['data']['status']);
        $this->assertSame('E40410', $result['error']['data']['api_code']);
    }

    public function test_a_transport_failure_is_not_reported_as_a_missing_resource(): void
    {
        $client            = $this->client();
        $client->responses = array('reports/RSNIP' => $this->reportRow());
        $client->failOn['reports/RSNIP/resources/lighthouse.json'] = new GtmetrixApiException(
            'GTmetrix request failed: connection reset',
            'gtmetrix_request_failed',
            0
        );

        $result = ( new GtmetrixGenerateWpcodeSnippetTool($this->errors, $client) )
            ->execute(array('report_id' => 'RSNIP'), self::CONTEXT);

        $this->assertSame('gtmetrix_request_failed', $result['error']['code']);
    }

    public function test_a_report_id_is_required(): void
    {
        $client = $this->client();
        $result = ( new GtmetrixGenerateWpcodeSnippetTool($this->errors, $client) )
            ->execute(array('report_id' => '../etc/passwd'), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function client(): FakeGtmetrixClient
    {
        $client            = new FakeGtmetrixClient();
        $client->responses = array(
            'reports/RSNIP' => $this->reportRow(),
            'reports/RSNIP/resources/lighthouse.json' => array('document' => $this->lighthouseBody()),
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
                'id'         => 'RSNIP',
                'attributes' => array(
                    'url'            => 'https://example.test/',
                    'gtmetrix_grade' => 'D',
                ),
                'links'      => array(),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function lighthouseBody(): array
    {
        return array(
            'audits' => array(
                'render-blocking-resources' => array('title' => 'Eliminate render-blocking resources', '_impactScore' => 0.6),
                'font-display'              => array('title' => 'Ensure text remains visible', '_impactScore' => 0.5),
                'layout-shifts'             => array('title' => 'Avoid large layout shifts', '_impactScore' => 0.4),
                'third-party-summary'       => array('title' => 'Reduce third-party impact', '_impactScore' => 0.3),
            ),
        );
    }
}
