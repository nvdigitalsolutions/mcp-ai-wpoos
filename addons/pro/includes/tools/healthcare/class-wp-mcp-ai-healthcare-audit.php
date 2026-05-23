<?php
/**
 * Healthcare Toolkit Unified PHI Audit Log
 *
 * Generalises the existing `WP_MCP_AI_Imaging_Audit_Log` so every PHI
 * read/write across the three sub-toolkits (Vitals, Health & Wellness,
 * Imaging) lands in the same append-only ledger.
 *
 * Each entry contains:
 *  - event type          (member_viewed, vital_logged, study_uploaded, …)
 *  - resource type       ('member' | 'vital_log' | 'imaging_study' | …)
 *  - resource id         (post id, study UID, etc.)
 *  - WordPress user id
 *  - ISO 8601 timestamp
 *  - SHA-256 hashed IP (PII-minimised)
 *  - free-form sanitised meta
 *
 * Storage is a rolling buffer in a WordPress option to avoid requiring a
 * dedicated database table.  Older entries are discarded once
 * `MAX_ENTRIES` is exceeded; deployments needing long-term retention should
 * subscribe to the `wp_mcp_ai_healthcare_after_phi_access` action and
 * forward entries to an external SIEM.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

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
