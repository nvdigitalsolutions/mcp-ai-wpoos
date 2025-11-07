<?php
/**
 * Settings Dashboard Initialization
 *
 * Loads the modular settings dashboard system.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load registry and base classes.
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-settings-registry.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-settings-validator.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';

// Load dashboard controller.
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-settings-dashboard.php';

// Load all section implementations.
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-general.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-providers.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-authentication.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-tools.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-integrations.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-security.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-advanced.php';

/**
 * Initialize the settings dashboard system.
 *
 * Note: This is the NEW modular settings dashboard. The legacy monolithic
 * settings page (class-wp-mcp-ai-admin-settings.php) continues to function
 * alongside this new system during the transition period.
 */
function wp_mcp_ai_init_settings_dashboard() {
	// Register all sections with the registry.
	WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_General() );
	WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Providers() );
	WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Authentication() );
	WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Tools() );
	WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Integrations() );
	WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Security() );
	WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Advanced() );

	// Initialize the dashboard controller.
	// This creates the new Settings > WP oOS menu item.
	new WP_MCP_AI_Settings_Dashboard();
}

// Initialize dashboard on admin_init to ensure WordPress is fully loaded.
add_action( 'admin_init', 'wp_mcp_ai_init_settings_dashboard', 1 );
