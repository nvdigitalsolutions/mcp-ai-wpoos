<?php
/**
 * Plugin Name: Astro Bridge
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: Serves the nvoos-commerce/v1 Payload-compatible commerce envelope — products, categories, brands, colors, statuses, promotions, promocodes, banner, carousel, auth, managers and media — from WordPress + WooCommerce for the NV Astro storefront, with the embedded Astro CMS admin.
 * Version:     1.0.1
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: Proprietary
 * License URI: https://nvdigitalsolutions.com/wpoos/license
 * Text Domain: nvoos-astro-bridge
 * Domain Path: /languages
 *
 * @package NvoosAstroBridge
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com).
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
if ( ! defined( 'NVOOS_ASTRO_BRIDGE_VERSION' ) ) {
	define( 'NVOOS_ASTRO_BRIDGE_VERSION', '1.0.1' );
}

/** Absolute path to this plugin file. */
if ( ! defined( 'NVOOS_ASTRO_BRIDGE_FILE' ) ) {
	define( 'NVOOS_ASTRO_BRIDGE_FILE', __FILE__ );
}

/** Absolute path to this plugin directory (trailing slash). */
if ( ! defined( 'NVOOS_ASTRO_BRIDGE_PATH' ) ) {
	define( 'NVOOS_ASTRO_BRIDGE_PATH', plugin_dir_path( __FILE__ ) );
}

/** URL to this plugin directory (trailing slash). */
if ( ! defined( 'NVOOS_ASTRO_BRIDGE_URL' ) ) {
	define( 'NVOOS_ASTRO_BRIDGE_URL', plugin_dir_url( __FILE__ ) );
}

// ---------------------------------------------------------------------------
// PSR-4 autoloader for the NvoosAstroBridge namespace.
// ---------------------------------------------------------------------------
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'NvoosAstroBridge\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = NVOOS_ASTRO_BRIDGE_PATH . 'includes/'
			. str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

// ---------------------------------------------------------------------------
// Schema — central constants shared across the entire addon.
// ---------------------------------------------------------------------------
require_once NVOOS_ASTRO_BRIDGE_PATH . 'includes/Schema.php';

// ---------------------------------------------------------------------------
// Bootstrap — defer to plugins_loaded so WP core and base plugin are ready.
// ---------------------------------------------------------------------------
require_once NVOOS_ASTRO_BRIDGE_PATH . 'includes/Plugin.php';

add_action(
	'plugins_loaded',
	static function (): void {
		NvoosAstroBridge\Plugin::init();
	},
	31  // After base plugin (20) and pro addon (15).
);

// Fleet bootstrap — on the Cloudways fleet the plugins_loaded priority-30
// bucket is consumed before registered callbacks run (observed live on
// nvoos.pro, 2026-10-29), so the closure above can sit unfired. init is the
// reliable fleet hook; Plugin::init() is idempotent, so both paths are safe
// and the first one to fire wins.
add_action(
	'init',
	static function (): void {
		NvoosAstroBridge\Plugin::init();
	},
	9  // Before the init:11 CPT/taxonomy registration hooks.
);

// ---------------------------------------------------------------------------
// Late-load fallback.
//
// When a loader includes this file AFTER plugins_loaded has fired (e.g. an
// addon-manager that loads addon plugins mid-request), the closure above is
// registered on an already-fired hook and never runs — the plugin would load
// without registering its post types, taxonomies, or REST routes. Init is
// idempotent (Plugin::$initialized guard), so calling it directly here is
// safe in both the normal and the late-load path.
// ---------------------------------------------------------------------------
if ( did_action( 'plugins_loaded' ) ) {
	NvoosAstroBridge\Plugin::init();
	$astro_bridge = NvoosAstroBridge\Plugin::instance();
	if ( did_action( 'init' ) ) {
		$astro_bridge->register_post_types();
		$astro_bridge->register_taxonomies();
	}
	if ( did_action( 'rest_api_init' ) ) {
		$astro_bridge->register_rest_routes();
	}
}

// ---------------------------------------------------------------------------
// Activation / deactivation hooks.
// ---------------------------------------------------------------------------
register_activation_hook(
	NVOOS_ASTRO_BRIDGE_FILE,
	static function (): void {
		// Flush rewrite rules so CPT/taxonomy permalinks work.
		NvoosAstroBridge\Plugin::activate();
	}
);

register_deactivation_hook(
	NVOOS_ASTRO_BRIDGE_FILE,
	static function (): void {
		NvoosAstroBridge\Plugin::deactivate();
	}
);
