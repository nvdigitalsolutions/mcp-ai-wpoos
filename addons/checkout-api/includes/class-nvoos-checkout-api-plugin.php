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
}
