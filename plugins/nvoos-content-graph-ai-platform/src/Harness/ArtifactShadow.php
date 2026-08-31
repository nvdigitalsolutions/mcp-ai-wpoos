<?php
/**
 * Artifact Shadow — session-hash A/B serving for admitted artifact variants.
 *
 * Phase F of the artifact-evolution plan. Serves a registered candidate
 * variant to a deterministic percentage of sessions (session-hash bucketing)
 * WITHOUT promoting it: the deployed artifact is never written. Every serve
 * decision is recorded in bounded stats so outcomes can be compared through
 * the Trace Store and the Phase F drift detector before a real promotion.
 *
 * Defaults are safe:
 *   - Shadow serving is off unless `wp_mcp_ai_artifact_shadow_enabled`
 *     returns true AND a candidate has been registered explicitly.
 *   - Percentage defaults to 10; 0 disables serving entirely.
 *
 * @package WP_MCP_AI
 * @subpackage WP_MCP_AI/includes/harness
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * @reference Imbue (2026). "LLM-based Evolution as a Universal Optimizer."
 *   https://imbue.com/blog/2026-02-27-darwinian-evolver
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Artifact Shadow class.
 *
 * @since 1.9.0
 */
class ArtifactShadow {

	/**
	 * Option key prefix for registered shadow candidates.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const OPTION_CANDIDATE_PREFIX = 'wp_mcp_ai_artifact_shadow_candidate_';

	/**
	 * Option key prefix for bounded serve stats.
	 *
	 * @since 1.9.0
	 * @var   string
	 */
	const OPTION_STATS_PREFIX = 'wp_mcp_ai_artifact_shadow_stats_';

	/**
	 * Default percentage of sessions served the candidate.
	 *
	 * @since 1.9.0
	 * @var   float
	 */
	const DEFAULT_PERCENTAGE = 10.0;

	/**
	 * Maximum number of recent serve events retained per stats bucket.
	 *
	 * @since 1.9.0
	 * @var   int
	 */
	const MAX_STATS_EVENTS = 50;

	/**
	 * Register a candidate variant for shadow serving.
	 *
	 * Registration alone never serves anything — serving additionally
	 * requires `wp_mcp_ai_artifact_shadow_enabled` to return true.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $artifact_type Artifact type (prompt|skill).
	 * @param mixed  $payload       Candidate payload.
	 * @param string $hash          Population hash of the candidate.
	 * @return bool True when the candidate was stored.
	 */
	public static function register_candidate( $assistant_id, $artifact_type, $payload, $hash ) {
		$assistant_id  = absint( $assistant_id );
		$artifact_type = sanitize_key( (string) $artifact_type );

		if ( $assistant_id <= 0 || '' === $artifact_type || '' === sanitize_key( (string) $hash ) ) {
			return false;
		}

		return update_option(
			self::OPTION_CANDIDATE_PREFIX . $assistant_id . '_' . $artifact_type,
			array(
				'payload'       => $payload,
				'hash'          => sanitize_key( (string) $hash ),
				'registered_at' => time(),
			),
			false
		);
	}

	/**
	 * Read the registered shadow candidate for an assistant/type.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $artifact_type Artifact type.
	 * @return array|null Candidate envelope, or null when absent.
	 */
	public static function get_candidate( $assistant_id, $artifact_type ) {
		$candidate = get_option(
			self::OPTION_CANDIDATE_PREFIX . absint( $assistant_id ) . '_' . sanitize_key( (string) $artifact_type ),
			null
		);

		return is_array( $candidate ) ? $candidate : null;
	}

	/**
	 * Remove a registered shadow candidate.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $artifact_type Artifact type.
	 * @return bool True when the candidate was removed.
	 */
	public static function unregister( $assistant_id, $artifact_type ) {
		return delete_option(
			self::OPTION_CANDIDATE_PREFIX . absint( $assistant_id ) . '_' . sanitize_key( (string) $artifact_type )
		);
	}

	/**
	 * Whether shadow serving is enabled for an assistant/type.
	 *
	 * Off by default; requires the site filter AND a registered candidate.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $artifact_type Artifact type.
	 * @return bool
	 */
	public static function is_enabled( $assistant_id, $artifact_type ) {
		$assistant_id  = absint( $assistant_id );
		$artifact_type = sanitize_key( (string) $artifact_type );

		/**
		 * Filters whether shadow serving is enabled for an assistant/type.
		 *
		 * Default false — shadow mode is opt-in.
		 *
		 * @since 1.9.0
		 *
		 * @param bool   $enabled       Whether shadow serving is enabled.
		 * @param int    $assistant_id  Assistant post ID.
		 * @param string $artifact_type Artifact type.
		 */
		$enabled = (bool) apply_filters( 'wp_mcp_ai_artifact_shadow_enabled', false, $assistant_id, $artifact_type );

		return $enabled && null !== self::get_candidate( $assistant_id, $artifact_type );
	}

