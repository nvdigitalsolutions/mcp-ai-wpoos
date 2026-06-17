<?php
/**
 * Cloudways Toolkit Helpers
 *
 * Shared utility functions and constants for the Cloudways Pro Toolkit.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage Cloudways_Toolkit
 * @since      1.1.15
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if the Cloudways Pro Toolkit is enabled.
 *
 * The toolkit must be explicitly enabled in plugin settings.
 *
 * @since 1.1.15
 *
 * @return bool True if enabled, false otherwise.
 */
function wp_mcp_ai_is_cloudways_toolkit_enabled() {
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	return ! empty( $settings['enable_cloudways_toolkit'] );
}

/**
 * Check if the Cloudways Pro Toolkit has configured credentials.
 *
 * @since 1.1.15
 *
 * @return bool
 */
function wp_mcp_ai_cloudways_has_credentials() {
	$client = WP_MCP_AI_Cloudways_Client::instance();
	return $client->is_configured();
}

/**
 * Get shared Cloudways parameter schema fragment: server_id.
 *
 * @since 1.1.15
 *
 * @return array
 */
function wp_mcp_ai_cloudways_param_server_id() {
	return array(
		'type'        => 'integer',
		'description' => __( 'Cloudways server ID.', 'mcp-ai-wpoos-pro' ),
		'required'    => true,
	);
}

/**
 * Get shared Cloudways parameter schema fragment: app_id.
 *
 * @since 1.1.15
 *
 * @return array
 */
function wp_mcp_ai_cloudways_param_app_id() {
	return array(
		'type'        => 'integer',
		'description' => __( 'Cloudways application ID.', 'mcp-ai-wpoos-pro' ),
		'required'    => true,
	);
}

/**
 * Get shared Cloudways parameter schema fragment: project_id.
 *
 * @since 1.1.15
 *
 * @return array
 */
function wp_mcp_ai_cloudways_param_project_id() {
	return array(
		'type'        => 'integer',
		'description' => __( 'Cloudways project ID.', 'mcp-ai-wpoos-pro' ),
		'required'    => false,
	);
}

/**
 * Get shared Cloudways parameter schema fragment: operation_id.
 *
 * @since 1.1.15
 *
 * @return array
 */
function wp_mcp_ai_cloudways_param_operation_id() {
	return array(
		'type'        => 'string',
		'description' => __( 'Cloudways operation ID for async task tracking.', 'mcp-ai-wpoos-pro' ),
		'required'    => true,
	);
}

/**
 * Get shared Cloudways parameter schema fragment: confirm (destructive guard).
 *
 * @since 1.1.15
 *
 * @return array
 */
function wp_mcp_ai_cloudways_param_confirm() {
	return array(
		'type'        => 'boolean',
		'description' => __( 'Explicitly confirm this destructive action by setting to true.', 'mcp-ai-wpoos-pro' ),
		'required'    => true,
		'default'     => false,
	);
}
