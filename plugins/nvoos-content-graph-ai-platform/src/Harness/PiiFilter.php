<?php
/**
 * PII / Secret filter — Layer F safety helper.
 *
 * Sweeps reflections, reward signals, and any other free-form text the harness
 * is about to persist for obvious personally identifiable information and
 * provider secrets. The filter is intentionally conservative: it catches the
 * common patterns (emails, US/E.164 phones, credit-card-shaped digits, US
 * SSNs, common API key prefixes) and redacts them with a stable token so the
 * surrounding prose stays readable. False positives are preferable to leaking
 * secrets into the agent memory CCT.
 *
 * Sites that need stricter or additional patterns can register them via the
 * `wp_mcp_ai_pii_filter_patterns` filter.
 *
 * This is *not* a substitute for a full DLP product — it is a guard rail
 * before the harness writes anything to long-lived storage.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PII / secret filter.
 */
class PiiFilter {

	/**
	 * Default redaction patterns. Each entry: array( regex, token ).
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function default_patterns() {
		return array(
			// Emails.
			array( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[REDACTED_EMAIL]' ),
			// E.164 / US phone numbers (loose).
			array( '/(?<![0-9])(?:\+?\d{1,3}[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}(?![0-9])/', '[REDACTED_PHONE]' ),
			// Credit-card-shaped 13-19 digit runs (allowing space/dash separators).
			array( '/(?<![0-9])(?:\d[ -]?){13,19}(?![0-9])/', '[REDACTED_CC]' ),
			// US SSN.
			array( '/(?<![0-9])\d{3}-\d{2}-\d{4}(?![0-9])/', '[REDACTED_SSN]' ),
			// Common API key prefixes (OpenAI, Anthropic, Google, Stripe-style, generic Bearer).
			array( '/sk-[A-Za-z0-9_\-]{16,}/', '[REDACTED_KEY]' ),
			array( '/sk-ant-[A-Za-z0-9_\-]{16,}/', '[REDACTED_KEY]' ),
			array( '/AIza[0-9A-Za-z_\-]{30,}/', '[REDACTED_KEY]' ),
			array( '/(?<![A-Za-z0-9])(?:rk|pk)_(?:live|test)_[A-Za-z0-9]{16,}/', '[REDACTED_KEY]' ),
			array( '/Bearer\s+[A-Za-z0-9\-_\.=]{20,}/i', 'Bearer [REDACTED_KEY]' ),
			array( '/ghp_[A-Za-z0-9]{20,}/', '[REDACTED_KEY]' ),
		);
	}

	/**
	 * Redact PII / secrets from a string. Returns the cleaned string and a
	 * count of how many redactions were made.
	 *
	 * @param string $text Input text.
	 * @return array{text:string,redactions:int} Cleaned text and redaction count.
	 */
	public static function scrub( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return array(
				'text'       => '',
				'redactions' => 0,
			);
		}

		$patterns = self::default_patterns();

		/**
		 * Filter the regex patterns used to scrub PII from harness writes.
		 *
		 * Each pattern is an array of [ regex, replacement_token ].
		 *
		 * @param array $patterns Default patterns.
		 */
		$patterns = (array) apply_filters( 'wp_mcp_ai_pii_filter_patterns', $patterns );

		$redactions = 0;
		foreach ( $patterns as $entry ) {
			if ( ! is_array( $entry ) || count( $entry ) < 2 ) {
				continue;
			}
			$regex = (string) $entry[0];
			$token = (string) $entry[1];
			if ( '' === $regex ) {
				continue;
			}

			$count = 0;
			// Validate the pattern before applying it: an invalid regex returns
			// false from preg_match, which lets us skip the entry without
			// triggering a warning from preg_replace.
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.NoSilencedErrors.Discouraged -- Filter authors may supply invalid patterns; we explicitly check for false.
			if ( false === @preg_match( $regex, '' ) ) {
				continue;
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.preg_replace_preg_replace -- Intentional regex redaction.
			$replaced = preg_replace( $regex, $token, $text, -1, $count );
			if ( null === $replaced ) {
				// Regex error — skip pattern but don't propagate.
				continue;
			}
			$text        = $replaced;
			$redactions += (int) $count;
		}

		return array(
			'text'       => $text,
			'redactions' => $redactions,
		);
	}

	/**
	 * Quick predicate: does the text contain any obvious secret/PII match?
	 *
	 * @param string $text Input.
	 * @return bool
	 */
	public static function contains_secret( $text ) {
		$result = self::scrub( $text );
		return $result['redactions'] > 0;
	}
}
