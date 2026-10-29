<?php
/**
 * Admin page — registers the WordPress admin menu page and enqueues
 * the React SPA that replicates Payload's admin experience.
 *
 * @package FuniqBridge\Admin
 */

namespace FuniqBridge\Admin;

use FuniqBridge\Schema;

/**
 * Registers the "Funiq CMS" top-level admin page and enqueues the SPA assets.
 */
class AdminPage {

	/**
	 * Bootstrap.
	 *
	 * @return void
	 */
	public static function init(): void {
		$instance = new self();
		add_action( 'admin_menu', array( $instance, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $instance, 'enqueue_assets' ) );
	}

	/**
	 * Add the top-level admin menu page.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			'Funiq CMS',
			'Funiq CMS',
			Schema::CAP_MANAGE_FUNIQ,
			Schema::ADMIN_PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-store',
			25
		);
	}

	/**
	 * Render the React SPA mount point.
	 *
	 * @return void
	 */
	public function render(): void {
		echo '<div id="funiq-admin-root"></div>';
	}

	/**
	 * Enqueue the compiled React SPA assets.
	 *
	 * Only loads on the Funiq CMS admin page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . Schema::ADMIN_PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$js_path  = FUNIQ_BRIDGE_PATH . 'build/index.js';
		$css_path = FUNIQ_BRIDGE_PATH . 'build/index.css';

		if ( file_exists( $js_path ) ) {
			wp_enqueue_script(
				'funiq-admin',
				FUNIQ_BRIDGE_URL . 'build/index.js',
				array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-block-editor', 'wp-i18n', 'wp-data' ),
				filemtime( $js_path ),
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'funiq-admin',
				FUNIQ_BRIDGE_URL . 'build/index.css',
				array( 'wp-components' ),
				filemtime( $css_path )
			);
		}

		// Bridge the esbuild IIFE externals to the WordPress-provided globals.
		// esbuild (format iife) emits a `require` shim for every external — in
		// a browser that shim throws "Dynamic require of X is not supported"
		// unless a global `require` exists. The script deps above guarantee
		// window.wp.* / window.React / window.ReactDOM are present.
		wp_add_inline_script(
			'funiq-admin',
			'window.require = window.require || function ( name ) {'
			. ' var map = {'
			. '  "@wordpress/element": window.wp.element,'
			. '  "@wordpress/components": window.wp.components,'
			. '  "@wordpress/api-fetch": window.wp.apiFetch,'
			. '  "@wordpress/block-editor": window.wp.blockEditor,'
			. '  "@wordpress/i18n": window.wp.i18n,'
			. '  "@wordpress/data": window.wp.data,'
			. '  react: window.React,'
			. '  "react-dom": window.ReactDOM'
			. ' };'
			. ' return map[ name ] || map[ name.split( "/" ).pop() ];'
			. '};',
			'before'
		);

		// Pass config to the SPA.
		wp_add_inline_script(
			'funiq-admin',
			'window.FuniqAdminConfig = ' . wp_json_encode(
				array(
					'root'  => esc_url_raw( rest_url( Schema::REST_NAMESPACE . '/' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);
	}
}
