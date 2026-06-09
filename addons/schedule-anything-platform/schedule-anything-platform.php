<?php
/**
 * Plugin Name: Schedule Anything Platform
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: Multi-tenant SaaS platform for scheduling and automating work across all NV oOS toolkits. Handles tenant provisioning, cross-tenant security, usage tracking, and platform REST endpoints. Requires NV oOS Base + Pro.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: Proprietary — NV Digital Solutions, All Rights Reserved
 * License URI: https://nvdigitalsolutions.com/wpoos/license
 * Text Domain: schedule-anything
 * Domain Path: /languages
 * Requires Plugins: mcp-ai-wpoos
 *
 * @package Schedule_Anything
 *
 * ⚠️ PROPRIETARY SOFTWARE
 * This is commercial software licensed for authorized users only.
 * Patent Pending (Application #19/410,504)
 * © 2025-2026 NV Digital Solutions - All Rights Reserved
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com).
 * All rights reserved.
 *
 * This addon is PROPRIETARY software of NV Digital Solutions. It is NOT
 * licensed under the GPL that covers the rest of the NV oOS repository,
 * and it is NOT distributed via WordPress.org. Use, reproduction, modification,
 * and redistribution are governed by the addon-local `LICENSE` file shipped in
 * this directory. See `LICENSE` for the full terms.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
define( 'SA_PLATFORM_VERSION', '0.1.0' );

/** Absolute path to this plugin file. */
define( 'SA_PLATFORM_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'SA_PLATFORM_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'SA_PLATFORM_URL', plugin_dir_url( __FILE__ ) );

/**
 * Check whether the NV oOS base plugin is active.
 *
 * @since 0.1.0
 *
 * @return bool True if the base plugin is detected.
 */
function sa_platform_base_is_active() {
	if ( function_exists( 'wp_mcp_ai_core_loaded' ) && wp_mcp_ai_core_loaded() ) {
		return true;
	}

	return class_exists( 'WP_MCP_AI_Tool_Registry' );
}

/**
 * Check whether the NV oOS Pro addon is active.
 *
 * @since 0.1.0
 *
 * @return bool True if Pro is detected.
 */
function sa_platform_pro_is_active() {
	return defined( 'WP_MCP_AI_PRO_VERSION' )
		&& class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' );
}

/**
 * Print an admin notice when a required plugin is missing.
 *
 * @since 0.1.0
 *
 * @param string $plugin_name Human-readable plugin name.
 * @return void
 */
function sa_platform_missing_plugin_notice( $plugin_name ) {
	echo '<div class="notice notice-error"><p>';
	printf(
		/* translators: %1$s: this plugin name, %2$s: required plugin name */
		esc_html__( 'Schedule Anything Platform requires %2$s to be installed and activated.', 'schedule-anything' ),
		'<strong>Schedule Anything Platform</strong>',
		'<strong>' . esc_html( $plugin_name ) . '</strong>'
	);
	echo '</p></div>';
}

/**
 * Bootstrap the platform plugin once all plugins are loaded.
 *
 * @since 0.1.0
 *
 * @return void
 */
function sa_platform_bootstrap() {
	if ( ! sa_platform_base_is_active() ) {
		add_action(
			'admin_notices',
			function () {
				sa_platform_missing_plugin_notice( 'NV oOS Base' );
			}
		);
		return;
	}

	if ( ! sa_platform_pro_is_active() ) {
		add_action(
			'admin_notices',
			function () {
				sa_platform_missing_plugin_notice( 'NV oOS Pro' );
			}
		);
		return;
	}

	// Load core classes.
	require_once SA_PLATFORM_PATH . 'includes/class-sa-plugin.php';
	require_once SA_PLATFORM_PATH . 'includes/class-sa-cross-tenant-security.php';
	require_once SA_PLATFORM_PATH . 'includes/class-sa-toolkit-manager.php';
	require_once SA_PLATFORM_PATH . 'includes/class-sa-multisite-provisioner.php';
	require_once SA_PLATFORM_PATH . 'includes/class-sa-usage-tracker.php';

	// Load REST controller.
	require_once SA_PLATFORM_PATH . 'includes/rest/class-sa-rest-controller.php';

	// Initialize the plugin.
	SA_Plugin::init();

	// Register cross-tenant security (must be early).
	SA_Cross_Tenant_Security::init();

	// Register REST routes on rest_api_init.
	add_action( 'rest_api_init', array( 'SA_REST_Controller', 'register_routes' ) );
}
add_action( 'plugins_loaded', 'sa_platform_bootstrap', 20 );
