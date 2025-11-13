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

// Load base classes for settings sections.
// Note: Settings Registry and Validator are loaded in the main plugin file.
require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';

// Load custom filters applicator.
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-custom-filters-applicator.php';

// Load orchestration renderer.
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-orchestration-renderer.php';

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
		// Get container for dependency management.
		$container = wp_mcp_ai_container();

		// Register all sections with the registry using container.
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.overview' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.general' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.custom_filters' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.providers' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.authentication' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.tools' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.orchestration' ) );
		// Gmail & Crawl4AI Integration has its own dedicated page (wp-mcp-ai-gmail-crawl4ai) and should not appear in settings tabs.
		// WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.integrations' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.jetengine_integration' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.woocommerce_integration' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.elementor_integration' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.token_manager' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.security' ) );
		// Performance section is now embedded as a sub-tab within Advanced Settings.
		// WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.performance' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.advanced' ) );

		// Initialize the dashboard controller.
		// This creates the top-level "WP oOS" menu item.
		// Store the instance globally for potential access by other code.
		$GLOBALS['wp_mcp_ai_settings_dashboard'] = $container->get( 'admin.settings_dashboard' );

		// Initialize integration admin pages.
		$GLOBALS['wp_mcp_ai_admin_jetengine']   = $container->get( 'admin.jetengine_integration' );
		$GLOBALS['wp_mcp_ai_admin_woocommerce'] = $container->get( 'admin.woocommerce_integration' );
		$GLOBALS['wp_mcp_ai_admin_elementor']   = $container->get( 'admin.elementor_integration' );
		$GLOBALS['wp_mcp_ai_admin_gmail_crawl'] = $container->get( 'admin.gmail_crawl_integration' );

		// Initialize the custom filters applicator.
		// This applies saved filter values to WordPress filters.
		$GLOBALS['wp_mcp_ai_custom_filters_applicator'] = $container->get( 'admin.custom_filters_applicator' );
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
			function () use ( $e ) {
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
