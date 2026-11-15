<?php

/**
 * Tests for the guardrail block policy.
 *
 * The load-bearing properties: a safe message never blocks in any mode, `log`
 * never blocks even a jailbreak, `block` blocks any finding, and `warn` blocks
 * the two security findings but only blocks off-topic at high strictness.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Harness;

use Nvoos\Core\Domain\Service\Harness\GuardrailPolicy;
use Nvoos\Core\Domain\Service\Harness\PromptInjectionDetector;
use PHPUnit\Framework\TestCase;

final class GuardrailPolicyTest extends TestCase
{
    private function analysis(string $result): array
    {
        return array(
            'result' => $result,
            'family' => 'test',
            'matches' => array(),
            'confidence' => 0.9,
            'severity' => 'high',
        );
    }

    public function test_a_safe_message_never_blocks(): void
    {
        foreach (array( 'block', 'warn', 'log' ) as $mode) {
            $this->assertFalse(
                GuardrailPolicy::decide($this->analysis(PromptInjectionDetector::RESULT_SAFE), $mode, 'high'),
                'Safe message blocked in ' . $mode . ' mode.'
            );
        }
    }

    public function test_log_mode_never_blocks_even_a_jailbreak(): void
    {
        $this->assertFalse(
            GuardrailPolicy::decide($this->analysis(PromptInjectionDetector::RESULT_JAILBREAK), 'log', 'high')
        );
    }

    public function test_block_mode_blocks_any_finding(): void
    {
        foreach (array( 'jailbreak', 'injection', 'off_topic' ) as $result) {
            $this->assertTrue(
                GuardrailPolicy::decide($this->analysis($result), 'block', 'low'),
                'Not blocked in block mode: ' . $result
            );
        }
    }

    public function test_warn_mode_blocks_a_jailbreak_and_an_injection(): void
    {
        $this->assertTrue(GuardrailPolicy::decide($this->analysis('jailbreak'), 'warn', 'low'));
        $this->assertTrue(GuardrailPolicy::decide($this->analysis('injection'), 'warn', 'low'));
    }

    public function test_warn_mode_lets_off_topic_through_below_high_strictness(): void
    {
        foreach (array( 'low', 'medium' ) as $strictness) {
            $this->assertFalse(
                GuardrailPolicy::decide($this->analysis('off_topic'), 'warn', $strictness),
                'Off-topic blocked at ' . $strictness . ' strictness.'
            );
        }
    }

    public function test_warn_mode_blocks_off_topic_at_high_strictness(): void
    {
        $this->assertTrue(GuardrailPolicy::decide($this->analysis('off_topic'), 'warn', 'high'));
    }

    public function test_an_unknown_mode_behaves_like_warn(): void
    {
        // Not `block` (which blocks off-topic) and not `log` (which blocks nothing).
        $this->assertFalse(GuardrailPolicy::decide($this->analysis('off_topic'), 'nuke', 'medium'));
        $this->assertTrue(GuardrailPolicy::decide($this->analysis('jailbreak'), 'nuke', 'medium'));
    }

    public function test_a_malformed_analysis_is_treated_as_safe(): void
    {
        $this->assertFalse(GuardrailPolicy::decide(array(), 'block', 'high'));
    }

    public function test_message_keys_cover_every_result(): void
    {
        $this->assertSame('jailbreak', GuardrailPolicy::messageKey($this->analysis('jailbreak')));
        $this->assertSame('injection', GuardrailPolicy::messageKey($this->analysis('injection')));
        $this->assertSame('off_topic', GuardrailPolicy::messageKey($this->analysis('off_topic')));
        $this->assertSame('generic', GuardrailPolicy::messageKey($this->analysis('safe')));
        $this->assertSame('generic', GuardrailPolicy::messageKey(array()));
    }
}
