<?php
/**
 * Plugin Name: NV oOS Page Agent
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: AI-powered page control copilot. Give any WordPress page its own AI agent that can click, type, and navigate via natural language. Requires NV oOS base plugin.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Requires Plugins: mcp-ai-wpoos
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-page-agent
 *
 * @package NV_oOS_Page_Agent
 *
 * Bundled page-agent library (MIT) by Alibaba.
 * See https://github.com/alibaba/page-agent for license and attribution.
 *
 * © 2025-2026 NV Digital Solutions - All Rights Reserved
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ────────────────────────────────────────────────
define( 'NVOOS_PAGE_AGENT_VERSION', '0.1.0' );
define( 'NVOOS_PAGE_AGENT_FILE', __FILE__ );
define( 'NVOOS_PAGE_AGENT_PATH', plugin_dir_path( __FILE__ ) );
define( 'NVOOS_PAGE_AGENT_URL', plugin_dir_url( __FILE__ ) );

// ── Dependency Check ─────────────────────────────────────────
/**
 * Check whether the NV oOS base plugin is active.
 *
 * @since 0.1.0
 *
 * @return bool True when the base plugin is available.
 */
function nvoos_page_agent_is_base_active() {
	if ( function_exists( 'wp_mcp_ai_core_loaded' ) && wp_mcp_ai_core_loaded() ) {
		return true;
	}

	return defined( 'WP_MCP_AI_VERSION' );
}

/**
 * Show admin notice when the base plugin is missing.
 *
 * @since 0.1.0
 *
 * @return void
 */
function nvoos_page_agent_missing_base_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'NV oOS Page Agent:', 'nvoos-page-agent' ),
		esc_html__( 'requires the NV oOS base plugin to be installed and active.', 'nvoos-page-agent' )
	);
}

if ( ! nvoos_page_agent_is_base_active() ) {
	add_action( 'admin_notices', 'nvoos_page_agent_missing_base_notice' );
	return;
}

// ── Bootstrap ─────────────────────────────────────────────────
require_once NVOOS_PAGE_AGENT_PATH . 'includes/class-wp-mcp-ai-page-agent.php';

add_action( 'plugins_loaded', array( 'WP_MCP_AI_Page_Agent', 'init' ), 20 );

/**
 * Set the "just activated" transient on plugin activation.
 */
register_activation_hook(
	NVOOS_PAGE_AGENT_FILE,
	function () {
		set_transient( 'nvoos_page_agent_activated', true, 30 );
	}
);
