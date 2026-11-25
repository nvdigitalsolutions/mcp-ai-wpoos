<?php
/**
 * Composition root.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composition root for the checkout API addon.
 *
 * Wires the REST controller, the download server, and the admin page.
 *
 * @since 0.1.0
 */
class NVOOS_Checkout_API_Plugin {

	/**
	 * Boot the addon (called once on plugins_loaded).
	 *
	 * @return void
	 */
	public static function init(): void {
		NVOOS_Checkout_API_License_Store::install_table();

		add_action( 'init', array( __CLASS__, 'register_license_rewrites' ) );

		add_action(
			'rest_api_init',
			static function (): void {
				$controller = new NVOOS_Checkout_API_Rest_Controller();
				$controller->register_routes();
			}
		);

		NVOOS_Checkout_API_Download_Server::register();

		if ( is_admin() ) {
			NVOOS_Checkout_API_Admin_Page::register();
		}
	}

	/**
	 * Expose the license endpoints at the paths both clients already use.
	 *
	 * The WordPress Pro plugin (`class-wp-mcp-ai-pro-license.php`) and the
	 * Laravel runtime (`LicensedProEntitlement`) post form data to
	 * `{server}/api/licenses/check` and `{server}/api/licenses/activate`,
	 * where `{server}` defaults to `https://nvdigitalsolutions.com/api/licenses`.
	 * Those paths are not a WordPress REST namespace, so they 404 unless the
	 * request is rewritten onto the namespaced route. Rewriting here keeps both
	 * clients unchanged and keeps the endpoint with the store that issues keys.
	 *
	 * Run on `init` (rules persist in the rewrite cache) and again from the
	 * activation hook right before `flush_rewrite_rules()`.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function register_license_rewrites(): void {
		/**
		 * Filter whether the legacy `/api/licenses/*` paths are served.
		 *
		 * Turn this off when a dedicated license-server plugin owns those paths.
		 *
		 * @since 0.2.0
		 *
		 * @param bool $enabled Whether to register the rewrites.
		 */
		if ( ! apply_filters( 'nvoos_checkout_license_legacy_paths', true ) ) {
			return;
		}

		add_rewrite_rule(
			'^api/licenses/check/?$',
			'index.php?rest_route=/nvoos-checkout/v1/license/check',
			'top'
		);
		add_rewrite_rule(
			'^api/licenses/activate/?$',
			'index.php?rest_route=/nvoos-checkout/v1/license/activate',
			'top'
		);
	}
}
