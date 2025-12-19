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
// Note: abstract-wp-mcp-ai-settings-section.php is now loaded early in main plugin file
// (before Pro addon loads) to prevent fatal errors when Pro sections extend it during activation.

// Load custom filters applicator.
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-custom-filters-applicator.php';

// Load orchestration renderer.
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-orchestration-renderer.php';

// Load dashboard controller.
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-settings-dashboard.php';

// Register autoloader for settings sections (lazy loading).
// This loads section class files only when they are actually instantiated,.

// significantly improving admin page load performance.
spl_autoload_register(
	function ( $class_name ) {
		// Map of section class names to their file paths.
		$section_files = array(
			'WP_MCP_AI_Section_Overview'            => 'includes/admin/sections/class-wp-mcp-ai-section-overview.php',
			'WP_MCP_AI_Section_General'             => 'includes/admin/sections/class-wp-mcp-ai-section-general.php',
			'WP_MCP_AI_Section_Chat_Client'         => 'includes/admin/sections/class-wp-mcp-ai-section-chat-client.php',
			'WP_MCP_AI_Section_Custom_Filters'      => 'includes/admin/sections/class-wp-mcp-ai-section-custom-filters.php',
			'WP_MCP_AI_Section_Providers'           => 'includes/admin/sections/class-wp-mcp-ai-section-providers.php',
			'WP_MCP_AI_Section_Authentication'      => 'includes/admin/sections/class-wp-mcp-ai-section-authentication.php',
			'WP_MCP_AI_Section_Tools'               => 'includes/admin/sections/class-wp-mcp-ai-section-tools.php',
			'WP_MCP_AI_Section_Orchestration'       => 'includes/admin/sections/class-wp-mcp-ai-section-orchestration.php',
			'WP_MCP_AI_Section_Integrations'        => 'includes/admin/sections/class-wp-mcp-ai-section-integrations.php',
			'WP_MCP_AI_Section_Plugins_Integration' => 'includes/admin/sections/class-wp-mcp-ai-section-plugins-integration.php',
			'WP_MCP_AI_Section_Token_Manager'       => 'includes/admin/sections/class-wp-mcp-ai-section-token-manager.php',
			'WP_MCP_AI_Section_Security'            => 'includes/admin/sections/class-wp-mcp-ai-section-security.php',
			'WP_MCP_AI_Section_Advanced'            => 'includes/admin/sections/class-wp-mcp-ai-section-advanced.php',
			'WP_MCP_AI_Section_Media'               => 'includes/admin/sections/class-wp-mcp-ai-section-media.php',
			'WP_MCP_AI_Section_Comments'            => 'includes/admin/sections/class-wp-mcp-ai-section-comments.php',
			'WP_MCP_AI_Section_Site_Creator'        => 'includes/admin/sections/class-wp-mcp-ai-section-site-creator.php',
		);

		// Check if this is a section class we should autoload.
		if ( isset( $section_files[ $class_name ] ) ) {
			$file = WP_MCP_AI_PATH . $section_files[ $class_name ];
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}
);

// Load integration admin pages.
// These need to be loaded eagerly as they register admin menus and hooks.
// Note: Plugin integrations (JetEngine, WooCommerce, Elementor) now use sections instead of standalone page.
// Note: External tools integration (Gmail, Crawl4AI, etc.) now uses sections instead of standalone page.
// require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-plugins.php';.

// require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-gmail-crawl.php';.


/**
 * Initialize the settings dashboard system.
 *
 * Note: This is the primary settings dashboard system. The legacy monolithic
 * settings page (class-wp-mcp-ai-admin-settings.php) is no longer registered
 * but the class remains for backward compatibility with its static methods.
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
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.chat_client' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.custom_filters' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.providers' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.authentication' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.tools' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.orchestration' ) );
		// External Tools (Gmail, Crawl4AI, Brave, Cloudflare, etc.) are now consolidated in integrations section.
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.integrations' ) );
		// Plugin integrations (JetEngine, WooCommerce, Elementor) are now consolidated in a single section.
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.plugins_integration' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.token_manager' ) );
		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.security' ) );

		// Performance section is only available with Pro addon.
		$performance_section = $container->get( 'section.performance' );
		if ( null !== $performance_section ) {
			WP_MCP_AI_Settings_Registry::register_section( $performance_section );
		}

		WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.advanced' ) );
		// Media, Comments, and Site Creator sections are now integrated as sub-tabs within the Tools section..
		// WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.media' ) );.

		// WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.comments' ) );.

		// WP_MCP_AI_Settings_Registry::register_section( $container->get( 'section.site_creator' ) );.

		// Initialize the dashboard controller.
		// This creates the top-level "WP oOS" menu item.
		// Store the instance globally for potential access by other code.
		$GLOBALS['wp_mcp_ai_settings_dashboard'] = $container->get( 'admin.settings_dashboard' );

		// Initialize integration admin pages.
		// Note: Plugin integrations (JetEngine, WooCommerce, Elementor) now use sections instead of standalone page.
		// Note: External tools integration (Gmail, Crawl4AI, etc.) now uses sections instead of standalone page.
		// $GLOBALS['wp_mcp_ai_admin_plugins']     = $container->get( 'admin.plugins_integration' );.

		// $GLOBALS['wp_mcp_ai_admin_gmail_crawl'] = $container->get( 'admin.gmail_crawl_integration' );.

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
