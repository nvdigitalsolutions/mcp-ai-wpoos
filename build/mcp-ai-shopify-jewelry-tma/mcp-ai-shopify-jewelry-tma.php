<?php
/**
 * Plugin Name:       NV oOS – Shopify Jewelry TMA
 * Plugin URI:        https://nvdigitalsolutions.com
 * Description:       Adds a "Shopify Jewelry Shop" Telegram Mini App template to the NV Open Operator System Pro plugin. Provides a gold-themed React SPA with product catalog, cart, checkout and AI concierge backed by a Shopify store via Remote Sites.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NV Digital Solutions
 * Author URI:        https://nvdigitalsolutions.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mcp-ai-shopify-jewelry-tma
 *
 * @package WP_MCP_AI_Shopify_Jewelry_TMA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Minimum NV oOS Pro version required.
define( 'WP_MCP_AI_SJ_TMA_MIN_PRO_VERSION', '1.1.3' );
define( 'WP_MCP_AI_SJ_TMA_VERSION', '1.0.0' );
define( 'WP_MCP_AI_SJ_TMA_FILE', __FILE__ );
define( 'WP_MCP_AI_SJ_TMA_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_MCP_AI_SJ_TMA_URL', plugin_dir_url( __FILE__ ) );

/**
 * Boot the addon once plugins are loaded.
 */
add_action( 'plugins_loaded', 'wp_mcp_ai_sj_tma_init', 20 );

/**
 * Initialise the addon.
 *
 * Defers registration until after the Pro plugin has booted so that the TMA
 * template registry and base class are available.
 */
function wp_mcp_ai_sj_tma_init() {
	// Require the Pro plugin to be active.
	if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
		add_action( 'admin_notices', 'wp_mcp_ai_sj_tma_notice_pro_missing' );
		return;
	}

	// Minimum version check.
	if ( version_compare( WP_MCP_AI_PRO_VERSION, WP_MCP_AI_SJ_TMA_MIN_PRO_VERSION, '<' ) ) {
		add_action( 'admin_notices', 'wp_mcp_ai_sj_tma_notice_pro_outdated' );
		return;
	}

	// Load the template class and register it via the hook.
	require_once WP_MCP_AI_SJ_TMA_DIR . 'includes/class-tma-template-jewelry-shop.php';

	add_action(
		'wp_mcp_ai_tma_templates_registered',
		'wp_mcp_ai_sj_tma_register_template',
		10,
		1
	);
}

/**
 * Register the Jewelry Shop template with the TMA template registry.
 *
 * @param WP_MCP_AI_Telegram_Mini_App_Template_Registry $registry Template registry instance.
 */
function wp_mcp_ai_sj_tma_register_template( $registry ) {
	if ( class_exists( 'WP_MCP_AI_TMA_Template_Jewelry_Shop' ) ) {
		$registry->register( new WP_MCP_AI_TMA_Template_Jewelry_Shop() );
	}
}

// ── Admin notices ─────────────────────────────────────────────────────────

/**
 * Admin notice: NV oOS Pro is not active.
 */
function wp_mcp_ai_sj_tma_notice_pro_missing() {
	?>
	<div class="notice notice-error">
		<p>
			<strong>NV oOS – Shopify Jewelry TMA</strong>:
			<?php esc_html_e( 'This addon requires the NV oOS Pro plugin to be active.', 'mcp-ai-shopify-jewelry-tma' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Admin notice: NV oOS Pro is outdated.
 */
function wp_mcp_ai_sj_tma_notice_pro_outdated() {
	?>
	<div class="notice notice-warning">
		<p>
			<strong>NV oOS – Shopify Jewelry TMA</strong>:
			<?php
			printf(
				/* translators: %s: minimum required Pro version */
				esc_html__( 'This addon requires NV oOS Pro version %s or higher.', 'mcp-ai-shopify-jewelry-tma' ),
				esc_html( WP_MCP_AI_SJ_TMA_MIN_PRO_VERSION )
			);
			?>
		</p>
	</div>
	<?php
}
