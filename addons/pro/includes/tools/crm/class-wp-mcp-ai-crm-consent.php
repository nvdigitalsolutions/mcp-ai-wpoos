<?php
/**
 * CRM Toolkit Consent Ledger
 *
 * Manages channel-specific consent records for every CRM contact across
 * all outbound channels (email, SMS, WhatsApp, LinkedIn DM, phone call).
 *
 * Each consent record captures:
 *  - contact_id / email / phone (identifier)
 *  - channel
 *  - legal_basis (GDPR Art. 6: consent, legitimate_interest, …)
 *  - status (active, revoked, expired, pending)
 *  - granted_at (ISO 8601)
 *  - evidence_url / evidence_id
 *  - source (web_form, chat, verbal, import)
 *  - ip_hash
 *  - user_agent_hash
 *
 * Mirrors the healthcare toolkit's PHI handling pattern: enforcement is
 * in the engine (not per-tool), and every consent event is audited.
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
 * CRM consent ledger.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_CRM_Consent {

	/**
	 * Meta key for consent records stored on contact posts.
	 *
	 * @var string
	 */
	const META_KEY = '_mcp_ai_crm_consent_records';

	/**
	 * Valid consent statuses.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'active', 'revoked', 'expired', 'pending' );

	/**
	 * Record consent for a contact on a specific channel.
	 *
	 * @param int    $contact_id    WordPress post ID (mcp_crm_contacts or mcp_ai_lead).
	 * @param string $channel       Channel slug (email, sms, whatsapp, …).
	 * @param string $legal_basis   GDPR Art. 6 legal basis slug.
	 * @param string $source        Source of consent (web_form, chat, verbal, …).
	 * @param string $evidence_url  Optional URL of consent evidence.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function record( $contact_id, $channel, $legal_basis = 'legitimate_interest', $source = 'web_form', $evidence_url = '' ) {
		$contact_id = absint( $contact_id );
		if ( ! $contact_id ) {
			return new WP_Error( 'crm_consent_invalid_contact', __( 'Invalid contact ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$channel = sanitize_key( $channel );
		if ( ! WP_MCP_AI_CRM_Codes::is_valid_channel( $channel ) ) {
			return new WP_Error( 'crm_consent_invalid_channel', __( 'Invalid channel.', 'mcp-ai-wpoos-pro' ) );
		}

		$legal_basis = sanitize_key( $legal_basis );

		// Load existing records.
		$records = self::get_records( $contact_id );

		// Build consent record.
		$ip_raw    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip_hashed = $ip_raw ? hash( 'sha256', $ip_raw ) : '';

		$ua_raw    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$ua_hashed = $ua_raw ? hash( 'sha256', $ua_raw ) : '';

		$entry = array(
			'channel'         => $channel,
			'legal_basis'     => $legal_basis,
			'status'          => 'active',
			'granted_at'      => gmdate( 'c' ),
			'evidence_url'    => sanitize_text_field( $evidence_url ),
			'source'          => sanitize_key( $source ),
			'ip_hash'         => $ip_hashed,
			'user_agent_hash' => $ua_hashed,
		);

		/**
		 * Filter the consent record before storage.
		 *
		 * @param array $entry      Consent entry.
		 * @param int   $contact_id Contact post ID.
		 */
		$entry = apply_filters( 'wp_mcp_ai_crm_consent_evidence', $entry, $contact_id );

		// Overwrite any previous record for the same channel (one active consent per channel per contact).
		$found = false;
		foreach ( $records as $i => $record ) {
			if ( $record['channel'] === $channel ) {
				$records[ $i ] = $entry;
				$found         = true;
				break;
			}
		}
		if ( ! $found ) {
			$records[] = $entry;
		}

		update_post_meta( $contact_id, self::META_KEY, $records );

		// Audit.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'consent_granted',
				'consent',
				$contact_id,
				array(
					'channel'     => $channel,
					'legal_basis' => $legal_basis,
					'source'      => $source,
				)
			);
		}

		return true;
	}

	/**
	 * Revoke consent for a contact on one or all channels.
	 *
	 * Required by FCC Apr 2025 TCPA rule (real-time revocation across channels).
	 *
	 * @param int    $contact_id Contact post ID.
	 * @param string $channel    Channel to revoke, or 'all' for every channel.
	 * @return bool|WP_Error
	 */
	public static function revoke( $contact_id, $channel = 'all' ) {
		$contact_id = absint( $contact_id );
		if ( ! $contact_id ) {
			return new WP_Error( 'crm_consent_invalid_contact', __( 'Invalid contact ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$records = self::get_records( $contact_id );
		$changed = false;

		foreach ( $records as $i => $record ) {
			if ( 'all' === $channel || $record['channel'] === $channel ) {
				$records[ $i ]['status']     = 'revoked';
				$records[ $i ]['revoked_at'] = gmdate( 'c' );
				$changed                     = true;
			}
		}

		if ( ! $changed ) {
			return true; // Nothing to revoke — still success.
		}

		update_post_meta( $contact_id, self::META_KEY, $records );

		// Also add to DNC list for the affected channel(s).
		$email = get_post_meta( $contact_id, 'email', true );
		$phone = get_post_meta( $contact_id, 'phone', true );
		if ( $email ) {
			WP_MCP_AI_CRM_Engine::add_to_dnc( $email, $channel );
		}
		if ( $phone ) {
			WP_MCP_AI_CRM_Engine::add_to_dnc( $phone, $channel );
		}

		// Audit.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'consent_revoked',
				'consent',
				$contact_id,
				array( 'channel' => $channel )
			);
		}

		return true;
	}

	/**
	 * Check whether outbound communication is permitted for a contact on a given channel.
	 *
	 * @param int    $contact_id Contact post ID.
	 * @param string $channel    Channel slug.
	 * @return bool True if permitted.
	 */
	public static function is_permitted( $contact_id, $channel ) {
		$contact_id = absint( $contact_id );
		if ( ! $contact_id ) {
			return false;
		}

		// Check DNC first.
		$email = get_post_meta( $contact_id, 'email', true );
		$phone = get_post_meta( $contact_id, 'phone', true );

		$identifier = '';
		if ( 'email' === $channel && $email ) {
			$identifier = $email;
		} elseif ( in_array( $channel, array( 'sms', 'whatsapp', 'phone_call' ), true ) && $phone ) {
			$identifier = $phone;
		}

		if ( $identifier && WP_MCP_AI_CRM_Engine::check_dnc( $identifier, $channel ) ) {
			return false;
		}
		if ( $identifier && WP_MCP_AI_CRM_Engine::check_dnc( $identifier, 'all' ) ) {
			return false;
		}

		// Check consent ledger.
		$records = self::get_records( $contact_id );
		foreach ( $records as $record ) {
			if ( $record['channel'] === $channel && 'active' === $record['status'] ) {
				return true;
			}
		}

		// For email: if no explicit consent but a double-opt-in setting is off, allow.
		if ( 'email' === $channel ) {
			$settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
			if ( empty( $settings['consent']['require_double_opt_in'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get all consent records for a contact.
	 *
	 * @param int $contact_id Contact post ID.
	 * @return array List of consent records.
	 */
	public static function get_records( $contact_id ) {
		$records = get_post_meta( absint( $contact_id ), self::META_KEY, true );
		return is_array( $records ) ? $records : array();
	}

	/**
	 * Get a consent audit trail for a contact (consent records + audit log entries).
	 *
	 * @param int $contact_id Contact post ID.
	 * @return array Keys: 'consent_records', 'audit_entries'.
	 */
	public static function get_consent_audit( $contact_id ) {
		return array(
			'consent_records' => self::get_records( $contact_id ),
			'audit_entries'   => class_exists( 'WP_MCP_AI_CRM_Audit' )
				? WP_MCP_AI_CRM_Audit::get_entries( 100, 1 )
				: array(),
		);
	}
}
