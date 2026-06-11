<?php
/**
 * DietPi Pro Toolkit — Unified Bootstrap
 *
 * Single entry point for the DietPi Pro Toolkit.
 * Loads shared infrastructure (SSH client, app client, helpers,
 * service catalogue) and the settings page when the toolkit is
 * enabled via the `enable_dietpi_toolkit` toggle.
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

// ── Always load shared infrastructure so other Pro code can rely on it. ──

// Service catalogue (static registry, no dependencies).
if ( ! class_exists( 'WP_MCP_AI_DietPi_Service_Catalogue' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/dietpi/class-wp-mcp-ai-dietpi-service-catalogue.php';
}

// Helpers (gate functions, schema fragments).
if ( ! function_exists( 'wp_mcp_ai_is_dietpi_toolkit_enabled' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/dietpi/class-wp-mcp-ai-dietpi-helpers.php';
}

// SSH client (system-level interaction).
if ( ! class_exists( 'WP_MCP_AI_DietPi_SSH_Client' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/dietpi/class-wp-mcp-ai-dietpi-ssh-client.php';
}

// App API client (HTTP interaction with Transmission, Jackett, Sonarr, Radarr, etc.).
if ( ! class_exists( 'WP_MCP_AI_DietPi_App_Client' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/dietpi/class-wp-mcp-ai-dietpi-app-client.php';
}

// Abstract tool base (required by all DietPi tool classes).
if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Base' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/tools/dietpi/class-wp-mcp-ai-tool-dietpi-base.php';
}

// ── Admin settings page ──
if ( is_admin() ) {
	if ( ! class_exists( 'WP_MCP_AI_DietPi_Settings_Page' ) ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-dietpi-settings-page.php';
		new WP_MCP_AI_DietPi_Settings_Page();
	}

	// Enqueue admin styles for the DietPi settings page.
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_dietpi_toolkit_admin_styles' );
}

/**
 * Enqueue DietPi toolkit admin styles.
 *
 * @since 1.3.0
 */
function wp_mcp_ai_enqueue_dietpi_toolkit_admin_styles() {
	if ( ! function_exists( 'wp_mcp_ai_is_dietpi_toolkit_enabled' ) || ! wp_mcp_ai_is_dietpi_toolkit_enabled() ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || false === strpos( $screen->id, 'dietpi' ) ) {
		return;
	}

	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-dietpi-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-dietpi-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-dietpi-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
