<?php

/**
 * PII / secret filter — shared redaction for text a runtime is about to persist.
 *
 * Ported from the WordPress harness helper
 * (`includes/harness/class-wp-mcp-ai-pii-filter.php`) so both runtimes redact
 * the same patterns. The filter is intentionally conservative: it catches the
 * common shapes (emails, US/E.164 phones, credit-card-shaped digits, US SSNs,
 * common API-key prefixes, AWS access key IDs, JWTs, PEM private-key blocks,
 * connection strings) and replaces them with a stable token so the surrounding
 * prose stays readable. A false positive is preferable to leaking a secret into
 * long-lived storage.
 *
 * **The pattern table is deliberately not shared with `OutputGuardrail`.**
 * Detection is tuned to over-report: a false positive costs a warning prefix, a
 * false negative leaks a credential. Redaction runs over text a user may
 * legitimately be discussing, so it has a much tighter false-positive budget.
 * Two shapes diverge for that reason:
 *
 *  - **Private keys.** Detection flags the `-----BEGIN … PRIVATE KEY-----`
 *    header, which is evidence enough to raise a finding. Redaction must match
 *    the whole block, because replacing the header alone would report success
 *    and leave the key material sitting in the text.
 *  - **Connection strings.** Detection's `[^\s]+` tail is fine when the cost of
 *    a false positive is a warning. Redaction stops before trailing sentence
 *    punctuation so it does not eat the full stop after a URL.
 *
 * Known limitation: a *truncated* PEM (a BEGIN with no matching END) is not
 * redacted, because the block pattern cannot close. `OutputGuardrail` still
 * detects the header on the output side.
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
	 * **Order is load-bearing.** Credential shapes that contain long digit runs
	 * come before the credit-card pattern, which would otherwise claim their
	 * digits and leave the identifying prefix behind (`AKIA[REDACTED_CC]`).
	 * Connection strings come before the email pattern, which would otherwise
	 * match the `user:pass@host` authority on its own and leave the scheme, host
	 * and database readable.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function patterns(): array
	{
		return array(
			// PEM private-key blocks — the whole block, not just the header.
			array( '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', self::REDACTED_KEY ),
			// AWS access key IDs: AKIA (long-term) and ASIA (STS).
			array( '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/', self::REDACTED_KEY ),
			// JWTs. The first two segments are base64 of JSON, so both open `eyJ`.
			array( '/\beyJ[A-Za-z0-9\-_]+\.eyJ[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+/', self::REDACTED_KEY ),
			// Connection strings, with or without embedded credentials.
			array( '/(?:mongodb(?:\+srv)?|postgres(?:ql)?|mysql|rediss?):\/\/[^\s]*[^\s.,;:!?)\]}>\'"]/', self::REDACTED_KEY ),
			// Emails.
			array( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', self::REDACTED_EMAIL ),
			// E.164 / US phone numbers (loose).
			array( '/(?<![0-9])(?:\+?\d{1,3}[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}(?![0-9])/', self::REDACTED_PHONE ),
			// Credit-card-shaped 13-19 digit runs (space/dash separators allowed).
			// The run ends on a digit so the separator after it is not swallowed.
			array( '/(?<![0-9])(?:\d[ -]?){12,18}\d(?![0-9])/', self::REDACTED_CC ),
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
