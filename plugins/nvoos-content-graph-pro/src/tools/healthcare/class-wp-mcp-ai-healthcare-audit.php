<?php
/**
 * includes/tools/healthcare/class-wp-mcp-ai-healthcare-audit.php (ecosystem port — Wave F4, healthcare toolkit data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/class-wp-mcp-ai-healthcare-audit.php` for the standalone `nvoos-content-graph-pro`
 * addon. Kept byte-identical. The base Pro addon owns the class in monolith installs — the
 * addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps where the source references the Pro addon path
 * (the `defined( 'WP_MCP_AI_PRO_VERSION' )` base-mode gates stay byte-identical).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unified PHI audit log.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Healthcare_Audit {

	/**
	 * Option key for the rolling audit buffer.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_healthcare_audit_log';

	/**
	 * Maximum number of audit entries retained in-memory.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 10000;

	/**
	 * Record a PHI access event.
	 *
	 * @param string $event_type    Machine-readable event identifier.
	 * @param string $resource_type Resource slug (e.g. 'member', 'imaging_study').
	 * @param mixed  $resource_id   Resource id (post id or string identifier).
	 * @param array  $meta          Additional context (sanitised before storage).
	 * @return void
	 */
	public static function record( $event_type, $resource_type, $resource_id = '', array $meta = array() ) {
		$entries = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}

		$user_id   = get_current_user_id();
		$ip_raw    = self::get_client_ip();
		$ip_hashed = $ip_raw ? hash( 'sha256', $ip_raw ) : '';

		$safe_meta = array();
		foreach ( $meta as $k => $v ) {
			$key               = sanitize_key( (string) $k );
			$safe_meta[ $key ] = is_scalar( $v ) ? sanitize_text_field( (string) $v ) : wp_json_encode( $v );
		}

		$entry = array(
			'event'         => sanitize_key( (string) $event_type ),
			'resource_type' => sanitize_key( (string) $resource_type ),
			'resource_id'   => is_scalar( $resource_id ) ? sanitize_text_field( (string) $resource_id ) : '',
			'user_id'       => absint( $user_id ),
			'timestamp'     => gmdate( 'c' ),
			'ip_hash'       => $ip_hashed,
			'meta'          => $safe_meta,
		);

		/**
		 * Fires before the audit entry is appended.  Returning a non-array
		 * suppresses the entry.
		 *
		 * @param array $entry  Audit entry.
		 */
		$entry = apply_filters( 'wp_mcp_ai_healthcare_before_phi_access', $entry );
		if ( ! is_array( $entry ) ) {
			return;
		}

		$entries[] = $entry;

		// Trim to MAX_ENTRIES.
		$count = count( $entries );
		if ( $count > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, $count - self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $entries, false );

		/**
		 * Fires after the audit entry has been persisted.
		 *
		 * Subscribe to forward entries to an external SIEM.
		 *
		 * @param array $entry Audit entry.
		 */
		do_action( 'wp_mcp_ai_healthcare_after_phi_access', $entry );
	}

	/**
	 * Backward-compat alias for `record()` that mirrors the old imaging
	 * audit-log signature `log( $event_type, $meta )`.
	 *
	 * @param string $event_type Event identifier.
	 * @param array  $meta       Meta payload.
	 * @return void
	 */
	public static function log( $event_type, array $meta = array() ) {
		$resource_type = isset( $meta['resource_type'] ) ? (string) $meta['resource_type'] : 'imaging_study';
		$resource_id   = isset( $meta['resource_id'] ) ? $meta['resource_id'] : ( $meta['study_id'] ?? '' );
		unset( $meta['resource_type'], $meta['resource_id'] );
		self::record( $event_type, $resource_type, $resource_id, $meta );
	}

	/**
	 * Read recent audit entries.
	 *
	 * @param int $limit Number of entries (default 100, max 10000).
	 * @return array
	 */
	public static function recent( $limit = 100 ) {
		$entries = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}
		$limit = max( 1, min( self::MAX_ENTRIES, (int) $limit ) );
		if ( count( $entries ) <= $limit ) {
			return array_values( $entries );
		}
		return array_slice( $entries, -$limit );
	}

	/**
	 * Truncate the audit buffer.  Intended for tests and admin actions only.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Best-effort client IP retrieval.
	 *
	 * @return string
	 */
	protected static function get_client_ip() {
		$candidates = array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $candidates as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$value = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
				if ( false !== strpos( $value, ',' ) ) {
					$parts = explode( ',', $value );
					$value = trim( $parts[0] );
				}
				return $value;
			}
		}
		return '';
	}
}
