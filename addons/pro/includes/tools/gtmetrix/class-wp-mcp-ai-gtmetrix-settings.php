<?php
/**
 * GTmetrix Toolkit — Settings.
 *
 * Central configuration for the GTmetrix performance toolkit: API key
 * reference (stored in the encrypted API key store), credit budget, test
 * target allowlist, snapshot retention, polling and core-page registry.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.5
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GTmetrix toolkit settings wrapper.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_GTmetrix_Settings {

	const OPTION_KEY = 'wp_mcp_ai_gtmetrix_settings';

	const API_BASE_URL = 'https://gtmetrix.com/api/2.0/';

	/**
	 * Default settings.
	 *
	 * @var array
	 */
	private static $defaults = array(
		'credit_budget_daily'     => 25,
		'allowed_test_hosts'      => array(),
		'snapshot_retention_days' => 90,
		'poll_max_attempts'       => 20,
		'poll_interval_s'         => 3,
		'default_location'        => '',
		'default_browser'         => '',
		'core_pages'              => array(),
	);

	/**
	 * Get all settings merged over defaults.
	 *
	 * @return array
	 */
	public static function get_all() {
		$saved = get_option( self::OPTION_KEY, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::$defaults );
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::get_all();

		return isset( $all[ $key ] ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Whether the toolkit toggle is enabled.
	 *
	 * Mirrors the registry gate in the Pro addon (`enable_gtmetrix_toolkit`).
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		return ! empty( $settings['enable_gtmetrix_toolkit'] );
	}

	/**
	 * Get the GTmetrix API key from the encrypted key store.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		if ( function_exists( 'wp_mcp_ai_get_api_key' ) ) {
			return (string) wp_mcp_ai_get_api_key( 'gtmetrix_api_key', '' );
		}

		return (string) get_option( 'wp_mcp_ai_gtmetrix_api_key', '' );
	}

	/**
	 * Daily credit budget (fail-closed when exhausted).
	 *
	 * @return float
	 */
	public static function credit_budget_daily() {
		return (float) self::get( 'credit_budget_daily', 25 );
	}

	/**
	 * Hosts (lowercased) allowed as test targets; defaults to the site host.
	 *
	 * @return string[]
	 */
	public static function allowed_test_hosts() {
		$hosts = self::get( 'allowed_test_hosts', array() );
		if ( ! is_array( $hosts ) ) {
			$hosts = array();
		}

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $home_host && ! in_array( strtolower( $home_host ), $hosts, true ) ) {
			$hosts[] = strtolower( $home_host );
		}

		return array_map( 'strtolower', $hosts );
	}

	/**
	 * Whether a URL is an allowed test target.
	 *
	 * @param string $url Target URL.
	 * @return bool
	 */
	public static function is_url_allowed( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}

		return in_array( strtolower( $host ), self::allowed_test_hosts(), true );
	}

	/**
	 * Registered core pages ({url, label, tags[]}) for scheduled sweeps.
	 *
	 * @return array
	 */
	public static function core_pages() {
		$pages = self::get( 'core_pages', array() );
		if ( ! is_array( $pages ) ) {
			$pages = array();
		}

		if ( empty( $pages ) ) {
			$pages[] = array(
				'url'   => home_url( '/' ),
				'label' => __( 'Home', 'mcp-ai-wpoos-pro' ),
				'tags'  => array(),
			);
		}

		return $pages;
	}

	/**
	 * Whether fixture mode is active (tests, demos, offline).
	 *
	 * @return bool
	 */
	public static function is_fixture_mode() {
		return ( defined( 'WP_MCP_AI_GTMETRIX_USE_FIXTURES' ) && WP_MCP_AI_GTMETRIX_USE_FIXTURES );
	}
}
