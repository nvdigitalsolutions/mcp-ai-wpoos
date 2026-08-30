<?php
/**
 * Plugin Name: NV oOS Checkout API
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: Vendor-side checkout service for NV oOS premium addons. Hosts the Stripe payment session/verify endpoints, issues licenses, serves signed ZIP downloads, and processes Stripe webhooks. Runs on the vendor's own server only — never distributed to customers or WordPress.org.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: Proprietary — NV Digital Solutions, All Rights Reserved
 * License URI: https://nvdigitalsolutions.com/wpoos/license/checkout-api
 * Text Domain: nvoos-checkout-api
 * Domain Path: /languages
 *
 * @package NV_oOS_Checkout_API
 *
 * ⚠️ PROPRIETARY SOFTWARE
 * This is commercial software licensed for authorized users only.
 * © 2026 NV Digital Solutions - All Rights Reserved
 *
 * Copyright (c) 2026 NV Digital Solutions (https://nvdigitalsolutions.com).
 * All rights reserved.
 *
 * This addon is PROPRIETARY software of NV Digital Solutions. It is NOT
 * licensed under the GPL that covers the rest of the NV oOS repository,
 * and it is NOT distributed via WordPress.org. Use, reproduction, modification,
 * and redistribution are governed by the addon-local `LICENSE` file shipped in
 * this directory. See `LICENSE` for the full terms.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
define( 'NVOOS_CHECKOUT_API_VERSION', '0.1.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_CHECKOUT_API_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_CHECKOUT_API_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_CHECKOUT_API_URL', plugin_dir_url( __FILE__ ) );

require_once NVOOS_CHECKOUT_API_PATH . 'includes/class-nvoos-checkout-api-settings.php';
require_once NVOOS_CHECKOUT_API_PATH . 'includes/class-nvoos-checkout-api-license-store.php';
require_once NVOOS_CHECKOUT_API_PATH . 'includes/class-nvoos-checkout-api-token.php';
require_once NVOOS_CHECKOUT_API_PATH . 'includes/class-nvoos-checkout-api-rate-limiter.php';
require_once NVOOS_CHECKOUT_API_PATH . 'includes/class-nvoos-checkout-api-stripe-client.php';
require_once NVOOS_CHECKOUT_API_PATH . 'includes/class-nvoos-checkout-api-download-server.php';
require_once NVOOS_CHECKOUT_API_PATH . 'includes/class-nvoos-checkout-api-rest-controller.php';
require_once NVOOS_CHECKOUT_API_PATH . 'includes/admin/class-nvoos-checkout-api-admin-page.php';
require_once NVOOS_CHECKOUT_API_PATH . 'includes/class-nvoos-checkout-api-plugin.php';

/**
 * Install the licenses table on activation.
 *
 * @since 0.1.0
 *
 * @return void
 */
function nvoos_checkout_api_activate(): void {
	NVOOS_Checkout_API_License_Store::install_table();
}

register_activation_hook( __FILE__, 'nvoos_checkout_api_activate' );

/**
 * Boot the plugin once WordPress is ready.
 *
 * @since 0.1.0
 *
 * @return void
 */
function nvoos_checkout_api_boot(): void {
	NVOOS_Checkout_API_Plugin::init();
}
add_action( 'plugins_loaded', 'nvoos_checkout_api_boot', 20 );
