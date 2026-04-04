<?php
/**
 * Plugin Activation Tracker
 *
 * Tracks plugin activations for analytics purposes while respecting
 * user privacy and GDPR compliance. This class sends minimal,
 * non-identifying data to help us understand plugin usage patterns.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Activation_Tracker
 *
 * Handles plugin activation tracking in a privacy-first, opt-in manner.
 *
 * Privacy Features:
 * - Tracking is DISABLED by default (explicit opt-in required)
 * - No personally identifiable information collected
 * - Site URL is hashed using non-reversible algorithm
 * - IP addresses are not stored
 * - All data collection is documented transparently
 */
class WP_MCP_AI_Activation_Tracker {

	/**
	 * Tracking endpoint URL.
	 *
	 * @var string
	 */
	const TRACKING_ENDPOINT = 'https://nvdigitalsolutions.com/api/plugin-tracking/activation';

	/**
	 * Track plugin activation.
	 *
	 * Sends anonymous activation data to help understand plugin usage.
	 * Tracking is OPT-IN and disabled by default. Users must explicitly
	 * enable it via Settings → NV oOS → "Enable activation tracking".
	 *
	 * @param string $plugin_variant The plugin variant being activated (complete|base|pro|core).
	 * @return void
	 */
	public static function track_activation( $plugin_variant = 'complete' ) {
		// Validate plugin variant.
		$valid_variants = array( 'complete', 'base', 'pro', 'core' );
		if ( ! in_array( $plugin_variant, $valid_variants, true ) ) {
			$plugin_variant = 'complete'; // Fallback to default.
		}

		// Tracking is opt-in: disabled by default. Only send when explicitly enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$opted_in = ! empty( $settings['enable_activation_tracking'] );

		/**
		 * Filter whether to enable usage tracking.
		 *
		 * Receives the value of the 'enable_activation_tracking' setting, which
		 * is false for new installations (opt-in model). Return true from this
		 * filter to enable tracking regardless of the settings value.
		 *
		 * @param bool $opted_in Current opt-in state from settings (false by default for new installs).
		 */
		if ( ! apply_filters( 'wp_mcp_ai_enable_usage_tracking', $opted_in ) ) {
			return;
		}

		// Don't track in local/development environments.
		if ( self::is_local_environment() ) {
			return;
		}

		// Prepare tracking data (all non-identifying).
		$tracking_data = array(
			'plugin_variant'    => sanitize_text_field( $plugin_variant ),
			'plugin_version'    => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'unknown',
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'locale'            => get_locale(),
			'multisite'         => is_multisite(),
			'site_hash'         => self::get_site_hash(),
			'timestamp'         => time(),
		);

		// Add Pro version if available.
		if ( defined( 'WP_MCP_AI_PRO_VERSION' ) && 'pro' === $plugin_variant ) {
			$tracking_data['pro_version'] = WP_MCP_AI_PRO_VERSION;
		}

		// Add Core version if available.
		if ( defined( 'WP_MCP_AI_CORE_VERSION' ) && 'core' === $plugin_variant ) {
			$tracking_data['core_version'] = WP_MCP_AI_CORE_VERSION;
		}

		/**
		 * Filter the activation tracking data before sending.
		 *
		 * Allows developers to modify or add to the tracking data.
		 *
		 * @param array  $tracking_data The tracking data to be sent.
		 * @param string $plugin_variant The plugin variant being tracked.
		 */
		$tracking_data = apply_filters( 'wp_mcp_ai_activation_tracking_data', $tracking_data, $plugin_variant );

		// Send tracking data asynchronously (non-blocking).
		self::send_tracking_data_async( $tracking_data );
	}

	/**
	 * Generate a non-reversible hash of the site URL.
	 *
	 * This creates a unique identifier for the site without exposing the actual URL.
	 * Uses WordPress salts to ensure the hash cannot be reversed.
	 *
	 * @return string The hashed site identifier.
	 */
	private static function get_site_hash() {
		$site_url = get_site_url();

		// Use WordPress salts for additional security.
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'wp_mcp_ai_default_salt';

		// Generate a non-reversible hash.
		return hash_hmac( 'sha256', $site_url, $salt );
	}

	/**
	 * Check if the current environment is local/development.
	 *
	 * @return bool True if local environment, false otherwise.
	 */
	private static function is_local_environment() {
		$site_url = get_site_url();

		// Check for common local development URLs.
		$local_hosts = array(
			'localhost',
			'127.0.0.1',
			'::1',
			'.local',
			'.test',
			'.dev',
			'.example',
		);

		foreach ( $local_hosts as $local_host ) {
			if ( strpos( $site_url, $local_host ) !== false ) {
				return true;
			}
		}

		// Check WP_LOCAL_DEV constant.
		if ( defined( 'WP_LOCAL_DEV' ) && WP_LOCAL_DEV ) {
			return true;
		}

		return false;
	}

	/**
	 * Send tracking data asynchronously.
	 *
	 * Uses WordPress HTTP API to send data in a non-blocking manner.
	 * Failures are silent to avoid disrupting the activation process.
	 * Note: timeout parameter is included for completeness but not used when blocking=false.
	 *
	 * @param array $data The tracking data to send.
	 * @return void
	 */
	private static function send_tracking_data_async( $data ) {
		// Use wp_remote_post with a short timeout to avoid blocking.
		$response = wp_remote_post(
			self::TRACKING_ENDPOINT,
			array(
				'body'      => wp_json_encode( $data ),
				'headers'   => array(
					'Content-Type' => 'application/json',
					'User-Agent'   => 'NV-oOS-Tracker/' . ( defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0.0' ),
				),
				'blocking'  => false, // Non-blocking - don't wait for response.
				'sslverify' => true,
			)
		);

		// Silent failure - we don't want to disrupt activation if tracking fails.
		// No error handling needed since this is non-blocking and optional.
	}

	/**
	 * Track plugin deactivation.
	 *
	 * Similar to activation tracking, but for deactivations.
	 * Tracking is opt-in and only fires when explicitly enabled in settings.
	 *
	 * @param string $plugin_variant The plugin variant being deactivated.
	 * @return void
	 */
	public static function track_deactivation( $plugin_variant = 'complete' ) {
		// Validate plugin variant.
		$valid_variants = array( 'complete', 'base', 'pro', 'core' );
		if ( ! in_array( $plugin_variant, $valid_variants, true ) ) {
			$plugin_variant = 'complete'; // Fallback to default.
		}

		// Tracking is opt-in: disabled by default.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$opted_in = ! empty( $settings['enable_activation_tracking'] );

		/** This filter is documented in track_activation(). */
		if ( ! apply_filters( 'wp_mcp_ai_enable_usage_tracking', $opted_in ) ) {
			return;
		}

		// Don't track in local/development environments.
		if ( self::is_local_environment() ) {
			return;
		}

		// Prepare tracking data.
		$tracking_data = array(
			'event'             => 'deactivation',
			'plugin_variant'    => sanitize_text_field( $plugin_variant ),
			'plugin_version'    => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'unknown',
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'site_hash'         => self::get_site_hash(),
			'timestamp'         => time(),
		);

		/**
		 * Filter the deactivation tracking data before sending.
		 *
		 * @param array  $tracking_data The tracking data to be sent.
		 * @param string $plugin_variant The plugin variant being tracked.
		 */
		$tracking_data = apply_filters( 'wp_mcp_ai_deactivation_tracking_data', $tracking_data, $plugin_variant );

		// Send tracking data asynchronously.
		self::send_tracking_data_async( $tracking_data );
	}
}
