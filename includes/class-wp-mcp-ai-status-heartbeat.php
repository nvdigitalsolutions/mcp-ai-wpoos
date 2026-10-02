<?php
/**
 * Status Heartbeat — fleet monitoring emitter.
 *
 * Pushes this site's local service-status snapshot to the Media Worker's
 * status module (POST /api/status/heartbeat) on the existing consolidated
 * five-minute cron tick. The worker treats a missed heartbeat as a down
 * site (dead man's switch), so the emitter must be cheap, non-blocking, and
 * never interfere with the tick's other duties.
 *
 * Opt-in: nothing is sent unless the `wp_mcp_ai_status_heartbeat_enabled`
 * option is set and a worker URL is configured. The payload carries the
 * local status components (allowlisted slugs only) — never credentials.
 *
 * @package   WP_MCP_AI
 * @since     1.1.93
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status heartbeat emitter class.
 *
 * @since 1.1.93
 */
class WP_MCP_AI_Status_Heartbeat {

	/**
	 * Option flag enabling the heartbeat.
	 *
	 * @since 1.1.93
	 * @var string
	 */
	const OPTION_ENABLED = 'wp_mcp_ai_status_heartbeat_enabled';

	/**
	 * Option flag controlling whether component detail is included.
	 *
	 * @since 1.1.93
	 * @var string
	 */
	const OPTION_INCLUDE_DETAILS = 'wp_mcp_ai_status_heartbeat_include_details';

	/**
	 * Transient lock key preventing concurrent sends.
	 *
	 * @since 1.1.93
	 * @var string
	 */
	const LOCK_KEY = 'wp_mcp_ai_status_heartbeat_lock';

	/**
	 * Payload version spoken with the worker (see the worker's status module).
	 *
	 * @since 1.1.93
	 * @var int
	 */
	const PAYLOAD_VERSION = 1;

	/**
	 * Register hooks.
	 *
	 * Subscribes to the consolidated five-minute tick's completion action so
	 * the heartbeat never delays health checks, maintenance transitions, or
	 * the delegation watchdog that run earlier in the same process.
	 *
	 * @since 1.1.93
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_mcp_ai_five_minute_tick_completed', array( __CLASS__, 'maybe_send' ), 10, 0 );
	}

	/**
	 * Whether the heartbeat feature is enabled and configured.
	 *
	 * @since 1.1.93
	 *
	 * @return bool True when the site should emit heartbeats.
	 */
	public static function is_enabled() {
		return (bool) get_option( self::OPTION_ENABLED, false ) && WP_MCP_AI_Media_Worker_Config::is_configured();
	}

	/**
	 * Send a heartbeat when enabled (idempotent, transient-locked).
	 *
	 * @since 1.1.93
	 *
	 * @return bool True when a heartbeat was attempted (regardless of outcome).
	 */
	public static function maybe_send() {
		if ( ! self::is_enabled() ) {
			return false;
		}

		// Prevent overlapping sends (4-minute lock against a 5-minute tick).
		if ( get_transient( self::LOCK_KEY ) ) {
			return false;
		}
		set_transient( self::LOCK_KEY, 1, 4 * MINUTE_IN_SECONDS );

		$payload = self::build_payload();
		$result  = WP_MCP_AI_Media_Worker_Config::request( '/api/status/heartbeat', 'POST', $payload, 3 );

		if ( is_wp_error( $result ) ) {
			if ( function_exists( 'wp_mcp_ai_log_error' ) ) {
				wp_mcp_ai_log_error(
					'Status heartbeat delivery failed.',
					array( 'error' => $result->get_error_message() )
				);
			}

			/**
			 * Fires when a heartbeat could not be delivered.
			 *
			 * @since 1.1.93
			 *
			 * @param WP_Error $error Delivery error.
			 */
			do_action( 'wp_mcp_ai_status_heartbeat_failed', $result );

			return true;
		}

		/**
		 * Fires after a heartbeat was accepted by the worker.
		 *
		 * @since 1.1.93
		 *
		 * @param array $payload The heartbeat payload that was sent.
		 */
		do_action( 'wp_mcp_ai_status_heartbeat_sent', $payload );

		return true;
	}

	/**
	 * Build the heartbeat payload from the local status snapshot.
	 *
	 * Reads the cached snapshot only — never triggers a health check on the
	 * cron thread (the registry runs its own checks earlier in the tick).
	 *
	 * @since 1.1.93
	 *
	 * @return array Payload matching the worker's heartbeat v1 contract.
	 */
	public static function build_payload() {
		$payload = array(
			'v'        => self::PAYLOAD_VERSION,
			'site_url' => home_url(),
			'sent_at'  => time(),
			'checks'   => array(
				'overall'    => 'operational',
				'components' => array(),
			),
			'meta'     => array(
				'wp_version'        => get_bloginfo( 'version' ),
				'php_version'       => PHP_VERSION,
				'plugin_version'    => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'dev',
				'maintenance_until' => self::get_active_maintenance_until(),
			),
		);

		if ( class_exists( 'WP_MCP_AI_Service_Status_Registry' ) ) {
			$registry = WP_MCP_AI_Service_Status_Registry::get_instance();
			$status   = $registry->get_cached_status();

			if ( ! empty( $status ) && is_array( $status ) ) {
				$overall = $registry->compute_overall_status( $status );
				if ( is_string( $overall ) && '' !== $overall ) {
					$payload['checks']['overall'] = $overall;
				}

				if ( get_option( self::OPTION_INCLUDE_DETAILS, true ) ) {
					$sources = $registry->get_sources();
					foreach ( $status as $slug => $data ) {
						// Allowlist: only known, public-facing source slugs leave
						// the site, and only the two display fields.
						if ( ! isset( $sources[ $slug ] ) || ! $sources[ $slug ]->is_public() ) {
							continue;
						}

						$payload['checks']['components'][ sanitize_key( $slug ) ] = array(
							'status'  => sanitize_text_field( isset( $data['status'] ) ? $data['status'] : 'unknown' ),
							'message' => sanitize_text_field( isset( $data['message'] ) ? $data['message'] : '' ),
						);
					}
				}
			}
		}

		/**
		 * Filter the heartbeat payload before it is sent.
		 *
		 * @since 1.1.93
		 *
		 * @param array $payload Heartbeat payload.
		 */
		return apply_filters( 'wp_mcp_ai_status_heartbeat_payload', $payload );
	}

	/**
	 * Get the end timestamp of the active maintenance window (unix seconds),
	 * or 0 when none is active. Pro-only data; base sites always send 0.
	 *
	 * @since 1.1.93
	 *
	 * @return int Unix timestamp.
	 */
	private static function get_active_maintenance_until() {
		if ( ! class_exists( 'WP_MCP_AI_Maintenance_CPT' ) ) {
			return 0;
		}

		$active = WP_MCP_AI_Maintenance_CPT::get_active_window();
		if ( empty( $active ) ) {
			return 0;
		}

		$end = get_post_meta( $active->ID, '_mcp_ai_maintenance_end', true );
		$end = is_numeric( $end ) ? (int) $end : strtotime( (string) $end );

		return $end > 0 ? $end : 0;
	}
}
