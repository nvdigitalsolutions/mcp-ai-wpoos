<?php
/**
 * Plugin Name: NV oOS Design System
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: Design tokens, brand kit, DTCG 2025.10 interchange,
 *              Global Styles sync, WCAG 2.2 contrast checks, and
 *              token-driven email templates for WordPress.
 * Version:     1.0.1
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-design-system
 * Domain Path: /languages
 *
 * @package NV_oOS_Design_System
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version — keep in sync with the header above. */
define( 'NVOOS_DESIGN_SYSTEM_VERSION', '1.0.1' );

/** Absolute path to this plugin file. */
define( 'NVOOS_DESIGN_SYSTEM_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_DESIGN_SYSTEM_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_DESIGN_SYSTEM_URL', plugin_dir_url( __FILE__ ) );

// ---------------------------------------------------------------------------
// Manual autoloader (PSR-4–style; no Composer dependency).
//
// Maps type-hint prefixes to subdirectories:
// data-        → includes/base/
// integration- → includes/integrations/
// email-       → includes/emails/
// tool-        → includes/tools/
// admin-       → includes/admin/
// preset-      → includes/ (top level)
// ---------------------------------------------------------------------------

spl_autoload_register(
	function ( $class ) {
		$prefix = 'NV_oOS_Design_System_';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}

		$relative = str_replace( $prefix, '', $class );
		$relative = str_replace( '_', '-', $relative );
		$relative = strtolower( $relative );

		$subdirs = array(
			'data-'        => 'base/',
			'integration-' => 'integrations/',
			'email-'       => 'emails/',
			'tool-'        => 'tools/',
			'admin-'       => 'admin/',
			'preset-'      => '',
		);

		foreach ( $subdirs as $hint => $dir ) {
			if ( strpos( $relative, $hint ) === 0 ) {
				$file = NVOOS_DESIGN_SYSTEM_PATH . 'includes/' . $dir . 'class-nvoos-nds-' . $relative . '.php';
				if ( file_exists( $file ) ) {
					require_once $file;
					return;
				}
			}
		}

		// Top-level includes.
		$file = NVOOS_DESIGN_SYSTEM_PATH . 'includes/class-nvoos-nds-' . $relative . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

// ---------------------------------------------------------------------------
// Boot the plugin on plugins_loaded (priority 5 — before most other plugins).
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', array( 'NV_oOS_Design_System_Plugin', 'init' ), 5 );

// Translations are loaded just-in-time by WordPress (including WordPress.org
// language packs); no manual load_plugin_textdomain() call is needed.
