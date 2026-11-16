<?php
/**
 * Plugin Name: NV oOS WiFi
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: AI-powered social WiFi and Wi-Fi observability — branded captive portal, per-purpose consent, device-identity-aware sessions, standards-shaped KPIs (Wi-Fi Data Elements) and an MCP tool surface. Portable by design: the domain layer also runs in the NV oOS Laravel runtime.
 * Version:     0.1.0
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-wifi
 * Domain Path: /languages
 *
 * @package NvoosWifi
 *
 * Copyright (c) 2026 NV Digital Solutions (https://nvdigitalsolutions.com).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
if ( ! defined( 'NVOOS_WIFI_VERSION' ) ) {
	define( 'NVOOS_WIFI_VERSION', '0.1.0' );
}

/** Absolute path to this plugin file. */
if ( ! defined( 'NVOOS_WIFI_FILE' ) ) {
	define( 'NVOOS_WIFI_FILE', __FILE__ );
}

/** Absolute path to this plugin directory (trailing slash). */
if ( ! defined( 'NVOOS_WIFI_PATH' ) ) {
	define( 'NVOOS_WIFI_PATH', plugin_dir_path( __FILE__ ) );
}

/** URL to this plugin directory (trailing slash). */
if ( ! defined( 'NVOOS_WIFI_URL' ) ) {
	define( 'NVOOS_WIFI_URL', plugin_dir_url( __FILE__ ) );
}

// ---------------------------------------------------------------------------
// PSR-4 autoloader for the NvoosWifi namespace.
//
// Composer is optional here: the addon is shipped as a plugin directory, not
// installed into vendor/, so a manual autoloader keeps it working in a plain
// WordPress install.
//
// Note the paths this autoloader will *never* resolve:
//   includes/Domain/**, includes/Interfaces/**, includes/Services/**
// are pure PHP with no WordPress dependency — that is the point of the layering
// (ADR 001), and it is what lets the Laravel host load them directly.
// ---------------------------------------------------------------------------
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'NvoosWifi\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = NVOOS_WIFI_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

// ---------------------------------------------------------------------------
// Tool registration — file scope, not plugins_loaded.
//
// `includes/tools-init.php` in the base plugin registers its side-loaded tools
// the same way. The registry may apply `wp_mcp_ai_default_tools` before a late
// `plugins_loaded` callback runs, so deferring this would silently drop the
// tool. Missing this is the number-one "my tools don't appear" cause.
// ---------------------------------------------------------------------------
require_once NVOOS_WIFI_PATH . 'includes/Plugin.php';

add_filter( 'wp_mcp_ai_default_tools', array( '\NvoosWifi\Plugin', 'register_tools' ) );

// ---------------------------------------------------------------------------
// Bootstrap — defer the remaining hooks so core and the base plugin are ready.
// ---------------------------------------------------------------------------
add_action( 'plugins_loaded', array( '\NvoosWifi\Plugin', 'init' ), 31 );
