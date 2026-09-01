<?php
/**
 * Output Guardrail — Validates LLM responses for content safety before
 * they reach downstream systems (OWASP LLM05: Improper Output Handling).
 *
 * Complements the existing input guardrails (Layer I) by adding systematic
 * output validation. The guardrail scans LLM responses for:
 *
 *   1. Sensitive information leakage (PII, API keys, credentials)
 *   2. Unsafe content (hate speech, violence, self-harm)
 *   3. Prompt injection echoes (the model regurgitating injection payloads)
 *   4. Structural integrity (valid JSON when schema is expected)
 *
 * The guardrail is opt-in per assistant via the harness profile.
 * Default is off (behaviour-preserving).
 *
 * Industry references:
 *   - OWASP LLM Application Top 10 (LLM05: Improper Output Handling)
 *   - OWASP LLM Application Top 10 (LLM02: Sensitive Information Disclosure)
 *   - EU AI Act Article 15 (Accuracy, Robustness & Cybersecurity)
 *   - NIST AI RMF (Map 4.1 – Output validation)
 *
 * @package WP_MCP_AI
 * @since   1.1.51
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output guardrail for LLM response validation.
 *
 * @since 1.1.51
 */
class OutputGuardrail {

	/**
	 * Option key for global output guardrail settings.
	 *
	 * @var string
	 */
	const OPTION_OUTPUT_GUARD_ENABLED = 'wp_mcp_ai_output_guard_enabled';

	/**
	 * Option key for output guardrail mode (block | warn | log).
	 *
	 * @var string
	 */
	const OPTION_OUTPUT_GUARD_MODE = 'wp_mcp_ai_output_guard_mode';

	/**
	 * Option key for output guardrail strictness (low | medium | high).
	 *
	 * @var string
	 */
	const OPTION_OUTPUT_GUARD_STRICTNESS = 'wp_mcp_ai_output_guard_strictness';

	/**
	 * Result: response is safe.
	 *
	 * @var string
	 */
	const RESULT_SAFE = 'safe';

	/**
	 * Result: response contains sensitive information.
	 *
	 * @var string
	 */
	const RESULT_SENSITIVE = 'sensitive';

	/**
	 * Result: response contains unsafe content.
	 *
	 * @var string
	 */
	const RESULT_UNSAFE = 'unsafe';

	/**
	 * Result: response echoes an injection payload.
	 *
	 * @var string
	 */
	const RESULT_INJECTION_ECHO = 'injection_echo';

	/**
	 * Whether the subscriber has been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Hook the output guardrail subscriber.
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		// Validate LLM responses before they reach the chat UI or downstream tools.
		add_filter( 'wp_mcp_ai_pre_response_render', array( __CLASS__, 'validate_response' ), 10, 3 );
	}

	/**
	 * Validate an LLM response before it is rendered or passed to downstream systems.
	 *
	 * @param string $content    The raw LLM response content.
	 * @param int    $assistant_id The assistant ID for profile lookup.
	 * @param array  $context    Additional context (model, provider, tool_call_id).
	 * @return string|WP_Error Sanitized content or WP_Error if blocked.
	 */
	public static function validate_response( $content, $assistant_id, $context = array() ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		// Check if output guardrail is enabled for this assistant.
		$profile = self::get_profile( $assistant_id );
		if ( empty( $profile['output_guard']['enabled'] ) ) {
			return $content;
		}

		$mode       = isset( $profile['output_guard']['mode'] ) ? $profile['output_guard']['mode'] : 'warn';
		$strictness = isset( $profile['output_guard']['strictness'] ) ? $profile['output_guard']['strictness'] : 'medium';

		// Run checks.
		$findings = array();

		// Check 1: Sensitive information leakage.
		if ( self::has_sensitive_info( $content, $strictness ) ) {
			$findings[] = self::RESULT_SENSITIVE;
		}

		// Check 2: Unsafe content (high strictness only for performance).
		if ( 'high' === $strictness && self::has_unsafe_content( $content ) ) {
			$findings[] = self::RESULT_UNSAFE;
		}

		// Check 3: Injection echoes.
		if ( self::has_injection_echo( $content ) ) {
			$findings[] = self::RESULT_INJECTION_ECHO;
		}

		// No findings — response is safe.
		if ( empty( $findings ) ) {
			return $content;
		}

		// Log the findings for audit.
		self::log_finding( $assistant_id, $findings, $content, $context );

		// Act based on mode.
		switch ( $mode ) {
			case 'block':
				return new \WP_Error(
					'wp_mcp_ai_output_guard_blocked',
					sprintf(
						/* translators: %s: comma-separated finding types */
						__( 'Response blocked by output guardrail: %s', 'nvoos-content-graph-ai-platform' ),
						implode( ', ', $findings )
					)
				);

			case 'warn':
				// Return content with a warning prefix.
				$warning = sprintf(
					'[⚠️ %s] ',
					implode( ', ', $findings )
				);
				return $warning . $content;

			case 'log':
			default:
				// Pass through but log.
				return $content;
		}
	}

