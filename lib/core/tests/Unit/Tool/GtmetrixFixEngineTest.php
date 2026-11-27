<?php

/**
 * Tests for the GTmetrix fix engine's snippet generator.
 *
 * The block code is a list of PHP *strings*, and one of them contains `$src` and
 * `$handle` inside a double-quoted literal — where PHP interpolates, an undefined
 * variable becomes an empty string and a warning. The generated snippet was
 * therefore corrupt (`function ( ,  ) {`) and generating it raised a warning that
 * nothing caught, because no test asserted on a snippet at all. The first case
 * here is that regression: PHPUnit surfaces the warning, and the literal text is
 * asserted so the escaping cannot be dropped again.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Tool\GTmetrix\GtmetrixFixEngine;
use PHPUnit\Framework\TestCase;

final class GtmetrixFixEngineTest extends TestCase
{
    public function test_the_conditional_css_block_emits_literal_variables(): void
    {
        $snippet = GtmetrixFixEngine::generateSnippet(
            array(
                array(
                    'block'   => 'conditional-plugin-css',
                    'symptom' => 'Unused plugin CSS loads everywhere.',
                    'action'  => 'Dequeue the plugin stylesheet off its own pages.',
                    'risk'    => 'medium',
                ),
            ),
            'rpt-1'
        );

        $this->assertStringContainsString('function ( $src, $handle )', $snippet);
        $this->assertStringContainsString('return $src;', $snippet);
        $this->assertStringContainsString("'REPLACE_WITH_HANDLE' !== \$handle", $snippet);
    }

    public function test_a_safe_block_is_emitted_enabled(): void
    {
        $snippet = GtmetrixFixEngine::generateSnippet(
            array(
                array(
                    'block'   => 'lcp-eager-hero',
                    'symptom' => 'The hero image is lazy-loaded.',
                    'action'  => 'Omit lazy loading for the first content image.',
                    'risk'    => 'safe',
                ),
            ),
            'rpt-2',
            'https://example.test/'
        );

        $this->assertStringStartsWith('<?php', $snippet);
        $this->assertStringContainsString('Generated from GTmetrix report: rpt-2', $snippet);
        $this->assertStringContainsString(' * Page: https://example.test/', $snippet);
        $this->assertStringContainsString("add_filter( 'wp_omit_loading_attr_threshold'", $snippet);
        $this->assertStringNotContainsString('TEST ON STAGING', $snippet);
    }

    public function test_a_high_risk_block_is_commented_out(): void
    {
        $snippet = GtmetrixFixEngine::generateSnippet(
            array(
                array(
                    'block'   => 'lazy-recaptcha',
                    'symptom' => 'reCAPTCHA blocks the main thread.',
                    'action'  => 'Load it on first interaction.',
                    'risk'    => 'high',
                ),
            ),
            'rpt-3'
        );

        $this->assertStringContainsString('TEST ON STAGING BEFORE ENABLING - RISK: HIGH.', $snippet);
        $this->assertStringContainsString('/*', $snippet);
        $this->assertStringContainsString('*/', $snippet);
    }

    public function test_a_fix_without_a_block_is_not_represented(): void
    {
        $snippet = GtmetrixFixEngine::generateSnippet(
            array(
                array(
                    'symptom' => 'Two analytics tags load.',
                    'action'  => 'Keep one.',
                    'risk'    => 'medium',
                ),
            ),
            'rpt-4'
        );

        $this->assertStringNotContainsString('Fix: Two analytics tags load.', $snippet);
    }

    public function test_an_unknown_block_keeps_its_comment_and_emits_no_code(): void
    {
        $snippet = GtmetrixFixEngine::generateSnippet(
            array(
                array(
                    'block'   => 'not-a-real-block',
                    'symptom' => 'Unknown.',
                    'action'  => 'Nothing.',
                    'risk'    => 'safe',
                ),
            ),
            'rpt-5'
        );

        $this->assertStringContainsString('Fix: Unknown.', $snippet);
        $this->assertStringContainsString('Action: Nothing.', $snippet);
        $this->assertStringNotContainsString('add_filter(', $snippet);
    }
}
