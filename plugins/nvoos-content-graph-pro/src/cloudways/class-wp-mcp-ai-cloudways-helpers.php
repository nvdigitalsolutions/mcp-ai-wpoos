<?php
/**
 * Cloudways helpers (ecosystem port - Wave F2, cloudways infra slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/` directory for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in monolith
 * installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps with the `src/` root.
 *
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

declare(strict_types=1);


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
		'description' => __( 'Cloudways server ID.', 'nvoos-content-graph-pro' ),
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
		'description' => __( 'Cloudways application ID.', 'nvoos-content-graph-pro' ),
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
		'description' => __( 'Cloudways project ID.', 'nvoos-content-graph-pro' ),
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
		'description' => __( 'Cloudways operation ID for async task tracking.', 'nvoos-content-graph-pro' ),
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
		'description' => __( 'Explicitly confirm this destructive action by setting to true.', 'nvoos-content-graph-pro' ),
		'required'    => true,
		'default'     => false,
	);
}
