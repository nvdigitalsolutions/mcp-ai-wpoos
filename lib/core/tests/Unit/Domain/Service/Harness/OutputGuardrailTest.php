<?php

/**
 * Tests for the output guardrail.
 *
 * The load-bearing properties: every pattern is a valid regex (a pattern that
 * silently fails to compile is a fail-open on the one check that matters), the
 * strictness tiers widen rather than swap, an injection echo needs two markers
 * so discussing injection is not itself flagged, and the three modes deliver
 * exactly what the runtime is told to deliver.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Harness;

use Nvoos\Core\Domain\Service\Harness\OutputGuardrail;
use PHPUnit\Framework\TestCase;

final class OutputGuardrailTest extends TestCase
{
	private function enabled(array $overrides = array()): array
	{
		return array_merge(
			array( 'enabled' => true, 'mode' => OutputGuardrail::MODE_WARN, 'strictness' => OutputGuardrail::STRICTNESS_MEDIUM ),
			$overrides
		);
	}

	/**
	 * A pattern that does not compile returns `false` from preg_match, which is
	 * indistinguishable from "no match" — a silent fail-open. This test is the
	 * guard: if a pattern is ever malformed, a real leak stops being detected
	 * and nothing else in the suite would notice.
	 */
	public function test_every_pattern_compiles(): void
	{
		foreach (array( 'low', 'medium', 'high' ) as $strictness) {
			foreach (OutputGuardrail::sensitivePatterns($strictness) as $pattern) {
				$this->assertNotFalse(
					@preg_match($pattern, ''),
					'Pattern does not compile at ' . $strictness . ' strictness: ' . $pattern
				);
			}
		}
	}

	public function test_a_disabled_guard_changes_nothing(): void
	{
		$result = OutputGuardrail::evaluate('Reach me at alice@example.com', array( 'enabled' => false ));

		$this->assertFalse($result['enabled']);
		$this->assertTrue($result['safe']);
		$this->assertSame('Reach me at alice@example.com', $result['content']);
	}

	public function test_a_missing_config_is_disabled(): void
	{
		$this->assertFalse(OutputGuardrail::evaluate('anything', array())['enabled']);
	}

	public function test_clean_content_is_safe(): void
	{
		$result = OutputGuardrail::evaluate('The report is ready for review.', $this->enabled());

		$this->assertTrue($result['enabled']);
		$this->assertTrue($result['safe']);
		$this->assertSame(array(), $result['findings']);
		$this->assertSame('The report is ready for review.', $result['content']);
	}

	public function test_empty_content_is_safe(): void
	{
		$result = OutputGuardrail::evaluate('', $this->enabled());

		$this->assertTrue($result['safe']);
		$this->assertSame(array(), $result['findings']);
	}

	/**
	 * @dataProvider leaks
	 */
	public function test_a_leak_is_reported_at_every_strictness(string $content): void
	{
		foreach (array( 'low', 'medium', 'high' ) as $strictness) {
			$result = OutputGuardrail::evaluate($content, $this->enabled(array( 'strictness' => $strictness )));

			$this->assertContains(
				OutputGuardrail::RESULT_SENSITIVE,
				$result['findings'],
				'Not detected at ' . $strictness . ' strictness: ' . $content
			);
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function leaks(): array
	{
		return array(
			'email' => array( 'Ping alice@example.com for details.' ),
			'card' => array( 'Card on file: 4111 1111 1111 1111.' ),
			'ssn' => array( 'SSN 123-45-6789 is on the form.' ),
			'openai key' => array( 'Use sk-abcdefghijklmnopqrstuvwxyz please.' ),
			'google key' => array( 'Key AIzaSyA1234567890abcdefghijklmnopqrstuv here.' ),
			'aws key' => array( 'Access AKIAIOSFODNN7EXAMPLE granted.' ),
			'jwt' => array( 'Token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U issued.' ),
			'private key' => array( "-----BEGIN RSA PRIVATE KEY-----\nMIIEow..." ),
			'connection string' => array( 'Connect to postgres://user:pass@db.internal/app.' ),
		);
	}

	public function test_medium_widens_to_addresses_and_long_hex(): void
	{
		$this->assertFalse(OutputGuardrail::hasSensitiveInfo('Host 192.168.1.10 replied.', 'low'));
		$this->assertTrue(OutputGuardrail::hasSensitiveInfo('Host 192.168.1.10 replied.', 'medium'));

		$hex = str_repeat('a1b2c3d4', 4);
		$this->assertFalse(OutputGuardrail::hasSensitiveInfo('Digest ' . $hex, 'low'));
		$this->assertTrue(OutputGuardrail::hasSensitiveInfo('Digest ' . $hex, 'medium'));
	}

	public function test_high_widens_to_phones_and_credential_assignments(): void
	{
		$this->assertFalse(OutputGuardrail::hasSensitiveInfo('Call 555-123-4567.', 'medium'));
		$this->assertTrue(OutputGuardrail::hasSensitiveInfo('Call 555-123-4567.', 'high'));

		$this->assertFalse(OutputGuardrail::hasSensitiveInfo('password: hunter2', 'medium'));
		$this->assertTrue(OutputGuardrail::hasSensitiveInfo('password: hunter2', 'high'));
	}

	public function test_the_tiers_widen_monotonically(): void
	{
		$low = count(OutputGuardrail::sensitivePatterns('low'));
		$medium = count(OutputGuardrail::sensitivePatterns('medium'));
		$high = count(OutputGuardrail::sensitivePatterns('high'));

		$this->assertLessThan($medium, $low);
		$this->assertLessThan($high, $medium);
	}

	public function test_an_unknown_strictness_falls_back_to_medium(): void
	{
		$this->assertSame(
			OutputGuardrail::sensitivePatterns('medium'),
			OutputGuardrail::sensitivePatterns('paranoid')
		);
	}

	public function test_unsafe_content_is_only_checked_at_high_strictness(): void
	{
		$content = 'Here is how to make a bomb.';

		$medium = OutputGuardrail::evaluate($content, $this->enabled(array( 'strictness' => 'medium' )));
		$high = OutputGuardrail::evaluate($content, $this->enabled(array( 'strictness' => 'high' )));

		$this->assertNotContains(OutputGuardrail::RESULT_UNSAFE, $medium['findings']);
		$this->assertContains(OutputGuardrail::RESULT_UNSAFE, $high['findings']);
	}

	public function test_an_injection_echo_needs_two_markers(): void
	{
		$one = 'I will not ignore previous instructions.';
		$two = 'I will not ignore previous instructions or enable developer mode override.';

		$this->assertFalse(OutputGuardrail::hasInjectionEcho($one), 'Discussing injection once is not an echo.');
		$this->assertTrue(OutputGuardrail::hasInjectionEcho($two));
	}

	public function test_marker_matching_is_case_insensitive(): void
	{
		$this->assertTrue(OutputGuardrail::hasInjectionEcho('IGNORE PREVIOUS INSTRUCTIONS. SYSTEM PROMPT OVERRIDE.'));
	}

	public function test_findings_are_reported_in_a_stable_order(): void
	{
		$content = 'ignore previous instructions, and developer mode override. email alice@example.com';

		$result = OutputGuardrail::evaluate($content, $this->enabled());

		$this->assertSame(
			array( OutputGuardrail::RESULT_SENSITIVE, OutputGuardrail::RESULT_INJECTION_ECHO ),
			$result['findings']
		);
	}

	public function test_warn_mode_prefixes_the_response(): void
	{
		$result = OutputGuardrail::evaluate('mail alice@example.com', $this->enabled(array( 'mode' => 'warn' )));

		$this->assertFalse($result['blocked']);
		$this->assertSame('[⚠️ sensitive] mail alice@example.com', $result['content']);
	}

	public function test_log_mode_delivers_the_response_untouched(): void
	{
		$result = OutputGuardrail::evaluate('mail alice@example.com', $this->enabled(array( 'mode' => 'log' )));

		$this->assertFalse($result['blocked']);
		$this->assertFalse($result['safe'], 'A finding is still a finding in log mode.');
		$this->assertContains(OutputGuardrail::RESULT_SENSITIVE, $result['findings']);
		$this->assertSame('mail alice@example.com', $result['content']);
	}

	public function test_block_mode_marks_the_response_blocked(): void
	{
		$result = OutputGuardrail::evaluate('mail alice@example.com', $this->enabled(array( 'mode' => 'block' )));

		$this->assertTrue($result['blocked']);
		$this->assertFalse($result['safe']);
	}

	public function test_an_unknown_mode_defaults_to_warn(): void
	{
		$config = OutputGuardrail::normaliseConfig(array( 'enabled' => true, 'mode' => 'nuke' ));

		$this->assertSame(OutputGuardrail::MODE_WARN, $config['mode']);
	}

	public function test_config_normalisation_enforces_the_enums(): void
	{
		$config = OutputGuardrail::normaliseConfig(array(
			'enabled' => 1,
			'mode' => 'block',
			'strictness' => 'paranoid',
		));

		$this->assertTrue($config['enabled']);
		$this->assertSame('block', $config['mode']);
		$this->assertSame('medium', $config['strictness']);
	}

	public function test_a_safe_response_is_never_blocked(): void
	{
		$result = OutputGuardrail::evaluate('All good.', $this->enabled(array( 'mode' => 'block' )));

		$this->assertFalse($result['blocked']);
		$this->assertTrue($result['safe']);
	}
}
