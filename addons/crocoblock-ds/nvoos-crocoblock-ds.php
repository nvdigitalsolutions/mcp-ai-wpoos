<?php
/**
 * Plugin Name: NV oOS Crocoblock Design System
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: Design token system for Crocoblock suite — unified CSS custom
 *              properties, preset templates, and admin-controlled theming for
 *              JetEngine, JetSmartFilters, and JetFormBuilder.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-crocoblock-ds
 * Domain Path: /languages
 *
 * @package NV_oOS_Crocoblock_DS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version — keep in sync with the header above. */
define( 'NVOOS_CROCOBLOCK_DS_VERSION', '0.1.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_CROCOBLOCK_DS_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_CROCOBLOCK_DS_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_CROCOBLOCK_DS_URL', plugin_dir_url( __FILE__ ) );

// ---------------------------------------------------------------------------
// Manual autoloader (PSR-4–style; no Composer dependency for Phase 1).
// ---------------------------------------------------------------------------

spl_autoload_register(
	function ( $class ) {
		$prefix = 'NV_oOS_Crocoblock_DS_';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}

		$relative = str_replace( $prefix, '', $class );
		$relative = str_replace( '_', '-', $relative );
		$relative = strtolower( $relative );

		// Map type-hint prefixes to subdirectories.
		$subdirs = array( 'data-', 'integration-', 'preset-' );

		foreach ( $subdirs as $subdir ) {
			if ( strpos( $relative, $subdir ) === 0 ) {
				$dir  = str_replace( '-', '/', rtrim( $subdir, '-' ) ) . '/';
				$file = NVOOS_CROCOBLOCK_DS_PATH . 'includes/' . $dir . 'class-nvoos-cds-' . $relative . '.php';
				if ( file_exists( $file ) ) {
					require_once $file;
					return;
				}
			}
		}

		// Admin classes.
		if ( strpos( $relative, 'admin-' ) === 0 ) {
			$file = NVOOS_CROCOBLOCK_DS_PATH . 'includes/admin/class-nvoos-cds-' . $relative . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}
		}

		// Top-level includes.
		$file = NVOOS_CROCOBLOCK_DS_PATH . 'includes/class-nvoos-cds-' . $relative . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

// ---------------------------------------------------------------------------
// Boot the plugin on plugins_loaded (priority 5 — before most other plugins).
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', array( 'NV_oOS_Crocoblock_DS_Plugin', 'init' ), 5 );
