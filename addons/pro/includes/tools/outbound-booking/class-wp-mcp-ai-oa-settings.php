<?php
/**
 * Outbound Appointment Booking — settings accessor.
 *
 * Central option store for the Outbound Booking toolkit. All channel modes,
 * sending windows, caps, webhooks, and booking configuration live here so
 * the engine, dashboard, and tools share one sanitised source of truth.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Outbound_Booking_Toolkit
 * @since 2.12.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings accessor for the Outbound Booking toolkit.
 *
 * @since 2.12.0
 */
class WP_MCP_AI_OA_Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'wp_mcp_ai_outbound_settings';

	/**
	 * Channel modes available for email.
	 *
	 * @var string[]
	 */
	const EMAIL_MODES = array( 'auto', 'approval', 'off' );

	/**
	 * Channel modes available for DM channels (no native send APIs).
	 *
	 * @var string[]
	 */
	const DM_MODES = array( 'approval', 'webhook', 'off' );

	/**
	 * Default settings.
	 *
	 * @since 2.12.0
	 * @return array
	 */
	public static function defaults() {
		return array(
			'from_name'           => get_bloginfo( 'name' ),
			'from_email'          => get_bloginfo( 'admin_email' ),
			'sender_signature'    => '',
			'email_mode'          => 'approval',
			'linkedin_mode'       => 'approval',
			'instagram_mode'      => 'approval',
			'webhook_url'         => '',
			'slack_webhook_url'   => '',
			'slack_channel'       => '',
			'digest_enabled'      => '1',
			'digest_email'        => '',
			'daily_cap'           => 100,
			'send_window_start'   => 8,
			'send_window_end'     => 18,
			'reply_token'         => '',
			'champion_allocation' => 80,
			'min_test_sends'      => 10,
			'booking_page_id'     => 0,
			'default_booking_link'=> 0,
		);
	}

	/**
	 * Get the full settings array merged over defaults.
	 *
	 * @since 2.12.0
	 * @return array
	 */
	public static function get() {
		$s = get_option( self::OPTION, array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		return wp_parse_args( $s, self::defaults() );
	}

	/**
	 * Get a single setting.
	 *
	 * @since 2.12.0
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get_setting( $key, $default = '' ) {
		$all = self::get();
		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	/**
	 * Sanitise and persist a new settings array.
	 *
	 * @since 2.12.0
	 * @param array $new Raw settings from a form.
	 * @return array Sanitised merged settings.
	 */
	public static function update( $new ) {
		if ( ! is_array( $new ) ) {
			$new = array();
		}
		$old    = self::get();
		$merged = $old;

		if ( isset( $new['from_name'] ) ) {
			$merged['from_name'] = sanitize_text_field( $new['from_name'] );
		}
		if ( isset( $new['from_email'] ) ) {
			$merged['from_email'] = sanitize_email( $new['from_email'] );
		}
		if ( isset( $new['sender_signature'] ) ) {
			$merged['sender_signature'] = sanitize_textarea_field( $new['sender_signature'] );
		}
		if ( isset( $new['email_mode'] ) ) {
			$merged['email_mode'] = in_array( $new['email_mode'], self::EMAIL_MODES, true ) ? $new['email_mode'] : 'approval';
		}
		if ( isset( $new['linkedin_mode'] ) ) {
			$merged['linkedin_mode'] = in_array( $new['linkedin_mode'], self::DM_MODES, true ) ? $new['linkedin_mode'] : 'approval';
		}
		if ( isset( $new['instagram_mode'] ) ) {
			$merged['instagram_mode'] = in_array( $new['instagram_mode'], self::DM_MODES, true ) ? $new['instagram_mode'] : 'approval';
		}
		if ( isset( $new['webhook_url'] ) ) {
			$merged['webhook_url'] = esc_url_raw( $new['webhook_url'] );
		}
		if ( isset( $new['slack_webhook_url'] ) ) {
			$merged['slack_webhook_url'] = esc_url_raw( $new['slack_webhook_url'] );
		}
		if ( isset( $new['slack_channel'] ) ) {
			$merged['slack_channel'] = sanitize_text_field( $new['slack_channel'] );
		}
		$merged['digest_enabled'] = empty( $new['digest_enabled'] ) ? '0' : '1';
		if ( isset( $new['digest_email'] ) ) {
			$merged['digest_email'] = sanitize_email( $new['digest_email'] );
		}
		if ( isset( $new['daily_cap'] ) ) {
			$merged['daily_cap'] = min( 1000, max( 1, absint( $new['daily_cap'] ) ) );
		}
		if ( isset( $new['send_window_start'] ) ) {
			$merged['send_window_start'] = min( 23, max( 0, absint( $new['send_window_start'] ) ) );
		}
		if ( isset( $new['send_window_end'] ) ) {
			$merged['send_window_end'] = min( 23, max( 0, absint( $new['send_window_end'] ) ) );
		}
		if ( isset( $new['reply_token'] ) && '' !== trim( (string) $new['reply_token'] ) ) {
			$merged['reply_token'] = sanitize_text_field( $new['reply_token'] );
		}
		if ( isset( $new['champion_allocation'] ) ) {
			$merged['champion_allocation'] = min( 100, max( 50, absint( $new['champion_allocation'] ) ) );
		}
		if ( isset( $new['min_test_sends'] ) ) {
			$merged['min_test_sends'] = max( 1, absint( $new['min_test_sends'] ) );
		}
		if ( isset( $new['booking_page_id'] ) ) {
			$merged['booking_page_id'] = absint( $new['booking_page_id'] );
		}
		if ( isset( $new['default_booking_link'] ) ) {
			$merged['default_booking_link'] = absint( $new['default_booking_link'] );
		}

		update_option( self::OPTION, $merged, false );
		return $merged;
	}

	/**
	 * Whether the toolkit feature flag is enabled.
	 *
	 * @since 2.12.0
	 * @return bool
	 */
	public static function is_enabled() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_outbound_booking_toolkit'] );
	}
}
