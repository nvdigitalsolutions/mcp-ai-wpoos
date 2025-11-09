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

// Load custom filters applicator.
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-custom-filters-applicator.php';

// Load dashboard controller.
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-settings-dashboard.php';

// Load all section implementations.
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-overview.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-general.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-custom-filters.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-providers.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-authentication.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-tools.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-orchestration.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-integrations.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-jetengine.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-woocommerce.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-elementor.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-token-manager.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-security.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';
require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-advanced.php';

// Load integration admin pages.
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-displays-dashboard.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-jetengine.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-woocommerce.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-elementor.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-gmail-crawl.php';

/**
 * Initialize the settings dashboard system.
 *
 * Note: This is the NEW modular settings dashboard. The legacy monolithic
 * settings page (class-wp-mcp-ai-admin-settings.php) continues to function
 * alongside this new system during the transition period.
 */
function wp_mcp_ai_init_settings_dashboard() {
	// Only initialize once.
	static $initialized = false;
	if ( $initialized ) {
		return;
	}
	$initialized = true;

	// Wrap initialization in try-catch to prevent silent failures.
	try {
		// Register all sections with the registry.
		WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Overview() );
		WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_General() );
		WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Custom_Filters() );
		WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Providers() );
		WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Authentication() );
		WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Tools() );
		WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Orchestration() );
		WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Integrations() );
		WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_JetEngine_Integration() );
		WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_WooCommerce_Integration() );
		WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Elementor_Integration() );
		WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Token_Manager() );
		WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Security() );
		WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Performance() );
		WP_MCP_AI_Settings_Registry::register_section( new WP_MCP_AI_Section_Advanced() );

		// Initialize the dashboard controller.
		// This creates the top-level "WP oOS" menu item.
		// Store the instance globally for potential access by other code.
		$GLOBALS['wp_mcp_ai_settings_dashboard'] = new WP_MCP_AI_Settings_Dashboard();

		// Initialize integration admin pages.
		$GLOBALS['wp_mcp_ai_admin_displays_dashboard'] = new WP_MCP_AI_Admin_Displays_Dashboard();
		$GLOBALS['wp_mcp_ai_admin_jetengine'] = new WP_MCP_AI_Admin_JetEngine_Integration();
		$GLOBALS['wp_mcp_ai_admin_woocommerce'] = new WP_MCP_AI_Admin_WooCommerce_Integration();
		$GLOBALS['wp_mcp_ai_admin_elementor'] = new WP_MCP_AI_Admin_Elementor_Integration();
		$GLOBALS['wp_mcp_ai_admin_gmail_crawl'] = new WP_MCP_AI_Admin_Gmail_Crawl_Integration();

		// Initialize the custom filters applicator.
		// This applies saved filter values to WordPress filters.
		$GLOBALS['wp_mcp_ai_custom_filters_applicator'] = new WP_MCP_AI_Custom_Filters_Applicator();
	} catch ( Throwable $e ) {
		// Log the error if logging is enabled.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'error',
				'Failed to initialize settings dashboard: ' . $e->getMessage(),
				array(
					'file'  => $e->getFile(),
					'line'  => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				)
			);
		}

		// Show admin notice about the error.
		add_action(
			'admin_notices',
			function() use ( $e ) {
				?>
				<div class="notice notice-error">
					<p>
						<strong>WP oOS Settings Dashboard Error:</strong>
						<?php echo esc_html( $e->getMessage() ); ?>
					</p>
					<p>
						<em>Please check the error log for more details or contact support.</em>
					</p>
				</div>
				<?php
			}
		);
	}
}

// Initialize immediately when this file is loaded.
// This ensures the dashboard is instantiated before the admin_menu hook fires.
// This file is only loaded when is_admin() is true, so we don't need to check again.
wp_mcp_ai_init_settings_dashboard();
