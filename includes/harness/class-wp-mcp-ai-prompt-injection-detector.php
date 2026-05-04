<?php
/**
 * Prompt Injection Detector — Layer I safety primitive.
 *
 * Provides heuristic and optional provider-backed detection of prompt
 * injection, jailbreak attempts, system-prompt extraction probes, and
 * role-hijacking patterns in user-supplied input before it is sent to
 * an LLM. This is an opt-in harness layer: off by default, enabled via
 * the per-assistant harness profile key `injection_detector.enabled`.
 *
 * ## Detection Tiers
 *
 * 1. **Heuristic** (free, synchronous) — regex patterns covering the most
 *    common attack families. Runs first, short-circuits if a match is found.
 *
 * 2. **OpenAI Moderation API** (optional, async HTTP) — flag `hate`,
 *    `violence`, `sexual`, `self-harm` categories and report them via the
 *    action hook. Requires an OpenAI API key to be configured.
 *
 * Both tiers fire the `wp_mcp_ai_prompt_injection_detected` action so
 * third-party plugins and Pro addons can extend handling (e.g. logging,
 * rate limiting, or hard blocking).
 *
 * ## Integration
 *
 * Add this subscriber in the chat path (or hook it on
 * `wp_mcp_ai_before_chat_request`):
 *
 *   $result = WP_MCP_AI_Prompt_Injection_Detector::analyze( $user_message, $assistant_id );
 *   if ( $result['flagged'] && $result['block'] ) {
 *       return new WP_Error( 'injection_blocked', ... );
 *   }
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prompt injection and jailbreak detector (Layer I).
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Prompt_Injection_Detector {

	/**
	 * Harness profile key that enables this layer.
	 */
	const PROFILE_KEY = 'injection_detector';

	/**
	 * WordPress option key for the global block-on-detection setting.
	 */
	const OPTION_BLOCK_ON_DETECT = 'wp_mcp_ai_injection_block_on_detect';

	/**
	 * Maximum text length to analyze (characters). Longer text is truncated
	 * for the heuristic scan to avoid regex catastrophic backtracking.
	 */
	const MAX_SCAN_LENGTH = 8000;

	/**
	 * Known heuristic patterns grouped by attack family.
	 *
	 * Each entry: array( pattern, severity, family ).
	 *
	 * Severity: 'low' | 'medium' | 'high' | 'critical'.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string}>
	 */
	private static function heuristic_patterns() {
		return array(
			// ── Role/persona hijacking ────────────────────────────────────────
			array( '/ignore\s+(all\s+)?(?:previous|prior|above)\s+instructions?/i', 'critical', 'role_hijack' ),
			array( '/disregard\s+(?:your\s+)?(?:previous|prior|above)\s+instructions?/i', 'critical', 'role_hijack' ),
			array( '/you\s+are\s+now\s+(?:a|an|the)\s+(?:new|different|evil|unrestricted|jailbroken)/i', 'critical', 'role_hijack' ),
			array( '/act\s+as\s+(?:a\s+)?(?:DAN|DEVELOPER\s+MODE|jailbroken|unrestricted|evil|malicious)/i', 'high', 'role_hijack' ),
			array( '/pretend\s+(?:you\s+have\s+no\s+restrictions|you\'?re\s+unrestricted)/i', 'high', 'role_hijack' ),
			array( '/(?:from\s+now\s+on|starting\s+now)[,\s]+(?:you\s+(?:will|must|should)|always)/i', 'medium', 'role_hijack' ),

			// ── System-prompt extraction ──────────────────────────────────────
			array( '/(?:reveal|show|print|display|output|repeat|tell me|what is)\s+(?:your\s+)?(?:system\s+prompt|initial\s+instructions?|original\s+instructions?|prompt)/i', 'high', 'prompt_extraction' ),
			array( '/(?:what\s+(?:are\s+your|is\s+your)|repeat\s+(?:your|the))\s+(?:system\s+message|setup\s+instructions?)/i', 'high', 'prompt_extraction' ),
			array( '/translate\s+(?:your|the)\s+(?:above|system)\s+(?:to|into)/i', 'medium', 'prompt_extraction' ),

			// ── Injection via delimiter escaping ─────────────────────────────
			array( '/\]\s*\]\s*(?:SYSTEM|USER|ASSISTANT|INST|SYS)\s*[:\[]/i', 'high', 'delimiter_escape' ),
			array( '/\<\/(?:s|system|instruction)\s*\>/i', 'high', 'delimiter_escape' ),
			array( '/<\|(?:endofprompt|end_of_turn|im_start|im_end)\|>/i', 'high', 'delimiter_escape' ),
			array( '/###\s+(?:SYSTEM|NEW TASK|OVERRIDE):/i', 'medium', 'delimiter_escape' ),

			// ── Direct jailbreak tokens / classic prompts ────────────────────
			array( '/DAN\s+(?:mode|jailbreak|prompt)/i', 'critical', 'jailbreak' ),
			array( '/developer\s+mode\s+(?:enabled|activated|unlocked)/i', 'critical', 'jailbreak' ),
			array( '/grandma\s+(?:jailbreak|exploit|loophole)/i', 'medium', 'jailbreak' ),
			array( '/\bDo\s+Anything\s+Now\b/i', 'critical', 'jailbreak' ),

			// ── Indirect / encoded injection ─────────────────────────────────
			array( '/(?:base64|rot13|hex|ascii)\s+(?:encode|decode)\s+(?:this|the\s+following)/i', 'medium', 'encoded_injection' ),
			array( '/(?:decode|decipher)\s+(?:my\s+)?(?:instructions?|commands?)\s+(?:from|in|using)/i', 'medium', 'encoded_injection' ),

			// ── Data exfiltration / SSRF probes ──────────────────────────────
			array( '/(?:send|post|exfiltrate|transmit)\s+(?:data|information|secrets?|api\s+keys?)\s+to\s+(?:https?:\/\/|my\s+server)/i', 'critical', 'exfiltration' ),
			array( '/(?:curl|wget|fetch|http_request)\s+(?:https?:\/\/)/i', 'medium', 'ssrf_probe' ),
		);
	}

	/**
	 * Analyze user input for prompt injection / jailbreak signals.
	 *
	 * Returns a result array with:
	 *  - flagged     (bool)   Whether any signal was detected.
	 *  - block       (bool)   Whether the calling code should abort the request.
	 *  - tier        (string) 'heuristic' | 'moderation' | 'none'.
	 *  - severity    (string) 'none' | 'low' | 'medium' | 'high' | 'critical'.
	 *  - family      (string) Attack family (empty when not flagged).
	 *  - matches     (array)  List of matched pattern labels.
	 *  - truncated   (bool)   Whether the input was truncated before scanning.
	 *
	 * @param string $text         User-supplied text to analyze.
	 * @param int    $assistant_id Assistant post ID (0 = global context).
	 * @param array  $context      Optional extra context (e.g. 'request').
	 * @return array Analysis result.
	 */
	public static function analyze( $text, $assistant_id = 0, array $context = array() ) {
		$text         = (string) $text;
		$assistant_id = (int) $assistant_id;

		$result = array(
			'flagged'   => false,
			'block'     => false,
			'tier'      => 'none',
			'severity'  => 'none',
			'family'    => '',
			'matches'   => array(),
			'truncated' => false,
		);

		if ( '' === trim( $text ) ) {
			return $result;
		}

		/**
		 * Filter whether the injection detector should run for this request.
		 * Returning false disables detection for this call only.
		 *
		 * @param bool   $enabled      Enabled state (default true if feature flag is on).
		 * @param int    $assistant_id Assistant post ID.
		 * @param array  $context      Extra context.
		 */
		$enabled = apply_filters( 'wp_mcp_ai_harness_input_check', true, $assistant_id, $context );
		if ( ! $enabled ) {
			return $result;
		}

		// Truncate to MAX_SCAN_LENGTH to prevent catastrophic backtracking.
		if ( strlen( $text ) > self::MAX_SCAN_LENGTH ) {
			$text              = substr( $text, 0, self::MAX_SCAN_LENGTH );
			$result['truncated'] = true;
		}

		// ── Tier 1: Heuristic scan ────────────────────────────────────────────
		$heuristic = self::run_heuristic_scan( $text );
		if ( $heuristic['flagged'] ) {
			$result = array_merge( $result, $heuristic );
			$result['tier'] = 'heuristic';
			$result['block'] = self::should_block( $result['severity'], $assistant_id );
			self::fire_detected_action( $text, $result, $assistant_id, $context );
			return $result;
		}

		// ── Tier 2: Optional provider moderation ─────────────────────────────
		$moderation = self::run_moderation_check( $text, $assistant_id );
		if ( $moderation['flagged'] ) {
			$result = array_merge( $result, $moderation );
			$result['tier'] = 'moderation';
			$result['block'] = self::should_block( $result['severity'], $assistant_id );
			self::fire_detected_action( $text, $result, $assistant_id, $context );
			return $result;
		}

		return $result;
	}

	/**
	 * Run the heuristic regex scan.
	 *
	 * @param string $text Already-truncated input.
	 * @return array Partial result array (flagged, severity, family, matches).
	 */
	private static function run_heuristic_scan( $text ) {
		$out = array(
			'flagged'  => false,
			'severity' => 'none',
			'family'   => '',
			'matches'  => array(),
		);

		$severity_rank = array( 'none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4 );

		/**
		 * Filter the heuristic patterns before scanning.
		 * Each pattern is array( regex_string, severity, family ).
		 *
		 * @param array $patterns Default patterns.
		 */
		$patterns = apply_filters( 'wp_mcp_ai_injection_heuristic_patterns', self::heuristic_patterns() );

		foreach ( $patterns as $entry ) {
			if ( ! is_array( $entry ) || count( $entry ) < 3 ) {
				continue;
			}
			list( $regex, $severity, $family ) = $entry;

			if ( ! is_string( $regex ) || '' === $regex ) {
				continue;
			}

			if ( @preg_match( $regex, $text ) ) {
				$out['flagged']  = true;
				$out['matches'][] = $family . ':' . $severity;

				// Keep the highest severity seen.
				if ( ( $severity_rank[ $severity ] ?? 0 ) > ( $severity_rank[ $out['severity'] ] ?? 0 ) ) {
					$out['severity'] = $severity;
					$out['family']   = $family;
				}
			}
		}

		return $out;
	}

	/**
	 * Optional OpenAI Moderation API check.
	 *
	 * Fires only when:
	 * - An OpenAI API key is configured.
	 * - The assistant's harness profile has `injection_detector.use_moderation_api` set to true.
	 * - The `wp_mcp_ai_injection_use_moderation_api` filter does not return false.
	 *
	 * @param string $text         Input text.
	 * @param int    $assistant_id Assistant post ID.
	 * @return array Partial result array (flagged, severity, family, matches).
	 */
	private static function run_moderation_check( $text, $assistant_id ) {
		$out = array(
			'flagged'  => false,
			'severity' => 'none',
			'family'   => '',
			'matches'  => array(),
		);

		/**
		 * Filter whether to run the OpenAI Moderation API check.
		 *
		 * @param bool $run          Default false (opt-in).
		 * @param int  $assistant_id Assistant post ID.
		 */
		$run = apply_filters( 'wp_mcp_ai_injection_use_moderation_api', false, $assistant_id );
		if ( ! $run ) {
			return $out;
		}

		// Retrieve the OpenAI API key.
		$api_key = get_option( 'wp_mcp_ai_openai_api_key', '' );
		if ( empty( $api_key ) || ! is_string( $api_key ) ) {
			return $out;
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/moderations',
			array(
				'timeout' => 5,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode( array( 'input' => $text ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $out;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['results'][0] ) ) {
			return $out;
		}

		$result = $body['results'][0];
		if ( ! empty( $result['flagged'] ) ) {
			$out['flagged'] = true;
			$out['severity'] = 'high';
			$out['family']   = 'moderation_api';

			foreach ( (array) ( $result['categories'] ?? array() ) as $category => $flagged ) {
				if ( $flagged ) {
					$out['matches'][] = 'moderation:' . sanitize_key( $category );
				}
			}
		}

		return $out;
	}

	/**
	 * Determine whether a flagged detection should block the request.
	 *
	 * Default policy: block on 'high' or 'critical'. The policy can be
	 * overridden via the site option `wp_mcp_ai_injection_block_on_detect`
	 * or the `wp_mcp_ai_injection_block_severity_threshold` filter.
	 *
	 * @param string $severity   Detected severity.
	 * @param int    $assistant_id Assistant post ID.
	 * @return bool Whether to block.
	 */
	private static function should_block( $severity, $assistant_id ) {
		/**
		 * Filter the minimum severity level that triggers a hard block.
		 * Values: 'low' | 'medium' | 'high' | 'critical' | 'never'.
		 *
		 * @param string $threshold    Minimum severity to block. Default 'high'.
		 * @param int    $assistant_id Assistant post ID.
		 */
		$threshold = apply_filters( 'wp_mcp_ai_injection_block_severity_threshold', 'high', $assistant_id );

		if ( 'never' === $threshold ) {
			return false;
		}

		$rank = array( 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4 );

		return ( $rank[ $severity ] ?? 0 ) >= ( $rank[ $threshold ] ?? 3 );
	}

	/**
	 * Fire the detected action.
	 *
	 * @param string $text         Original (possibly truncated) input.
	 * @param array  $result       Full analysis result.
	 * @param int    $assistant_id Assistant post ID.
	 * @param array  $context      Extra context.
	 */
	private static function fire_detected_action( $text, array $result, $assistant_id, array $context ) {
		/**
		 * Fires when a prompt injection / jailbreak signal is detected.
		 *
		 * @param array  $result       Full analysis result array.
		 * @param string $text         The (possibly truncated) scanned text.
		 * @param int    $assistant_id Assistant post ID.
		 * @param array  $context      Extra context passed by the caller.
		 */
		do_action( 'wp_mcp_ai_prompt_injection_detected', $result, $text, $assistant_id, $context );
	}

	/**
	 * Register the `wp_mcp_ai_before_chat_request` subscriber.
	 *
	 * When called from harness-init.php this wires detection to every chat
	 * request automatically. Individual assistants may still gate the layer
	 * via their harness profile.
	 */
	public static function register() {
		add_action( 'wp_mcp_ai_before_chat_request', array( __CLASS__, 'on_before_chat_request' ), 5, 4 );
	}

	/**
	 * Hook handler: run detection on incoming user messages.
	 *
	 * @param int             $assistant_id Assistant post ID.
	 * @param array           $messages     Chat messages.
	 * @param array           $options      Prepared options.
	 * @param WP_REST_Request $request      REST request.
	 */
	public static function on_before_chat_request( $assistant_id, $messages, $options, $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Harness_Profile' ) ) {
			return;
		}

		$profile = WP_MCP_AI_Harness_Profile::get( (int) $assistant_id );
		if ( empty( $profile['injection_detector']['enabled'] ) ) {
			return;
		}

		// Scan the latest user message only (system prompt is controlled content).
		$user_text = '';
		if ( is_array( $messages ) ) {
			foreach ( array_reverse( $messages ) as $msg ) {
				if ( isset( $msg['role'] ) && 'user' === $msg['role'] ) {
					$user_text = is_string( $msg['content'] ) ? $msg['content'] : wp_json_encode( $msg['content'] );
					break;
				}
			}
		}

		if ( '' === $user_text ) {
			return;
		}

		self::analyze( $user_text, (int) $assistant_id, array( 'request' => $request ) );
		// Note: blocking is handled by consumers of the wp_mcp_ai_prompt_injection_detected action.
		// The action hook approach keeps this class free of REST response side-effects.
	}
}
