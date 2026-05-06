<?php
/**
 * Asset enqueue for the NV oOS SaaS Controller admin UI.
 *
 * The React wizard bundle (`assets/build/index.js`) is enqueued *only* on the
 * `NV oOS SaaS` admin screen — never globally — to avoid colliding with
 * unrelated WP-Admin pages and to keep the WordPress.org compliance promise
 * that the addon makes no JS demands outside its own pages.
 *
 * Build hash is read from `assets/build/index.asset.php` (the standard
 * `@wordpress/scripts` output) which lists the WP `@wordpress/*` external
 * dependencies and a content-hashed cache buster. When the file is absent —
 * e.g. on a developer checkout that has not yet run `npm run build` — we
 * fall back to a no-op enqueue and surface an admin notice so the operator
 * knows the JS layer is not active.
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asset registrar.
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_Assets {

	/**
	 * Handle used for the bundled React script.
	 *
	 * @var string
	 */
	const SCRIPT_HANDLE = 'nvoos-saas-controller-wizard';

	/**
	 * Handle used for the (optional) bundled stylesheet.
	 *
	 * @var string
	 */
	const STYLE_HANDLE = 'nvoos-saas-controller-wizard';

	/**
	 * Register hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	/**
	 * Enqueue the React bundle when on this addon's admin screen.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook Current admin page hook (e.g. "toplevel_page_nvoos-saas-controller").
	 * @return void
	 */
	public static function maybe_enqueue( $hook ) {
		if ( 'toplevel_page_' . NVOOS_SaaS_Controller_Admin_Page::PAGE_SLUG !== $hook ) {
			return;
		}

		// Always available on this admin page — needed by inline scripts (e.g. the
		// Deployment tab's "Run Plan" button) even when the React bundle is absent.
		wp_enqueue_script( 'wp-api-fetch' );

		$build_dir  = NVOOS_SAAS_CONTROLLER_PATH . 'assets/build/';
		$build_url  = NVOOS_SAAS_CONTROLLER_URL . 'assets/build/';
		$asset_file = $build_dir . 'index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			// No JS bundle yet — surface a single, dismissible notice on this page.
			add_action( 'admin_notices', array( __CLASS__, 'render_missing_bundle_notice' ) );
			return;
		}

		$asset = include $asset_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		if ( ! is_array( $asset ) || empty( $asset['version'] ) ) {
			return;
		}

		$dependencies = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] )
			? $asset['dependencies']
			: array();

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$build_url . 'index.js',
			$dependencies,
			(string) $asset['version'],
			true
		);

		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'nvoos-saas-controller'
		);

		// Localised runtime config — REST root, nonce, and the credential-key
		// allowlist so the wizard never invents a key the backend rejects.
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'nvoosSaasController',
			array(
				'restRoot'       => esc_url_raw( rest_url( NVOOS_SaaS_Controller_REST::NAMESPACE . '/' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'credentialKeys' => NVOOS_SaaS_Controller_Credential_Store::ALLOWED_KEYS,
				'addonVersion'   => defined( 'NVOOS_SAAS_CONTROLLER_VERSION' ) ? NVOOS_SAAS_CONTROLLER_VERSION : 'dev',
			)
		);

		if ( file_exists( $build_dir . 'index.css' ) ) {
			wp_enqueue_style(
				self::STYLE_HANDLE,
				$build_url . 'index.css',
				array( 'wp-components' ),
				(string) $asset['version']
			);
		}
	}

	/**
	 * Render an admin notice when the JS bundle is missing.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render_missing_bundle_notice() {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__(
			'NV oOS SaaS Controller: the React wizard bundle has not been built. Run `npm ci && npm run build` in addons/saas-controller/ to enable the interactive wizard. The page works without JavaScript — only the wizard is unavailable.',
			'nvoos-saas-controller'
		);
		echo '</p></div>';
	}
}
