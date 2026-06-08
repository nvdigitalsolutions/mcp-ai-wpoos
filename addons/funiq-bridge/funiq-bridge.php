<?php
/**
 * Plugin Name: Funiq Bridge
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: Bridges the Funiq React PWA frontend (built for Payload CMS) to a WordPress backend. Provides Payload-compatible REST API endpoints, Custom Post Types for e-commerce data (products, categories, brands, colors, statuses, promotions, promocodes), global banner/carousel settings, and an embedded React SPA admin panel that replicates Payload's admin experience inside WordPress.
 * Version:     1.0.0
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: Proprietary
 * License URI: https://nvdigitalsolutions.com/wpoos/license
 * Text Domain: funiq-bridge
 * Domain Path: /languages
 *
 * @package FuniqBridge
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
define( 'FUNIQ_BRIDGE_VERSION', '1.0.0' );

/** Absolute path to this plugin file. */
define( 'FUNIQ_BRIDGE_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'FUNIQ_BRIDGE_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'FUNIQ_BRIDGE_URL', plugin_dir_url( __FILE__ ) );

// ---------------------------------------------------------------------------
// PSR-4 autoloader for the FuniqBridge namespace.
// ---------------------------------------------------------------------------
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'FuniqBridge\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = FUNIQ_BRIDGE_PATH . 'includes/'
			. str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

// ---------------------------------------------------------------------------
// Schema — central constants shared across the entire addon.
// ---------------------------------------------------------------------------
require_once FUNIQ_BRIDGE_PATH . 'includes/Schema.php';

// ---------------------------------------------------------------------------
// Bootstrap — defer to plugins_loaded so WP core and base plugin are ready.
// ---------------------------------------------------------------------------
require_once FUNIQ_BRIDGE_PATH . 'includes/Plugin.php';

add_action(
	'plugins_loaded',
	static function (): void {
		FuniqBridge\Plugin::init();
	},
	30  // After base plugin (20) and pro addon (15).
);

// ---------------------------------------------------------------------------
// Activation / deactivation hooks.
// ---------------------------------------------------------------------------
register_activation_hook(
	FUNIQ_BRIDGE_FILE,
	static function (): void {
		// Flush rewrite rules so CPT/taxonomy permalinks work.
		FuniqBridge\Plugin::activate();
	}
);

register_deactivation_hook(
	FUNIQ_BRIDGE_FILE,
	static function (): void {
		FuniqBridge\Plugin::deactivate();
	}
);
