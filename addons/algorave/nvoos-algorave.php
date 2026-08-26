<?php
/**
 * Plugin Name: NV oOS Algorave Addon
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: Algorave live coding music extension for NV oOS. Enables AI-powered music pattern generation, browser-based audio synthesis via Tone.js/Strudel, MIDI export, and real-time audio visualization through the oOS chat interface. Requires NV oOS base plugin.
 * Version:     1.0.7
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: AGPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain: nvoos-algorave
 * Domain Path: /languages
 *
 * @package NV_oOS_Algorave
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU Affero General Public License v3 or
 * later. The combined-work license is AGPL-3.0 because this addon bundles
 * `@strudel/web` (AGPL-3.0) under `assets/js/vendor/strudel/`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Coexistence guard ──────────────────────────────────────────
// The standalone "NV oOS Algorave" plugin (plugins/nvoos-algorave/)
// defines the same global classes. If it is already active, bail out
// instead of fatally redeclaring them. The standalone plugin carries
// the reverse guard.
if ( class_exists( 'NV_oOS_Algorave', false ) || defined( 'NVOOS_ALGORAVE_STANDALONE' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'NV oOS Algorave Addon and the standalone NV oOS Algorave plugin cannot both be active. Deactivate one of them.', 'nvoos-algorave' );
			echo '</p></div>';
		}
	);
	return;
}

/** Plugin version. */
define( 'NVOOS_ALGORAVE_VERSION', '1.0.7' );

/** Absolute path to this plugin file. */
define( 'NVOOS_ALGORAVE_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_ALGORAVE_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_ALGORAVE_URL', plugin_dir_url( __FILE__ ) );

/** Strudel version bundled with the addon. */
define( 'NVOOS_ALGORAVE_STRUDEL_VERSION', '1.2.5' );

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

/**
 * Check whether the NV oOS base plugin is active.
 *
 * @since 1.0.0
 *
 * @return bool True when the base plugin is available.
 */
function nvoos_algorave_is_base_active() {
	return defined( 'WP_MCP_AI_VERSION' );
}

/**
 * Check whether the algorave addon is fully ready.
 *
 * @since 1.0.0
 *
 * @return bool True when the addon is operational.
 */
function nvoos_algorave_is_ready() {
	return nvoos_algorave_is_base_active() && NV_oOS_Algorave::is_enabled();
}

// Boot the plugin.
NV_oOS_Algorave::init();
