<?php
/**
 * E-commerce Toolkit Initialization
 *
 * Loads the E-commerce Toolkit system for advanced WooCommerce integration
 * including product management, order processing, inventory tracking, and
 * customer management.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if e-commerce toolkit is enabled.
 *
 * The toolkit must be explicitly enabled in settings (Pro features).
 *
 * @since 2.1.0
 *
 * @return bool True if enabled, false otherwise.
 */
function wp_mcp_ai_is_ecommerce_toolkit_enabled() {
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	return ! empty( $settings['enable_ecommerce_toolkit'] );
}

// Load E-commerce admin pages when in admin area.
if ( is_admin() ) {
	// Check if e-commerce toolkit is enabled and not in base version (unless Pro addon is active).
	$settings      = get_option( 'wp_mcp_ai_settings', array() );
	$is_enabled    = ! empty( $settings['enable_ecommerce_toolkit'] );
	$is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
	$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );
	$has_wc        = class_exists( 'WooCommerce' );

	if ( $is_enabled && ( ! $is_base || $is_pro_active ) && $has_wc ) {
		// Load E-commerce Toolkit Settings page.
		if ( ! class_exists( 'WP_MCP_AI_Ecommerce_Settings_Page' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-ecommerce-settings-page.php';
			new WP_MCP_AI_Ecommerce_Settings_Page();
		}

		// Load Product Research & Add page for WooCommerce integration.
		if ( ! class_exists( 'WP_MCP_AI_Product_Research_Page' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-product-research-page.php';
			WP_MCP_AI_Product_Research_Page::init();
		}

		// Load Product Consolidate & Add page for data import/validation.
		if ( ! class_exists( 'WP_MCP_AI_Product_Consolidate_Page' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-product-consolidate-page.php';
			WP_MCP_AI_Product_Consolidate_Page::init();
		}

		// Load Product Settings page (tab-based interface).
		if ( ! class_exists( 'WP_MCP_AI_Product_Settings_Page' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-product-settings-page.php';
			new WP_MCP_AI_Product_Settings_Page();
		}

		// Register tools will be loaded automatically via the tools directory structure.
		// Tools are located in: addons/pro/includes/tools/ecommerce/.
	}
}

/**
 * Enqueue e-commerce toolkit admin styles.
 *
 * @param string $hook Current admin page hook.
 */
function wp_mcp_ai_enqueue_ecommerce_toolkit_admin_styles( $hook ) {
	// Only load if toolkit is enabled.
	if ( ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
		return;
	}

	// Check if we're on a relevant admin page.
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->id, array( 'product', 'shop_order' ), true ) ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-ecommerce-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-ecommerce-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-ecommerce-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_ecommerce_toolkit_admin_styles' );
