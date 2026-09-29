<?php
/**
 * Plugin Name: NV oOS Design System
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: Design token system with DTCG export, accessibility tokens, and
 *              a token-driven email template module for WordPress. Previously
 *              the "Crocoblock Design System" — includes integrations for
 *              JetEngine, JetSmartFilters, JetFormBuilder, and Elementor.
 * Version:     0.3.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
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
define( 'NVOOS_DESIGN_SYSTEM_VERSION', '0.3.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_DESIGN_SYSTEM_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_DESIGN_SYSTEM_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_DESIGN_SYSTEM_URL', plugin_dir_url( __FILE__ ) );

// ---------------------------------------------------------------------------
// Legacy constants — the addon was previously released as the "Crocoblock
// Design System" (addons/crocoblock-ds). These aliases keep third-party code
// that referenced the old constants working through the transition release.
// ---------------------------------------------------------------------------

if ( ! defined( 'NVOOS_CROCOBLOCK_DS_VERSION' ) ) {
	define( 'NVOOS_CROCOBLOCK_DS_VERSION', NVOOS_DESIGN_SYSTEM_VERSION );
}
if ( ! defined( 'NVOOS_CROCOBLOCK_DS_FILE' ) ) {
	define( 'NVOOS_CROCOBLOCK_DS_FILE', NVOOS_DESIGN_SYSTEM_FILE );
}
if ( ! defined( 'NVOOS_CROCOBLOCK_DS_PATH' ) ) {
	define( 'NVOOS_CROCOBLOCK_DS_PATH', NVOOS_DESIGN_SYSTEM_PATH );
}
if ( ! defined( 'NVOOS_CROCOBLOCK_DS_URL' ) ) {
	define( 'NVOOS_CROCOBLOCK_DS_URL', NVOOS_DESIGN_SYSTEM_URL );
}

// ---------------------------------------------------------------------------
// Manual autoloader (PSR-4–style; no Composer dependency).
//
// Maps type-hint prefixes to subdirectories:
//   data-        → includes/base/
//   integration- → includes/integrations/
//   email-       → includes/emails/
//   tool-        → includes/tools/
//   admin-       → includes/admin/
//   preset-      → includes/ (top level)
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
// Legacy class aliases — keep the old NV_oOS_Crocoblock_DS_* class names
// working through the transition release. class_alias() defers resolution to
// autoload time, so this is safe even before the target class is loaded.
// ---------------------------------------------------------------------------

if ( ! class_exists( 'NV_oOS_Crocoblock_DS_Plugin', false ) ) {
	class_alias( 'NV_oOS_Design_System_Plugin', 'NV_oOS_Crocoblock_DS_Plugin' );
}
if ( ! class_exists( 'NV_oOS_Crocoblock_DS_Token_Registry', false ) ) {
	class_alias( 'NV_oOS_Design_System_Token_Registry', 'NV_oOS_Crocoblock_DS_Token_Registry' );
}
if ( ! class_exists( 'NV_oOS_Crocoblock_DS_CSS_Generator', false ) ) {
	class_alias( 'NV_oOS_Design_System_CSS_Generator', 'NV_oOS_Crocoblock_DS_CSS_Generator' );
}
if ( ! class_exists( 'NV_oOS_Crocoblock_DS_Assets', false ) ) {
	class_alias( 'NV_oOS_Design_System_Assets', 'NV_oOS_Crocoblock_DS_Assets' );
}
if ( ! class_exists( 'NV_oOS_Crocoblock_DS_DTCG_Exporter', false ) ) {
	class_alias( 'NV_oOS_Design_System_DTCG_Exporter', 'NV_oOS_Crocoblock_DS_DTCG_Exporter' );
}
if ( ! class_exists( 'NV_oOS_Crocoblock_DS_Preset_Minimal', false ) ) {
	class_alias( 'NV_oOS_Design_System_Preset_Minimal', 'NV_oOS_Crocoblock_DS_Preset_Minimal' );
}
if ( ! class_exists( 'NV_oOS_Crocoblock_DS_Preset_Ecommerce', false ) ) {
	class_alias( 'NV_oOS_Design_System_Preset_Ecommerce', 'NV_oOS_Crocoblock_DS_Preset_Ecommerce' );
}
if ( ! class_exists( 'NV_oOS_Crocoblock_DS_Preset_Directory', false ) ) {
	class_alias( 'NV_oOS_Design_System_Preset_Directory', 'NV_oOS_Crocoblock_DS_Preset_Directory' );
}
if ( ! class_exists( 'NV_oOS_Crocoblock_DS_Data_Token', false ) ) {
	class_alias( 'NV_oOS_Design_System_Data_Token', 'NV_oOS_Crocoblock_DS_Data_Token' );
}
if ( ! interface_exists( 'NV_oOS_Crocoblock_DS_Data_Preset', false ) ) {
	class_alias( 'NV_oOS_Design_System_Data_Preset', 'NV_oOS_Crocoblock_DS_Data_Preset' );
}
if ( ! class_exists( 'NV_oOS_Crocoblock_DS_Admin_Page', false ) ) {
	class_alias( 'NV_oOS_Design_System_Admin_Page', 'NV_oOS_Crocoblock_DS_Admin_Page' );
}
if ( ! class_exists( 'NV_oOS_Crocoblock_DS_Integration_JSF', false ) ) {
	class_alias( 'NV_oOS_Design_System_Integration_JSF', 'NV_oOS_Crocoblock_DS_Integration_JSF' );
}
if ( ! class_exists( 'NV_oOS_Crocoblock_DS_Integration_JetEngine', false ) ) {
	class_alias( 'NV_oOS_Design_System_Integration_JetEngine', 'NV_oOS_Crocoblock_DS_Integration_JetEngine' );
}
if ( ! class_exists( 'NV_oOS_Crocoblock_DS_Integration_JFB', false ) ) {
	class_alias( 'NV_oOS_Design_System_Integration_JFB', 'NV_oOS_Crocoblock_DS_Integration_JFB' );
}
if ( ! class_exists( 'NV_oOS_Crocoblock_DS_Integration_Elementor', false ) ) {
	class_alias( 'NV_oOS_Design_System_Integration_Elementor', 'NV_oOS_Crocoblock_DS_Integration_Elementor' );
}

// ---------------------------------------------------------------------------
// Boot the plugin on plugins_loaded (priority 5 — before most other plugins).
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', array( 'NV_oOS_Design_System_Plugin', 'init' ), 5 );