	/**
	 * Percentage of sessions served the shadow candidate.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $artifact_type Artifact type.
	 * @return float Percentage 0–100 (0 disables serving).
	 */
	public static function percentage( $assistant_id, $artifact_type ) {
		/**
		 * Filters the percentage of sessions served the shadow candidate.
		 *
		 * Default 10; 0 disables serving.
		 *
		 * @since 1.9.0
		 *
		 * @param float  $percentage    Shadow percentage (0–100).
		 * @param int    $assistant_id  Assistant post ID.
		 * @param string $artifact_type Artifact type.
		 */
		$percentage = (float) apply_filters( 'wp_mcp_ai_artifact_shadow_percentage', self::DEFAULT_PERCENTAGE, $assistant_id, $artifact_type );

		return max( 0.0, min( 100.0, $percentage ) );
	}

	/**
	 * Decide whether the current session is bucketed into the candidate arm.
	 *
	 * Deterministic per session key: the same key always lands in the same
	 * bucket, so a session sees a consistent variant across requests.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id   Assistant post ID.
	 * @param string $artifact_type  Artifact type.
	 * @param string $candidate_hash Population hash of the candidate.
	 * @param array  $context        Resolution context (e.g. surface).
	 * @return bool True when the candidate should be served.
	 */
	public static function should_serve_candidate( $assistant_id, $artifact_type, $candidate_hash, array $context = array() ) {
		$percentage = self::percentage( $assistant_id, $artifact_type );
		if ( $percentage <= 0.0 ) {
			return false;
		}
		if ( $percentage >= 100.0 ) {
			return true;
		}

		$session_key = self::session_key( $assistant_id, $artifact_type, $context );
		$bucket_raw  = md5( $assistant_id . '|' . $artifact_type . '|' . $candidate_hash . '|' . $session_key );
		$bucket      = hexdec( substr( $bucket_raw, 0, 8 ) ) % 10000; // 0..9999.

		return (float) $bucket < ( $percentage * 100.0 );
	}

	/**
	 * Record a serve decision in bounded stats.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id   Assistant post ID.
	 * @param string $artifact_type  Artifact type.
	 * @param string $candidate_hash Population hash of the candidate.
	 * @param bool   $served         Whether the candidate arm was served.
	 * @return void
	 */
	public static function record_serve( $assistant_id, $artifact_type, $candidate_hash, $served ) {
		$assistant_id  = absint( $assistant_id );
		$artifact_type = sanitize_key( (string) $artifact_type );
		$key           = self::OPTION_STATS_PREFIX . $assistant_id . '_' . $artifact_type;

		$stats = get_option( $key, array() );
		if ( ! is_array( $stats ) ) {
			$stats = array();
		}

		$stats['served_candidate'] = (int) ( isset( $stats['served_candidate'] ) ? $stats['served_candidate'] : 0 ) + ( $served ? 1 : 0 );
		$stats['served_incumbent'] = (int) ( isset( $stats['served_incumbent'] ) ? $stats['served_incumbent'] : 0 ) + ( $served ? 0 : 1 );

		if ( ! isset( $stats['events'] ) || ! is_array( $stats['events'] ) ) {
			$stats['events'] = array();
		}
		array_unshift(
			$stats['events'],
			array(
				'hash'      => sanitize_key( (string) $candidate_hash ),
				'served'    => (bool) $served,
				'timestamp' => time(),
			)
		);
		$stats['events'] = array_slice( $stats['events'], 0, self::MAX_STATS_EVENTS );

		update_option( $key, $stats, false );
	}

	/**
	 * Read the shadow stats bucket for an assistant/type.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $artifact_type Artifact type.
	 * @return array Stats: served_candidate, served_incumbent, events.
	 */
	public static function get_stats( $assistant_id, $artifact_type ) {
		$stats = get_option(
			self::OPTION_STATS_PREFIX . absint( $assistant_id ) . '_' . sanitize_key( (string) $artifact_type ),
			array()
		);

		return is_array( $stats ) ? $stats : array();
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve the session key used for bucketing.
	 *
	 * Defaults to the current user ID plus the WP session token, so logged-in
	 * sessions bucket consistently. Anonymous sessions share one key unless a
	 * site supplies a real key via the filter.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $artifact_type Artifact type.
	 * @param array  $context      Resolution context.
	 * @return string
	 */
	private static function session_key( $assistant_id, $artifact_type, array $context ) {
		$default = (string) get_current_user_id() . ':' . ( function_exists( 'wp_get_session_token' ) ? (string) wp_get_session_token() : '' );

		/**
		 * Filters the session key used for shadow bucketing.
		 *
		 * @since 1.9.0
		 *
		 * @param string $key           Session key (default: user ID + session token).
		 * @param int    $assistant_id  Assistant post ID.
		 * @param string $artifact_type Artifact type.
		 * @param array  $context       Resolution context.
		 */
		return (string) apply_filters( 'wp_mcp_ai_artifact_shadow_session_key', $default, $assistant_id, $artifact_type, $context );
	}
}
