<?php
/**
 * Healthcare Imaging Audit Log
 *
 * Provides HIPAA-aligned audit logging for every access or modification
 * event against a DICOM study or series.  Audit records are stored in a
 * dedicated WordPress option (rolling circular buffer) so that they
 * persist without requiring an additional database table.
 *
 * Each log entry contains:
 *  - event type (study_viewed, file_accessed, study_uploaded, …)
 *  - study / instance UID
 *  - WordPress user ID
 *  - ISO 8601 timestamp
 *  - IP address (stored hashed for privacy)
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HIPAA-aligned audit logging for medical imaging events.
 */
class WP_MCP_AI_Imaging_Audit_Log {

	/**
	 * WordPress option key that stores the rolling audit buffer.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_imaging_audit_log';

	/**
	 * Maximum number of audit entries to retain.
	 *
	 * Oldest entries are discarded first when this limit is exceeded.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 10000;

	/**
	 * Record a new audit event.
	 *
	 * Accepted event types:
	 *  - study_uploaded
	 *  - study_viewed
	 *  - study_manifest_viewed
	 *  - instance_file_accessed
	 *  - study_deleted
	 *  - audit_log_viewed
	 *
	 * @param string $event_type  Machine-readable event identifier.
	 * @param array  $meta        Additional context (study_id, series_uid, etc.).
	 */
	public static function log( $event_type, array $meta = array() ) {
		$entries   = get_option( self::OPTION_KEY, array() );
		$user_id   = get_current_user_id();
		$ip_raw    = self::get_client_ip();
		$ip_hashed = $ip_raw ? hash( 'sha256', $ip_raw ) : '';

		// Sanitize meta keys to prevent PII leakage in log values.
		$safe_meta = array();
		foreach ( $meta as $k => $v ) {
			$k               = sanitize_key( $k );
			$safe_meta[ $k ] = is_scalar( $v ) ? sanitize_text_field( (string) $v ) : wp_json_encode( $v );
		}

		$entries[] = array(
			'event'     => sanitize_key( $event_type ),
			'user_id'   => absint( $user_id ),
			'timestamp' => gmdate( 'c' ),
			'ip_hash'   => $ip_hashed,
			'meta'      => $safe_meta,
		);

		// Trim to MAX_ENTRIES (oldest first).
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $entries, false );
	}

	/**
	 * Retrieve recent audit entries.
	 *
	 * @param int    $limit     Number of entries to return (default 100).
	 * @param string $study_id  Optional: filter to a specific study UID.
	 * @return array Most-recent entries first.
	 */
	public static function get_recent( $limit = 100, $study_id = '' ) {
		$entries = get_option( self::OPTION_KEY, array() );

		if ( '' !== $study_id ) {
			$study_id = sanitize_text_field( $study_id );
			$entries  = array_filter(
				$entries,
				static function ( $entry ) use ( $study_id ) {
					return isset( $entry['meta']['study_id'] ) && $study_id === $entry['meta']['study_id'];
				}
			);
		}

		// Most-recent first.
		$entries = array_reverse( array_values( $entries ) );

		return array_slice( $entries, 0, absint( $limit ) );
	}

	/**
	 * Retrieve the raw client IP, checking common proxy headers first.
	 *
	 * @return string IP address string, or empty string if not determinable.
	 */
	private static function get_client_ip() {
		$headers = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$raw = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// X-Forwarded-For may be a comma-separated list; take the first.
				$parts = explode( ',', $raw );
				$ip    = trim( $parts[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '';
	}
}
