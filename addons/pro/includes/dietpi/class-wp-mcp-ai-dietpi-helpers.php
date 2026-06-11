<?php
/**
 * DietPi Toolkit Helpers
 *
 * Shared utility functions and constants for the DietPi Pro Toolkit.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage DietPi_Toolkit
 * @since      1.3.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if the DietPi Pro Toolkit is enabled.
 *
 * The toolkit must be explicitly enabled in plugin settings.
 *
 * @since 1.3.0
 *
 * @return bool True if enabled, false otherwise.
 */
function wp_mcp_ai_is_dietpi_toolkit_enabled() {
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	return ! empty( $settings['enable_dietpi_toolkit'] );
}

/**
 * Check if DietPi SSH credentials are configured.
 *
 * Requires at minimum a host and either a key or password.
 *
 * @since 1.3.0
 *
 * @return bool
 */
function wp_mcp_ai_dietpi_has_ssh_credentials() {
	$settings = get_option( 'wp_mcp_ai_dietpi_settings', array() );
	$settings = is_array( $settings ) ? $settings : array();

	$host = isset( $settings['host'] ) ? trim( $settings['host'] ) : '';
	if ( '' === $host ) {
		return false;
	}

	$auth_method = isset( $settings['ssh_auth_method'] ) ? $settings['ssh_auth_method'] : 'key';

	if ( 'key' === $auth_method ) {
		return isset( $settings['ssh_private_key'] ) && '' !== trim( $settings['ssh_private_key'] );
	}

	return isset( $settings['ssh_password'] ) && '' !== trim( $settings['ssh_password'] );
}

/**
 * Check if a specific DietPi-managed app has its API configured.
 *
 * @since 1.3.0
 *
 * @param string $app_slug App slug (e.g. 'sonarr', 'transmission').
 * @return bool
 */
function wp_mcp_ai_dietpi_is_app_configured( $app_slug ) {
	$settings = get_option( 'wp_mcp_ai_dietpi_settings', array() );
	$settings = is_array( $settings ) ? $settings : array();

	$apps = isset( $settings['apps'] ) ? $settings['apps'] : array();
	if ( ! isset( $apps[ $app_slug ] ) ) {
		return false;
	}

	$app = $apps[ $app_slug ];
	if ( empty( $app['enabled'] ) ) {
		return false;
	}

	$url = isset( $app['url'] ) ? trim( $app['url'] ) : '';
	if ( '' === $url ) {
		return false;
	}

	// Each app has its own auth field(s).
	switch ( $app_slug ) {
		case 'transmission':
			return isset( $app['username'] ) && '' !== trim( $app['username'] )
				&& isset( $app['password'] ) && '' !== trim( $app['password'] );
		case 'jackett':
		case 'sonarr':
		case 'radarr':
		case 'jellyfin':
			return isset( $app['api_key'] ) && '' !== trim( $app['api_key'] );
		case 'plex':
			return isset( $app['token'] ) && '' !== trim( $app['token'] );
		default:
			return false;
	}
}

/**
 * Get DietPi toolkit settings with defaults merged.
 *
 * @since 1.3.0
 *
 * @return array
 */
function wp_mcp_ai_dietpi_get_settings() {
	$defaults = array(
		'host'                => '',
		'ssh_port'            => 22,
		'ssh_user'            => 'root',
		'ssh_auth_method'     => 'key',
		'ssh_private_key'     => '',
		'ssh_key_passphrase'  => '',
		'ssh_password'        => '',
		'apps'                => array(
			'transmission' => array( 'enabled' => false, 'url' => '', 'username' => '', 'password' => '' ),
			'jackett'      => array( 'enabled' => false, 'url' => '', 'api_key' => '' ),
			'sonarr'       => array( 'enabled' => false, 'url' => '', 'api_key' => '' ),
			'radarr'       => array( 'enabled' => false, 'url' => '', 'api_key' => '' ),
			'plex'         => array( 'enabled' => false, 'url' => '', 'token' => '' ),
			'jellyfin'     => array( 'enabled' => false, 'url' => '', 'api_key' => '' ),
		),
		'default_download_dir' => '/mnt/dietpi_userdata/downloads',
		'command_timeout'      => 30,
		'cache_ttl_seconds'    => 60,
		'log_ssh_commands'     => false,
	);

	$saved    = get_option( 'wp_mcp_ai_dietpi_settings', array() );
	$saved    = is_array( $saved ) ? $saved : array();
	$resolved = array_replace_recursive( $defaults, $saved );

	/**
	 * Filter the resolved DietPi toolkit settings.
	 *
	 * @since 1.3.0
	 *
	 * @param array $resolved Resolved settings array.
	 * @param array $saved    Raw option value.
	 */
	$resolved = apply_filters( 'wp_mcp_ai_dietpi_toolkit_settings', $resolved, $saved );

	return is_array( $resolved ) ? $resolved : $defaults;
}

/**
 * Get shared parameter schema fragment: service_name.
 *
 * Used by several tools that target a specific DietPi service.
 *
 * @since 1.3.0
 *
 * @param bool $required Whether the parameter is required.
 * @return array
 */
function wp_mcp_ai_dietpi_param_service_name( $required = true ) {
	return array(
		'type'        => 'string',
		'description' => __( 'DietPi service name (e.g. sonarr, radarr, transmission-daemon, jackett, plexmediaserver, jellyfin).', 'mcp-ai-wpoos-pro' ),
		'required'    => $required,
	);
}

/**
 * Get shared parameter schema fragment: service_action.
 *
 * Used by service control tools (start / stop / restart / status).
 *
 * @since 1.3.0
 *
 * @param bool $required Whether the parameter is required.
 * @return array
 */
function wp_mcp_ai_dietpi_param_service_action( $required = true ) {
	return array(
		'type'        => 'string',
		'description' => __( 'Action to perform on the service.', 'mcp-ai-wpoos-pro' ),
		'enum'        => array( 'start', 'stop', 'restart', 'status' ),
		'required'    => $required,
	);
}

/**
 * Get shared parameter schema fragment: confirm (destructive guard).
 *
 * @since 1.3.0
 *
 * @return array
 */
function wp_mcp_ai_dietpi_param_confirm() {
	return array(
		'type'        => 'boolean',
		'description' => __( 'Explicitly confirm this destructive action by setting to true.', 'mcp-ai-wpoos-pro' ),
		'required'    => true,
		'default'     => false,
	);
}
