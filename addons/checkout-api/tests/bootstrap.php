<?php
/**
 * PHPUnit bootstrap for the NV oOS Checkout API addon.
 *
 * Loaded via the addon's phpunit.xml.dist. The WordPress test environment
 * itself is bootstrapped by the test-suite config; this file's job is to
 * register the addon's PHP files with the running WordPress instance.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Allow the file to be loaded from a phpunit.xml that bootstraps the
	// WordPress test environment first.
	return;
}

if ( ! defined( 'NVOOS_CHECKOUT_API_PATH' ) ) {
	define( 'NVOOS_CHECKOUT_API_PATH', dirname( __DIR__ ) . '/' );
}

require_once NVOOS_CHECKOUT_API_PATH . 'includes/class-nvoos-checkout-api-settings.php';
require_once NVOOS_CHECKOUT_API_PATH . 'includes/class-nvoos-checkout-api-license-store.php';
require_once NVOOS_CHECKOUT_API_PATH . 'includes/class-nvoos-checkout-api-token.php';
require_once NVOOS_CHECKOUT_API_PATH . 'includes/class-nvoos-checkout-api-rate-limiter.php';
require_once NVOOS_CHECKOUT_API_PATH . 'includes/class-nvoos-checkout-api-stripe-client.php';
require_once NVOOS_CHECKOUT_API_PATH . 'includes/class-nvoos-checkout-api-download-server.php';
require_once NVOOS_CHECKOUT_API_PATH . 'includes/class-nvoos-checkout-api-rest-controller.php';
require_once NVOOS_CHECKOUT_API_PATH . 'includes/admin/class-nvoos-checkout-api-admin-page.php';
require_once NVOOS_CHECKOUT_API_PATH . 'includes/class-nvoos-checkout-api-plugin.php';
