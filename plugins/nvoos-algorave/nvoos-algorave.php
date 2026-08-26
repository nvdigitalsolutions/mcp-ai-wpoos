<?php
/**
 * Plugin Name:  NV oOS Algorave
 * Plugin URI:   https://github.com/nvdigitalsolutions/nvoos-algorave
 * Description:  Live coding music studio for WordPress. Strudel and Tone.js audio engines, a pattern library, MIDI export and a real-time audio visualizer. No API keys required.
 * Version:      1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Author:       NV Digital Solutions
 * Author URI:   https://nvdigitalsolutions.com
 * License:      AGPL-3.0-or-later
 * License URI:  https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain:  nvoos-algorave
 * Domain Path:  /languages
 *
 * @package NV_oOS_Algorave
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU Affero General Public License v3 or
 * later. The combined-work license is AGPL-3.0 because this plugin bundles
 * `@strudel/web` (AGPL-3.0) under `assets/js/vendor/strudel/`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version.
 *
 * Guarded so the standalone plugin and the legacy NV oOS addon
 * (which used the same constant names) can never collide.
 */
if ( ! defined( 'NVOOS_ALGORAVE_VERSION' ) ) {
	define( 'NVOOS_ALGORAVE_VERSION', '1.0.0' );
}

/** Absolute path to this plugin file. */
if ( ! defined( 'NVOOS_ALGORAVE_FILE' ) ) {
	define( 'NVOOS_ALGORAVE_FILE', __FILE__ );
}

/** Absolute path to this plugin directory (trailing slash). */
if ( ! defined( 'NVOOS_ALGORAVE_PATH' ) ) {
	define( 'NVOOS_ALGORAVE_PATH', plugin_dir_path( __FILE__ ) );
}

/** URL to this plugin directory (trailing slash). */
if ( ! defined( 'NVOOS_ALGORAVE_URL' ) ) {
	define( 'NVOOS_ALGORAVE_URL', plugin_dir_url( __FILE__ ) );
}

/** Strudel version bundled with the plugin. */
if ( ! defined( 'NVOOS_ALGORAVE_STRUDEL_VERSION' ) ) {
	define( 'NVOOS_ALGORAVE_STRUDEL_VERSION', '1.2.5' );
}

/** Marker constant distinguishing this standalone plugin from the legacy addon. */
define( 'NVOOS_ALGORAVE_STANDALONE', true );

// ─── Coexistence guard ──────────────────────────────────────────
// The legacy "NV oOS Algorave Addon" (addons/algorave/) defines the same
// global classes. If it is already active, bail out instead of fatally
// redeclaring them. The legacy addon carries the reverse guard.
if ( class_exists( 'NV_oOS_Algorave', false ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'NV oOS Algorave and the legacy NV oOS Algorave Addon cannot both be active. Deactivate one of them.', 'nvoos-algorave' );
			echo '</p></div>';
		}
	);
	return;
}

// Load core classes.
require_once NVOOS_ALGORAVE_PATH . 'includes/class-nvoos-algorave.php';
require_once NVOOS_ALGORAVE_PATH . 'includes/class-nvoos-algorave-pattern-cpt.php';
require_once NVOOS_ALGORAVE_PATH . 'includes/class-nvoos-algorave-session-cpt.php';
require_once NVOOS_ALGORAVE_PATH . 'includes/class-nvoos-algorave-sample-library.php';
require_once NVOOS_ALGORAVE_PATH . 'includes/class-nvoos-algorave-seeder.php';

// Load admin classes.
if ( is_admin() ) {
	require_once NVOOS_ALGORAVE_PATH . 'includes/admin/class-nvoos-algorave-settings.php';
}

// Load REST controller.
require_once NVOOS_ALGORAVE_PATH . 'includes/rest/class-nvoos-algorave-rest.php';

// ─── Activation ────────────────────────────────────────────────
register_activation_hook(
	__FILE__,
	static function (): void {
		if ( PHP_VERSION_ID < 70400 ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				'NV oOS Algorave requires PHP 7.4 or higher.',
				'Plugin Activation Failed',
				array( 'back_link' => true )
			);
		}

		set_transient( 'nvoos_algorave_activated', true, 30 );
		NV_oOS_Algorave_Seeder::maybe_seed();
	}
);

// ─── Text domain ───────────────────────────────────────────────
// Explicit load for WP < 6.5 compatibility; on 6.5+ the textdomain
// registry auto-discovers /languages.
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain(
			'nvoos-algorave',
			false,
			dirname( plugin_basename( NVOOS_ALGORAVE_FILE ) ) . '/languages'
		);
	}
);

// ─── Boot ──────────────────────────────────────────────────────
NV_oOS_Algorave::init();

/**
 * Seed patterns on init for upgrade paths where the activation hook doesn't fire.
 */
add_action(
	'init',
	static function (): void {
		NV_oOS_Algorave_Seeder::maybe_seed();
	},
	99
);

// ─── Public API ────────────────────────────────────────────────

if ( ! function_exists( 'nvoos_algorave_is_enabled' ) ) :
	/**
	 * Check whether NV oOS Algorave is enabled.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	function nvoos_algorave_is_enabled() {
		return NV_oOS_Algorave::is_enabled();
	}
endif;

if ( ! function_exists( 'nvoos_algorave_get_settings' ) ) :
	/**
	 * Get all Algorave settings.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	function nvoos_algorave_get_settings() {
		return NV_oOS_Algorave::get_settings();
	}
endif;

if ( ! function_exists( 'nvoos_algorave_get_setting' ) ) :
	/**
	 * Get a specific setting value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Default value.
	 * @return mixed
	 */
	function nvoos_algorave_get_setting( $key, $default_value = null ) {
		$settings = NV_oOS_Algorave::get_settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default_value;
	}
endif;
