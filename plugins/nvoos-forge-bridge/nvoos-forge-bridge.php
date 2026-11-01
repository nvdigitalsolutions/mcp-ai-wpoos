<?php
/**
 * Plugin Name: NV oOS Forge Bridge
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: Rebuild webhook + preview tokens for sites generated with the NV oOS Astro Forge. Fires a signed GitHub repository_dispatch when content changes (debounced), verifies inbound rebuild triggers with HMAC, and mints single-use preview tokens.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-forge-bridge
 * Domain Path: /languages
 *
 * @package NvoosForgeBridge
 *
 * Copyright (c) 2026 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU General Public License v3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
define( 'NVOOS_FORGE_BRIDGE_VERSION', '0.1.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_FORGE_BRIDGE_FILE', __FILE__ );

/** Absolute path to the plugin directory. */
define( 'NVOOS_FORGE_BRIDGE_PATH', plugin_dir_path( __FILE__ ) );

/** URL of the plugin directory. */
define( 'NVOOS_FORGE_BRIDGE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoload NvoosForgeBridge\{Short_Name} classes from
 * includes/class-nvoos-forge-bridge-{short-name}.php.
 *
 * @param string $class Fully-qualified class name.
 * @return void
 */
spl_autoload_register(
	function ( $fqcn ) {
		$prefix = 'NvoosForgeBridge\\';
		if ( 0 !== strpos( $fqcn, $prefix ) ) {
			return;
		}
		$relative = substr( $fqcn, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$short    = array_pop( $parts );
		$file     = 'class-nvoos-forge-bridge-' . strtolower( str_replace( '_', '-', $short ) ) . '.php';
		$dirs     = array_map(
			function ( $part ) {
				return strtolower( str_replace( '_', '-', $part ) );
			},
			$parts
		);
		$path     = NVOOS_FORGE_BRIDGE_PATH . 'includes/' . implode( '/', $dirs );
		if ( $dirs ) {
			$path .= '/';
		}
		$path .= $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

// Boot on plugins_loaded — idempotent and safe when the file is loaded late
// (the bundle/fleet loaders can include plugins after init already fired).
add_action( 'plugins_loaded', array( 'NvoosForgeBridge\\Plugin', 'init' ) );
if ( did_action( 'plugins_loaded' ) ) {
	NvoosForgeBridge\Plugin::init();
}
