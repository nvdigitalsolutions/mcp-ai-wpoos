<?php
/**
 * Admin page — registers the WordPress admin menu page and enqueues
 * the React SPA that replicates Payload's admin experience.
 *
 * @package NvoosAstroBridge\Admin
 */

namespace NvoosAstroBridge\Admin;

use NvoosAstroBridge\Schema;

/**
 * Registers the "Astro CMS" top-level admin page and enqueues the SPA assets.
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
			'Astro CMS',
			'Astro CMS',
			Schema::CAP_MANAGE,
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
		echo '<div id="astro-admin-root"></div>';
	}

	/**
	 * Enqueue the compiled React SPA assets.
	 *
	 * Only loads on the Astro CMS admin page.
	 *
	 * The STANDALONE bundle is used deliberately: it bundles React and the
	 *
	 * @wordpress/* packages from node_modules, so it does not depend on the
	 * wp-admin script chain. The externals-based wp-admin build (build/index.js)
	 * renders broken tables under WP 7.1+ — its JSX runtime shim captures
	 * window.React.createElement, and the field incident of 2026-11-03 showed
	 * text-only rows (no <td> elements) in exactly that environment. The
	 * standalone bundle is the same artifact the /manage surface ships and is
	 * proven against the live backend.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . Schema::ADMIN_PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$js_path  = NVOOS_ASTRO_BRIDGE_PATH . 'build/standalone/astro-admin.js';
		$css_path = NVOOS_ASTRO_BRIDGE_PATH . 'build/standalone/astro-admin.css';

		if ( file_exists( $js_path ) ) {
			wp_enqueue_script(
				'astro-admin',
				NVOOS_ASTRO_BRIDGE_URL . 'build/standalone/astro-admin.js',
				array(),
				filemtime( $js_path ),
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'astro-admin',
				NVOOS_ASTRO_BRIDGE_URL . 'build/standalone/astro-admin.css',
				array(),
				filemtime( $css_path )
			);
		}

		// Pass config to the SPA. The root is the absolute backend REST URL in
		// wp-admin; the standalone /manage surface on the storefront supplies
		// its own relative config (see the astro app's ManageGate island).
		wp_add_inline_script(
			'astro-admin',
			'window.AstroAdminConfig = ' . wp_json_encode(
				array(
					'root'      => esc_url_raw( rest_url( Schema::REST_NAMESPACE . '/' ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'mediaBase' => '',
				)
			) . ';',
			'before'
		);
	}
}
