<?php

/**
 * Tests for the shared PII / secret filter.
 *
 * The filter is the only thing standing between a user's message and a durable
 * session log, so the test pins the pattern families and the two properties
 * that matter: everything that looks like a secret is replaced, and ordinary
 * prose is left alone.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Harness;

use Nvoos\Core\Domain\Service\Harness\PiiFilter;
use PHPUnit\Framework\TestCase;

final class PiiFilterTest extends TestCase
{
	/**
	 * @dataProvider redactions
	 */
	public function test_it_redacts_known_shapes(string $input, string $expectedToken, string $strippedFragment): void
	{
		$result = PiiFilter::scrub($input);

		$this->assertSame(1, $result['redactions']);
		$this->assertStringContainsString($expectedToken, $result['text']);
		$this->assertStringNotContainsString($strippedFragment, $result['text']);
	}

	public static function redactions(): array
	{
		return array(
			'email' => array( 'mail jane.doe@example.com please', PiiFilter::REDACTED_EMAIL, 'jane.doe@example.com' ),
			'us phone' => array( 'call 555-123-4567 now', PiiFilter::REDACTED_PHONE, '555-123-4567' ),
			'ssn' => array( 'ssn 123-45-6789 on file', PiiFilter::REDACTED_SSN, '123-45-6789' ),
			'openai key' => array( 'key sk-abcdefghijklmnopqrstuvwx here', PiiFilter::REDACTED_KEY, 'sk-abcdefghijklmnopqrstuvwx' ),
			'github token' => array( 'token ghp_abcdefghijklmnopqrstuvwx end', PiiFilter::REDACTED_KEY, 'ghp_abcdefghijklmnopqrstuvwx' ),
		);
	}

	public function test_a_card_number_is_redacted(): void
	{
		$result = PiiFilter::scrub('card 4111 1111 1111 1111 expires soon');

		$this->assertGreaterThanOrEqual(1, $result['redactions']);
		$this->assertStringNotContainsString('4111 1111 1111 1111', $result['text']);
	}

	public function test_it_leaves_ordinary_prose_alone(): void
	{
		$input = 'Summarise the third quarter results for the design team.';
		$result = PiiFilter::scrub($input);

		$this->assertSame(0, $result['redactions']);
		$this->assertSame($input, $result['text']);
	}

	public function test_an_empty_string_is_a_no_op(): void
	{
		$this->assertSame(array( 'text' => '', 'redactions' => 0 ), PiiFilter::scrub(''));
	}

	public function test_the_pattern_table_can_be_replaced(): void
	{
		$patterns = array( array( '/ACME-\d+/', '[REDACTED_ACME]' ) );

		$result = PiiFilter::scrub('order ACME-42 shipped', $patterns);

		$this->assertSame(1, $result['redactions']);
		$this->assertStringContainsString('[REDACTED_ACME]', $result['text']);
	}

	public function test_an_invalid_pattern_is_skipped_without_a_warning(): void
	{
		$patterns = array(
			array( '/(unclosed/', '[NOPE]' ),
			array( '/keep/', '[YES]' ),
		);

		$result = PiiFilter::scrub('keep this', $patterns);

		$this->assertSame(1, $result['redactions']);
		$this->assertSame('[YES] this', $result['text']);
	}

	public function test_contains_secret_is_a_predicate_over_scrub(): void
	{
		$this->assertTrue(PiiFilter::containsSecret('reach me at jane@example.com'));
		$this->assertFalse(PiiFilter::containsSecret('nothing to see here'));
	}
}
