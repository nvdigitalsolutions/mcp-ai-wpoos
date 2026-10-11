<?php
/**
 * Preference-pair store — Model Foundry (Pro, Phase 1).
 *
 * Bounded, option-based store of DPO-shaped preference pairs
 * (prompt / chosen / rejected) per assistant, following the Base eval
 * run-store pattern (JSON option, FIFO cap). Pairs are recorded by other
 * systems — chat-UI thumbs (Phase 4+), eval-based rejection sampling, or
 * any plugin through the public `record()` API — and consumed by the
 * `export_preference_pairs` tool.
 *
 * Shapes are validated at the door (R4): exactly three non-empty string
 * fields, no lists. Oversize entries are rejected with a WP_Error, never
 * truncated — silent truncation would poison a DPO corpus.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-assistant preference-pair store.
 */
class WP_MCP_AI_Preference_Pair_Store {

	/**
	 * Option-name prefix. Assistant ID is appended after absint.
	 */
	const OPTION_PREFIX = 'wp_mcp_ai_mf_preference_pairs__';

	/**
	 * Maximum pairs retained per assistant (FIFO).
	 */
	const MAX_PAIRS = 1000;

	/**
	 * Maximum prompt length in characters.
	 */
	const MAX_PROMPT_CHARS = 8000;

	/**
	 * Maximum chosen/rejected length in characters.
	 */
	const MAX_RESPONSE_CHARS = 16000;

	/**
	 * Record a preference pair.
	 *
	 * @param int        $assistant_id Assistant post ID.
	 * @param string     $prompt       User prompt.
	 * @param string     $chosen       Chosen (preferred) response.
	 * @param string     $rejected     Rejected (dispreferred) response.
	 * @param string     $source       Free-form source tag (e.g. 'chat_thumbs').
	 * @param float|null $margin       Optional judge margin (0–1).
	 * @return array<string,mixed>|WP_Error Recorded pair or error.
	 */
	public static function record( $assistant_id, $prompt, $chosen, $rejected, $source = '', $margin = null ) {
		$assistant_id = (int) $assistant_id;
		if ( $assistant_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_invalid_assistant', __( 'A valid assistant_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$prompt   = trim( (string) $prompt );
		$chosen   = trim( (string) $chosen );
		$rejected = trim( (string) $rejected );
		if ( '' === $prompt || '' === $chosen || '' === $rejected ) {
			return new WP_Error( 'wp_mcp_ai_preference_invalid_shape', __( 'Preference pairs require non-empty prompt, chosen, and rejected strings (R4).', 'mcp-ai-wpoos-pro' ) );
		}
		if ( strlen( $prompt ) > self::MAX_PROMPT_CHARS ) {
			return new WP_Error( 'wp_mcp_ai_preference_prompt_too_large', __( 'Preference prompt exceeds the maximum length.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( strlen( $chosen ) > self::MAX_RESPONSE_CHARS || strlen( $rejected ) > self::MAX_RESPONSE_CHARS ) {
			return new WP_Error( 'wp_mcp_ai_preference_response_too_large', __( 'Preference response exceeds the maximum length.', 'mcp-ai-wpoos-pro' ) );
		}

		$pair = array(
			'prompt'     => $prompt,
			'chosen'     => $chosen,
			'rejected'   => $rejected,
			'source'     => sanitize_key( (string) $source ),
			'margin'     => null === $margin ? null : max( 0.0, min( 1.0, (float) $margin ) ),
			'created_at' => time(),
		);

		$pairs   = self::get_all( $assistant_id );
		$pairs[] = $pair;
		if ( count( $pairs ) > self::MAX_PAIRS ) {
			$pairs = array_slice( $pairs, -1 * self::MAX_PAIRS );
		}

		update_option( self::option_name( $assistant_id ), wp_json_encode( $pairs ), false );
		return $pair;
	}

	/**
	 * Fetch all stored pairs for an assistant (oldest first).
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_all( $assistant_id ) {
		$raw = get_option( self::option_name( (int) $assistant_id ), '' );
		if ( empty( $raw ) || ! is_string( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$out = array();
		foreach ( $decoded as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Count stored pairs for an assistant.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return int
	 */
	public static function count( $assistant_id ) {
		return count( self::get_all( (int) $assistant_id ) );
	}

	/**
	 * Remove all stored pairs for an assistant.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return void
	 */
	public static function clear( $assistant_id ) {
		delete_option( self::option_name( (int) $assistant_id ) );
	}

	/**
	 * Build the option name for an assistant.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return string
	 */
	public static function option_name( $assistant_id ) {
		return self::OPTION_PREFIX . absint( $assistant_id );
	}
}