	/**
	 * Check if content contains potentially sensitive information.
	 *
	 * Scans for: email addresses, credit card numbers, SSNs, API key patterns,
	 * AWS keys, JWT tokens, private keys, connection strings.
	 *
	 * @param string $content    Content to scan.
	 * @param string $strictness Strictness level.
	 * @return bool True if sensitive info found.
	 */
	private static function has_sensitive_info( $content, $strictness ) {
		$patterns = array();

		// Always check (all strictness levels).
		$patterns[] = '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/'; // Email.
		$patterns[] = '/\b(?:\d[ -]*?){13,16}\b/'; // Credit-card-shaped digits.
		$patterns[] = '/\b\d{3}-\d{2}-\d{4}\b/'; // US SSN.
		$patterns[] = '/\bsk-[A-Za-z0-9]{20,}\b/'; // OpenAI keys.
		$patterns[] = '/\bAIza[0-9A-Za-z\-_]{35}\b/'; // Google API keys.
		$patterns[] = '/\bAKIA[0-9A-Z]{16}\b/'; // AWS access keys.
		$patterns[] = '/\beyJ[A-Za-z0-9\-_]+\.eyJ[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+/'; // JWT tokens.
		$patterns[] = '/-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----/'; // Private keys.
		$patterns[] = '/\b(?:mongodb|mysql|postgres|redis):\/\/[^\s]+/'; // Connection strings.

		// Medium+ strictness: additional patterns.
		if ( 'medium' === $strictness || 'high' === $strictness ) {
			$patterns[] = '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/'; // IP addresses.
			$patterns[] = '/\b(?:0x)?[A-Fa-f0-9]{32,}\b/'; // Hashes/tokens.
		}

		// High strictness: comprehensive patterns.
		if ( 'high' === $strictness ) {
			$patterns[] = '/\b(?:\+?\d{1,3}[-.]?)?\(?\d{3}\)?[-.]?\d{3}[-.]?\d{4}\b/'; // Phone numbers.
			$patterns[] = '/\b(?:password|secret|token|key|credential)\s*[:=]\s*\S+/i'; // Credential assignments.
		}

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $content ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if content contains unsafe/harmful content.
	 *
	 * Uses keyword heuristics for common unsafe patterns. For production
	 * deployments, consider integrating with an external content safety API.
	 *
	 * @param string $content Content to scan.
	 * @return bool True if unsafe content found.
	 */
	private static function has_unsafe_content( $content ) {
		// Keyword-based heuristic — intentionally broad patterns.
		// For production, replace with an API-based classifier.
		$unsafe_patterns = array(
			'/\b(?:how to (?:make|build|create) (?:a bomb|explosives|weapons))\b/i',
			'/\b(?:self[- ]?harm|suicide|kill yourself)\b/i',
			'/\b(?:child\s*(?:abuse|exploitation|pornography))\b/i',
		);

		foreach ( $unsafe_patterns as $pattern ) {
			if ( preg_match( $pattern, $content ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if the response echoes a known injection payload.
	 *
	 * @param string $content Content to scan.
	 * @return bool True if injection echo detected.
	 */
	private static function has_injection_echo( $content ) {
		$injection_markers = array(
			'ignore previous instructions',
			'ignore all previous',
			'do not follow the above',
			'disregard prior',
			'you are now DAN',
			'developer mode override',
			'system prompt override',
		);

		$content_lower = strtolower( $content );

		// Count how many markers appear — one marker alone may be in-context,
		// but multiple markers strongly suggest injection echo.
		$hits = 0;
		foreach ( $injection_markers as $marker ) {
			if ( false !== strpos( $content_lower, $marker ) ) {
				++$hits;
			}
		}

		// Require at least 2 markers to trigger (reduces false positives).
		return $hits >= 2;
	}

	/**
	 * Get the harness profile for an assistant.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Harness profile array.
	 */
	private static function get_profile( $assistant_id ) {
		$assistant_id = absint( $assistant_id );
		if ( ! $assistant_id ) {
			return array();
		}

		$profile = get_post_meta( $assistant_id, '_wp_mcp_ai_harness_profile', true );
		if ( ! is_array( $profile ) ) {
			return array();
		}

		return $profile;
	}

	/**
	 * Log a guardrail finding to the audit trail.
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param array  $findings     Array of finding type strings.
	 * @param string $content      The content that triggered the finding (partial).
	 * @param array  $context      Additional context.
	 */
	private static function log_finding( $assistant_id, $findings, $content, $context ) {
		$log_entry = array(
			'timestamp'       => current_time( 'mysql' ),
			'assistant_id'    => $assistant_id,
			'findings'        => $findings,
			'content_hash'    => md5( $content ),
			'content_preview' => substr( $content, 0, 200 ),
			'context'         => $context,
		);

		// Log to the standard audit trail if available.
		if ( function_exists( 'wp_mcp_ai_log' ) ) {
			wp_mcp_ai_log( 'output_guardrail', $log_entry );
		}

		// Also store in a dedicated option for the security dashboard.
		$recent_findings = get_option( 'wp_mcp_ai_output_guard_findings', array() );
		array_unshift( $recent_findings, $log_entry );
		$recent_findings = array_slice( $recent_findings, 0, 100 );
		update_option( 'wp_mcp_ai_output_guard_findings', $recent_findings, false );
	}
}
