<?php

/**
 * Tests for the output guard collaborator.
 *
 * The load-bearing property is the leak-proof return: when a response is
 * blocked, `content` is the empty string, so a caller that forwards what it is
 * given cannot render the text that was just blocked.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Security;

use Nvoos\Core\Application\Security\OutputGuard;
use Nvoos\Core\Domain\Service\Harness\OutputGuardrail;
use PHPUnit\Framework\TestCase;

final class OutputGuardTest extends TestCase
{
	private const LEAK = 'Reach me at alice@example.com for details.';

	public function test_a_disabled_guard_passes_everything_through(): void
	{
		$guard = new OutputGuard(enabled: false);

		$verdict = $guard->inspect(self::LEAK);

		$this->assertFalse($verdict['enabled']);
		$this->assertFalse($verdict['blocked']);
		$this->assertTrue($verdict['safe']);
		$this->assertSame(self::LEAK, $verdict['content']);
	}

	public function test_a_clean_response_is_safe_and_unchanged(): void
	{
		$guard = new OutputGuard();

		$verdict = $guard->inspect('The report is ready.');

		$this->assertTrue($verdict['enabled']);
		$this->assertTrue($verdict['safe']);
		$this->assertSame('The report is ready.', $verdict['content']);
		$this->assertSame('', $verdict['detail']);
	}

	public function test_warn_mode_annotates_and_keeps_the_text(): void
	{
		$guard = new OutputGuard(mode: OutputGuardrail::MODE_WARN);

		$verdict = $guard->inspect(self::LEAK);

		$this->assertFalse($verdict['blocked']);
		$this->assertFalse($verdict['safe']);
		$this->assertSame(array( 'sensitive' ), $verdict['findings']);
		$this->assertSame('[⚠️ sensitive] ' . self::LEAK, $verdict['content']);
		$this->assertStringContainsString( 'was annotated', $verdict['detail']);
	}

	/**
	 * The whole point of the empty-string rule: a blocked response must not be
	 * renderable from what this method hands back.
	 */
	public function test_block_mode_returns_no_content_at_all(): void
	{
		$guard = new OutputGuard(mode: OutputGuardrail::MODE_BLOCK);

		$verdict = $guard->inspect(self::LEAK);

		$this->assertTrue($verdict['blocked']);
		$this->assertSame('', $verdict['content']);
		$this->assertStringContainsString( 'was blocked', $verdict['detail'] );
		$this->assertStringContainsString( 'sensitive', $verdict['detail'] );
	}

	public function test_log_mode_reports_the_finding_and_delivers_the_text(): void
	{
		$guard = new OutputGuard(mode: OutputGuardrail::MODE_LOG);

		$verdict = $guard->inspect(self::LEAK);

		$this->assertFalse($verdict['blocked']);
		$this->assertFalse($verdict['safe']);
		$this->assertSame(self::LEAK, $verdict['content']);
	}

	public function test_the_strictness_tier_reaches_the_evaluator(): void
	{
		$text = 'Call 555-123-4567.';

		$medium = (new OutputGuard(strictness: OutputGuardrail::STRICTNESS_MEDIUM))->inspect($text);
		$high = (new OutputGuard(strictness: OutputGuardrail::STRICTNESS_HIGH))->inspect($text);

		$this->assertTrue($medium['safe'], 'A phone number is not a medium-tier finding.');
		$this->assertFalse($high['safe']);
	}

	public function test_from_profile_returns_null_when_the_layer_is_off(): void
	{
		$this->assertNull(OutputGuard::fromProfile(array('output_guard' => array('enabled' => false))));
		$this->assertNull(OutputGuard::fromProfile(array()));
		$this->assertNull(OutputGuard::fromProfile(array('output_guard' => 'nope')));
	}

	public function test_from_profile_carries_the_profile_config(): void
	{
		$guard = OutputGuard::fromProfile(
			array(
				'output_guard' => array(
					'enabled' => true,
					'mode' => 'block',
					'strictness' => 'high',
				),
			)
		);

		$this->assertInstanceOf(OutputGuard::class, $guard);
		$this->assertTrue($guard->enabled());
		$this->assertSame('block', $guard->mode());
		$this->assertSame('high', $guard->strictness());
	}

	public function test_from_profile_normalises_out_of_range_enums(): void
	{
		$guard = OutputGuard::fromProfile(
			array(
				'output_guard' => array(
					'enabled' => true,
					'mode' => 'nuke',
					'strictness' => 'paranoid',
				),
			)
		);

		$this->assertNotNull($guard);
		$this->assertSame(OutputGuardrail::MODE_WARN, $guard->mode());
		$this->assertSame(OutputGuardrail::STRICTNESS_MEDIUM, $guard->strictness());
	}

	public function test_the_withheld_notice_is_a_fixed_sentence(): void
	{
		$this->assertStringNotContainsString('alice@', OutputGuard::WITHHELD_NOTICE);
		$this->assertStringContainsString('withheld', OutputGuard::WITHHELD_NOTICE);
	}
}
