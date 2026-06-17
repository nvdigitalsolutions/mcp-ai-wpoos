<?php
/**
 * CRM Toolkit Unified PII / Consent Audit Log
 *
 * Generalises the audit pattern from WP_MCP_AI_Healthcare_Audit for the
 * CRM toolkit.  Every PII read, outbound send, consent event, and
 * destructive action is recorded to an append-only rolling buffer so the
 * CRM can demonstrate accountability to regulators (GDPR Art. 30 record
 * of processing activities, CAN-SPAM, TCPA consent trail).
 *
 * Each entry contains:
 *  - event type       (lead_viewed, outbound_sent, consent_revoked, …)
 *  - resource type    ('lead' | 'deal' | 'contact' | 'sequence' | …)
 *  - resource id      (post id, or string identifier)
 *  - WordPress user id
 *  - ISO 8601 timestamp
 *  - SHA-256 hashed IP (PII-minimised)
 *  - free-form sanitised meta
 *
 * Storage is a rolling buffer in a WordPress option (MAX_ENTRIES = 10 000).
 * Deployments needing long-term retention should subscribe to the
 * wp_mcp_ai_crm_after_audit action and forward entries to an external SIEM.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unified CRM audit log.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_CRM_Audit {

	/**
	 * Option key for the rolling audit buffer.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_crm_audit_log';

	/**
	 * Maximum number of audit entries retained in-memory.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 10000;

	/**
	 * Record a CRM-AUDIT event.
	 *
	 * @param string $event_type    Machine-readable event identifier (e.g. 'lead_viewed', 'outbound_sent').
	 * @param string $resource_type Resource slug (e.g. 'lead', 'deal', 'contact', 'sequence', 'consent').
	 * @param mixed  $resource_id   Resource identifier (post ID or string).
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

		// Sanitise meta values.
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
		 * Filter: fire before the audit entry is appended.
		 *
		 * Returning a non-array suppresses the entry entirely.
		 *
		 * @param array $entry Audit entry.
		 */
		$entry = apply_filters( 'wp_mcp_ai_crm_before_audit', $entry );
		if ( ! is_array( $entry ) ) {
			return;
		}

		$entries[] = $entry;

		// Trim to MAX_ENTRIES (rolling buffer).
		$count = count( $entries );
		if ( $count > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, $count - self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $entries, false );

		/**
		 * Action: fired after the audit entry has been persisted.
		 *
		 * Subscribe to forward entries to an external SIEM or long-term store.
		 *
		 * @param array $entry Audit entry.
		 */
		do_action( 'wp_mcp_ai_crm_after_audit', $entry );
	}

	/**
	 * Retrieve audit entries, most recent first.
	 *
	 * @param int    $per_page Number of entries to return.
	 * @param int    $page     Page number (1-based).
	 * @param string $event_type Optional event type filter.
	 * @return array Array of audit entries.
	 */
	public static function get_entries( $per_page = 50, $page = 1, $event_type = '' ) {
		$entries = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}

		// Most recent first.
		$entries = array_reverse( $entries );

		// Filter by event type.
		if ( ! empty( $event_type ) ) {
			$event_type = sanitize_key( $event_type );
			$entries    = array_filter(
				$entries,
				function ( $entry ) use ( $event_type ) {
					return isset( $entry['event'] ) && $entry['event'] === $event_type;
				}
			);
			$entries    = array_values( $entries );
		}

		// Paginate.
		$offset = ( max( 1, (int) $page ) - 1 ) * max( 1, (int) $per_page );
		return array_slice( $entries, $offset, max( 1, (int) $per_page ) );
	}

	/**
	 * Clear all audit entries.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Get the client's IP address, respecting proxy headers.
	 *
	 * @return string IP address or empty string.
	 */
	private static function get_client_ip() {
		$sources = array(
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);

		foreach ( $sources as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = filter_var( wp_unslash( $_SERVER[ $key ] ), FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE );
				if ( $ip ) {
					return $ip;
				}
			}
		}

		return '';
	}
}
