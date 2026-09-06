<?php
/**
 * Pro SPA v2 Loader — Registers the React Single Page Application admin page
 * and enqueues the TypeScript/esbuild SPA assets.
 *
 * The SPA v2 replaces the legacy webpack-based Pro SPA with a modern
 * TypeScript + esbuild + React 19 + AI SDK architecture, mirroring the
 * chat-spa addon's patterns.
 *
 * Runtime config assembly is delegated to WP_MCP_AI_Pro_SPA_Config so the
 * admin page and the [nvoos_pro_spa] front-end shortcode share one source
 * of truth for the NVOOS_PRO_SPA global.
 *
 * @package NV_oOS_Pro
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_SPA_Loader
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Pro_SPA_Loader {

	/**
	 * Admin page hook suffix.
	 *
	 * @since 1.7.0
	 * @var string|null
	 */
	private $hook_suffix = null;

	/**
	 * Register the SPA admin menu page and enqueue hooks.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ), 20 );
	}

	/**
	 * Add the SPA admin page as a top-level menu item.
	 *
	 * Registered with a different slug than the base plugin's admin page
	 * so both can coexist.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function add_admin_page() {
		$this->hook_suffix = add_menu_page(
			__( 'NV oOS AI', 'mcp-ai-wpoos' ),
			__( 'NV oOS AI', 'mcp-ai-wpoos' ),
			'read',
			'wp-mcp-ai-spa',
			array( $this, 'render' ),
			'dashicons-superhero',
			30
		);

		add_action( 'load-' . $this->hook_suffix, array( $this, 'enqueue' ) );
	}

	/**
	 * Render the SPA root div.
	 *
	 * The SPA entry point (index.tsx) mounts into #wp-mcp-ai-pro-spa-root
	 * or any element with [data-config] attribute for multi-instance support.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function render() {
		echo '<div id="wp-mcp-ai-pro-spa-root"></div>';
	}

	/**
	 * Enqueue SPA JavaScript and CSS assets.
	 *
	 * Loads the esbuild-built IIFE bundle (pro-spa.js / pro-spa.css) and
	 * passes the NVOOS_PRO_SPA runtime configuration via wp_localize_script.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function enqueue() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_SPA_Config' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-spa-config.php';
		}

		if ( ! WP_MCP_AI_Pro_SPA_Config::register_assets() ) {
			return;
		}

		$runtime = WP_MCP_AI_Pro_SPA_Config::build(
			array(
				'mode'                  => 'admin',
				'theme'                 => 'auto',
				'allow_sensitive_tools' => true,
			)
		);

		WP_MCP_AI_Pro_SPA_Config::enqueue( $runtime );
	}
}
