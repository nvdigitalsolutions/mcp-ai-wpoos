<?php

/**
 * PII / secret filter — shared redaction for text a runtime is about to persist.
 *
 * Ported from the WordPress harness helper
 * (`includes/harness/class-wp-mcp-ai-pii-filter.php`) so both runtimes redact
 * the same patterns. The filter is intentionally conservative: it catches the
 * common shapes (emails, US/E.164 phones, credit-card-shaped digits, US SSNs,
 * common API-key prefixes) and replaces them with a stable token so the
 * surrounding prose stays readable. A false positive is preferable to leaking a
 * secret into long-lived storage.
 *
 * This is *not* a DLP product — it is a guard rail before a write.
 *
 * ## Licence
 *
 * @license MIT
 * @since   1.6.0
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Harness;

final class PiiFilter
{
	public const REDACTED_EMAIL = '[REDACTED_EMAIL]';
	public const REDACTED_PHONE = '[REDACTED_PHONE]';
	public const REDACTED_CC = '[REDACTED_CC]';
	public const REDACTED_SSN = '[REDACTED_SSN]';
	public const REDACTED_KEY = '[REDACTED_KEY]';

	/**
	 * Default redaction patterns. Each entry: array( regex, token ).
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function patterns(): array
	{
		return array(
			// Emails.
			array( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', self::REDACTED_EMAIL ),
			// E.164 / US phone numbers (loose).
			array( '/(?<![0-9])(?:\+?\d{1,3}[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}(?![0-9])/', self::REDACTED_PHONE ),
			// Credit-card-shaped 13-19 digit runs (allowing space/dash separators).
			array( '/(?<![0-9])(?:\d[ -]?){13,19}(?![0-9])/', self::REDACTED_CC ),
			// US SSN.
			array( '/(?<![0-9])\d{3}-\d{2}-\d{4}(?![0-9])/', self::REDACTED_SSN ),
			// Common API key prefixes (OpenAI, Anthropic, Google, Stripe-style, generic Bearer).
			array( '/sk-[A-Za-z0-9_\-]{16,}/', self::REDACTED_KEY ),
			array( '/sk-ant-[A-Za-z0-9_\-]{16,}/', self::REDACTED_KEY ),
			array( '/AIza[0-9A-Za-z_\-]{30,}/', self::REDACTED_KEY ),
			array( '/(?<![A-Za-z0-9])(?:rk|pk)_(?:live|test)_[A-Za-z0-9]{16,}/', self::REDACTED_KEY ),
			array( '/Bearer\s+[A-Za-z0-9\-_\.=]{20,}/i', 'Bearer ' . self::REDACTED_KEY ),
			array( '/ghp_[A-Za-z0-9]{20,}/', self::REDACTED_KEY ),
		);
	}

	/**
	 * Redact PII / secrets from a string.
	 *
	 * @param string                                  $text     Input text.
	 * @param array<int, array{0: string, 1: string}>|null $patterns Override the default pattern table.
	 * @return array{text: string, redactions: int}
	 */
	public static function scrub(string $text, ?array $patterns = null): array
	{
		if ('' === $text) {
			return array( 'text' => '', 'redactions' => 0 );
		}

		$patterns ??= self::patterns();
		$redactions = 0;

		foreach ($patterns as $entry) {
			if (! is_array($entry) || count($entry) < 2) {
				continue;
			}

			$regex = (string) $entry[0];
			$token = (string) $entry[1];

			if ('' === $regex) {
				continue;
			}

			// Validate before applying: an invalid pattern returns false from
			// preg_match, which lets us skip it without a preg_replace warning.
			if (false === @preg_match($regex, '')) {
				continue;
			}

			$count = 0;
			$replaced = preg_replace($regex, $token, $text, -1, $count);

			if (null === $replaced) {
				continue;
			}

			$text = $replaced;
			$redactions += (int) $count;
		}

		return array( 'text' => $text, 'redactions' => $redactions );
	}

	/**
	 * Quick predicate: does the text contain any obvious secret/PII match?
	 *
	 * @param array<int, array{0: string, 1: string}>|null $patterns Override the default pattern table.
	 */
	public static function containsSecret(string $text, ?array $patterns = null): bool
	{
		return self::scrub($text, $patterns)['redactions'] > 0;
	}
}
