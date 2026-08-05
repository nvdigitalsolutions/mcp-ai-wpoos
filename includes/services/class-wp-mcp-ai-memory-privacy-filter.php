<?php
/**
 * Memory Privacy Filter — Phase 1 of the 2026 Memory Layer Enhancements.
 *
 * Strips secrets, API keys, bearer tokens, and explicitly-tagged private blocks
 * from memory writes BEFORE persistence. Hooks `wp_mcp_ai_memory_pre_store_transform`
 * at priority 5 so it runs before any user transform (which defaults to 10).
 *
 * Design contract (documented in {@see WP_MCP_AI_Memory_Capture_Service::store()}
 * around line 125):
 *
 *   "Verbatim records run through here too — a redactor is the only sanctioned
 *    way to drop PHI / secrets BEFORE the verbatim discipline kicks in."
 *
 * This service is the redactor referenced in that comment. Verbatim discipline
 * guarantees preservation of *surviving* content; it does NOT bypass redaction.
 *
 * Inspired by `rohitg00/agentmemory`'s SHA-256 dedup + privacy filter pipeline,
 * implemented in a way that respects every existing memory-layer extension
 * point (the filter is purely additive — no API surface changes).
 *
 * @link    https://github.com/rohitg00/agentmemory
 * @link    https://github.com/MemPalace/mempalace
 *
 * @package WP_MCP_AI
 * @since   1.1.20
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Memory write-path secret-redaction service.
 *
 * Stateless. Single hook attached at bootstrap. Every method is `public static`
 * so headless tests can call them directly without spinning up WordPress.
 */
class WP_MCP_AI_Memory_Privacy_Filter {

	/**
	 * Default replacement string used in place of redacted secrets.
	 */
	const DEFAULT_REPLACEMENT = '[REDACTED]';

	/**
	 * Filter priority used when hooking `wp_mcp_ai_memory_pre_store_transform`.
	 *
	 * MUST be lower than the default WordPress priority (10) so the redaction
	 * pass runs before any caller-supplied transform that might serialise,
	 * paraphrase, or log the data.
	 */
	const FILTER_PRIORITY = 5;

	/**
	 * Maximum number of arguments accepted from `wp_mcp_ai_memory_pre_store_transform`.
	 *
	 * The filter has two distinct call sites with two distinct signatures:
	 *
	 *  - {@see WP_MCP_AI_Memory_Capture_Service::store()} passes 2 args:
	 *    `($normalised_envelope, $normalised_envelope)`.
	 *  - {@see WP_MCP_AI_Tool_Store_Agent_Context::execute()} passes 6 args:
	 *    `($context_data, $verbatim, $context_type, $agent_id, $arguments, $context)`.
	 *
	 * We register `add_filter()` with `$accepted_args = 6` so PHP forwards
	 * whatever the caller supplies; our callback defaults every argument so it
	 * is safe to call with either signature.
	 */
	const FILTER_ACCEPTED_ARGS = 6;

	/**
	 * Singleton bootstrap flag — guards against double-hook on re-include.
	 *
	 * @var bool
	 */
	private static $bootstrapped = false;

	/**
	 * Wire the filter into the memory write path.
	 *
	 * Idempotent: re-calling `bootstrap()` is a no-op. Disable the entire
	 * subsystem by short-circuiting at the `wp_mcp_ai_memory_privacy_filter_enabled`
	 * filter (default: enabled).
	 */
	public static function bootstrap() {
		if ( self::$bootstrapped ) {
			return;
		}

		/**
		 * Master kill-switch for the memory privacy filter.
		 *
		 * Disabling this is strongly discouraged because the filter is the only
		 * sanctioned redaction surface for verbatim memory records. Leaving it
		 * off means OpenAI/Anthropic API keys, AWS credentials, and bearer
		 * tokens may be stored in long-term memory and replayed at session boot.
		 *
		 * @since 1.1.20
		 *
		 * @param bool $enabled Defaults to true.
		 */
		if ( ! (bool) apply_filters( 'wp_mcp_ai_memory_privacy_filter_enabled', true ) ) {
			self::$bootstrapped = true;
			return;
		}

		add_filter(
			'wp_mcp_ai_memory_pre_store_transform',
			array( __CLASS__, 'apply_redaction' ),
			self::FILTER_PRIORITY,
			self::FILTER_ACCEPTED_ARGS
		);

		self::$bootstrapped = true;
	}

	/**
	 * Default redaction patterns covering the most common secret formats.
	 *
	 * Pattern set is filterable via `wp_mcp_ai_memory_privacy_patterns`.
	 * Each pattern is a string regex that will be passed to `preg_replace()`
	 * with the configured replacement string.
	 *
	 * Patterns favour false-negatives over false-positives: only well-known
	 * secret schemes that begin with a vendor-specific prefix and use a
	 * non-trivial alphabet are matched, so prose content with arbitrary
	 * letter/digit runs is not mangled.
	 *
	 * @return array<string,string> Map of pattern-label => regex.
	 */
	public static function default_patterns() {
		return array(
			// OpenAI keys (current `sk-...` and project `sk-proj-...` formats).
			'openai_key'          => '/\bsk-(?:proj-)?[A-Za-z0-9_\-]{20,}\b/',
			// Anthropic keys (`sk-ant-...`).
			'anthropic_key'       => '/\bsk-ant-[A-Za-z0-9_\-]{20,}\b/',
			// AWS access key IDs.
			'aws_access_key'      => '/\bAKIA[0-9A-Z]{16}\b/',
			// AWS secret access keys (40 base64-ish chars following the literal phrase).
			'aws_secret_key'      => '/(?i)aws_secret_access_key\s*[:=]\s*["\']?[A-Za-z0-9\/+=]{40}["\']?/',
			// GitHub personal access tokens.
			'github_pat'          => '/\bghp_[A-Za-z0-9]{36}\b/',
			// GitHub server tokens.
			'github_server_token' => '/\bghs_[A-Za-z0-9]{36}\b/',
			// GitHub OAuth user tokens.
			'github_oauth_token'  => '/\bgho_[A-Za-z0-9]{36}\b/',
			// Google API keys (`AIza...`).
			'google_api_key'      => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
			// Slack bot/user/legacy tokens.
			'slack_token'         => '/\bxox[abprs]-[A-Za-z0-9\-]{10,}\b/',
			// Stripe live + test secret keys.
			'stripe_secret_key'   => '/\bsk_(?:live|test)_[A-Za-z0-9]{20,}\b/',
			// Generic bearer tokens (only in `Authorization: Bearer` context to avoid
			// stripping the word "Bearer" in prose).
			'bearer_token'        => '/(?i)(authorization\s*:\s*bearer\s+)[A-Za-z0-9._\-]{20,}/',
			// Private blocks the user marks explicitly.
			'private_block'       => '/<private>[\s\S]*?<\/private>/i',
			// PEM-encoded private keys.
			'pem_private_key'     => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----[\s\S]+?-----END (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----/',
		);
	}

	/**
	 * Filter callback — redacts every known secret pattern from a memory record.
	 *
	 * Defensive against both filter signatures (2 args from the capture service,
	 * 6 args from the `store_agent_context` tool). Returns the record unchanged
	 * when no secrets are detected.
	 *
	 * @since 1.1.20
	 *
	 * @param array      $context_data The record about to be persisted. Expected
	 *                                 to be an array with `title` and `content`
	 *                                 keys, plus optional `metadata` (array),
	 *                                 `tags` (array), `data` (nested record), etc.
	 * @param mixed      $arg2         Second filter argument. From the capture
	 *                                 service this is the envelope itself
	 *                                 (array); from the tool this is `$verbatim`
	 *                                 (bool). Ignored — kept for filter
	 *                                 signature compatibility.
	 * @param mixed|null $arg3         Filter signature compatibility.
	 * @param mixed|null $arg4         Filter signature compatibility.
	 * @param mixed|null $arg5         Filter signature compatibility.
	 * @param mixed|null $arg6         Filter signature compatibility.
	 *
	 * @return array Redacted record. Same shape as the input.
	 */
	public static function apply_redaction( $context_data, $arg2 = null, $arg3 = null, $arg4 = null, $arg5 = null, $arg6 = null ) {
		unset( $arg2, $arg3, $arg4, $arg5, $arg6 );

		if ( ! is_array( $context_data ) ) {
			return $context_data;
		}

		$patterns = self::resolve_patterns( $context_data );
		if ( empty( $patterns ) ) {
			return $context_data;
		}

		$replacement = self::resolve_replacement( $context_data );
		$counter     = 0;

		$redacted = self::redact_array_recursive( $context_data, $patterns, $replacement, $counter );

		if ( $counter > 0 ) {
			/**
			 * Fires when the privacy filter redacts one or more secrets.
			 *
			 * Listeners can record the redaction in the audit trail without
			 * exposing the original secret string. The action only fires when
			 * `wp_mcp_ai_memory_privacy_log_redactions` is true (default: false).
			 *
			 * @since 1.1.20
			 *
			 * @param int   $count          Number of pattern-hit redactions performed.
			 * @param array $redacted_record The final record after redaction.
			 */
			if ( (bool) apply_filters( 'wp_mcp_ai_memory_privacy_log_redactions', false ) ) {
				do_action( 'wp_mcp_ai_memory_privacy_redacted', $counter, $redacted );
			}
		}

		return $redacted;
	}

	/**
	 * Determine the active regex pattern set for this redaction call.
	 *
	 * Returns the default set merged with whatever
	 * `wp_mcp_ai_memory_privacy_patterns` returns. Filters that return non-array
	 * values are ignored (with a `_doing_it_wrong` notice when WP_DEBUG is on).
	 *
	 * @param array $context_data The record being filtered (passed through to
	 *                            the filter for context-sensitive overrides).
	 * @return array<string,string> Effective pattern set.
	 */
	protected static function resolve_patterns( array $context_data ) {
		$patterns = self::default_patterns();

		/**
		 * Filter the active regex pattern set used by the privacy filter.
		 *
		 * Callbacks receive the default pattern map (label => regex) and the
		 * record being redacted. Returning a non-array drops the override.
		 *
		 * @since 1.1.20
		 *
		 * @param array<string,string> $patterns     Default pattern map.
		 * @param array                $context_data Record being filtered.
		 */
		$filtered = apply_filters( 'wp_mcp_ai_memory_privacy_patterns', $patterns, $context_data );

		if ( ! is_array( $filtered ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				_doing_it_wrong(
					'wp_mcp_ai_memory_privacy_patterns',
					esc_html__( 'Filter listeners must return an associative array of pattern label => regex. Using defaults instead.', 'mcp-ai-wpoos' ),
					'1.1.20'
				);
			}
			return $patterns;
		}

		// Drop entries that are not non-empty strings — bad regexes silently
		// throw warnings from preg_replace.
		$cleaned = array();
		foreach ( $filtered as $label => $regex ) {
			if ( is_string( $regex ) && '' !== $regex ) {
				$cleaned[ (string) $label ] = $regex;
			}
		}

		return $cleaned;
	}

	/**
	 * Resolve the replacement string used in place of redacted matches.
	 *
	 * @param array $context_data The record being filtered.
	 * @return string Replacement string. Always non-empty.
	 */
	protected static function resolve_replacement( array $context_data ) {
		/**
		 * Filter the replacement string substituted in place of detected secrets.
		 *
		 * @since 1.1.20
		 *
		 * @param string $replacement  Default `[REDACTED]`.
		 * @param array  $context_data Record being filtered.
		 */
		$replacement = apply_filters(
			'wp_mcp_ai_memory_privacy_replacement',
			self::DEFAULT_REPLACEMENT,
			$context_data
		);

		if ( ! is_string( $replacement ) || '' === $replacement ) {
			$replacement = self::DEFAULT_REPLACEMENT;
		}

		return $replacement;
	}

	/**
	 * Recursively redact every string value in the array against every pattern.
	 *
	 * Walks every nested array (`data`, `metadata`, `tags`, etc.) so secrets
	 * tucked inside nested structures are still stripped. Non-string scalars
	 * are returned unchanged. Strings shorter than the shortest possible
	 * pattern match (16 chars by convention) are skipped for speed.
	 *
	 * @param array                $input       Array to walk.
	 * @param array<string,string> $patterns    Active pattern set.
	 * @param string               $replacement Replacement string.
	 * @param int                  $counter     Reference counter incremented per match.
	 * @return array Same shape as input, with strings redacted.
	 */
	protected static function redact_array_recursive( array $input, array $patterns, $replacement, &$counter ) {
		foreach ( $input as $key => $value ) {
			if ( is_array( $value ) ) {
				$input[ $key ] = self::redact_array_recursive( $value, $patterns, $replacement, $counter );
				continue;
			}

			if ( ! is_string( $value ) ) {
				continue;
			}

			$input[ $key ] = self::redact_string( $value, $patterns, $replacement, $counter );
		}

		return $input;
	}

	/**
	 * Apply every pattern in the set to a single string.
	 *
	 * Public so the test suite and sibling services (e.g. the upcoming
	 * auto-capture service in Phase 3) can call it directly without going
	 * through the filter chain.
	 *
	 * @since 1.1.20
	 *
	 * @param string               $value       Input string.
	 * @param array<string,string> $patterns    Pattern label => regex map.
	 * @param string               $replacement Replacement to substitute on match.
	 * @param int                  $counter     Reference counter; incremented by total match count.
	 * @return string Redacted string.
	 */
	public static function redact_string( $value, array $patterns, $replacement, &$counter ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		foreach ( $patterns as $regex ) {
			$matches = 0;
			$result  = @preg_replace( $regex, $replacement, $value, -1, $matches );

			// `preg_replace` returns null on failure (e.g. invalid regex). Keep
			// the original string in that case so a broken filter contributor
			// cannot data-loss the record.
			if ( null === $result ) {
				continue;
			}

			$value    = $result;
			$counter += (int) $matches;
		}

		return $value;
	}

	/**
	 * Convenience entry point for direct callers (tests, sibling services).
	 *
	 * Equivalent to running the full filter pipeline against a fabricated
	 * record without registering the WordPress hook. Useful for Phase 3's
	 * auto-capture service which wants to redact before computing the
	 * SHA-256 content hash.
	 *
	 * @since 1.1.20
	 *
	 * @param string $text Raw text.
	 * @return string Redacted text.
	 */
	public static function redact( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return $text;
		}

		$patterns    = self::resolve_patterns( array( 'content' => $text ) );
		$replacement = self::resolve_replacement( array( 'content' => $text ) );
		$counter     = 0;

		return self::redact_string( $text, $patterns, $replacement, $counter );
	}
}
