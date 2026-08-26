<?php
/**
 * Plugin Name:  NV oOS Algorave — AI
 * Plugin URI:   https://github.com/nvdigitalsolutions/nvoos-algorave-ai
 * Description:  Premium AI addon for NV oOS Algorave. Adds 9 AI tools to the NV oOS chat interface — pattern generation, AI music generation (Lyria/Replicate), MIDI export, sample management, visualizer control and more.
 * Version:      1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: nvoos-algorave
 * Tested up to: 6.9
 * Author:       NV Digital Solutions
 * Author URI:   https://nvdigitalsolutions.com
 * License:      Proprietary
 * License URI:  https://nvdigitalsolutions.com/license
 * Text Domain:  nvoos-algorave-ai
 * Domain Path:  /languages
 *
 * @package NV_oOS_Algorave_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
if ( ! defined( 'NVOOS_ALGORAVE_AI_VERSION' ) ) {
	define( 'NVOOS_ALGORAVE_AI_VERSION', '1.0.0' );
}

/** Absolute path to this plugin file. */
if ( ! defined( 'NVOOS_ALGORAVE_AI_FILE' ) ) {
	define( 'NVOOS_ALGORAVE_AI_FILE', __FILE__ );
}

/** Absolute path to this plugin directory (trailing slash). */
if ( ! defined( 'NVOOS_ALGORAVE_AI_PATH' ) ) {
	define( 'NVOOS_ALGORAVE_AI_PATH', plugin_dir_path( __FILE__ ) );
}

/** URL to this plugin directory (trailing slash). */
if ( ! defined( 'NVOOS_ALGORAVE_AI_URL' ) ) {
	define( 'NVOOS_ALGORAVE_AI_URL', plugin_dir_url( __FILE__ ) );
}

// Load the AI plugin class. Tool classes are loaded lazily — only when the
// NV oOS base plugin is present, because they implement its tool interfaces.
require_once NVOOS_ALGORAVE_AI_PATH . 'includes/class-nvoos-algorave-ai.php';

// ─── Text domain ───────────────────────────────────────────────
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain(
			'nvoos-algorave-ai',
			false,
			dirname( plugin_basename( NVOOS_ALGORAVE_AI_FILE ) ) . '/languages'
		);
	}
);

// ─── Boot ──────────────────────────────────────────────────────
NV_oOS_Algorave_AI::init();

// ─── Public API ────────────────────────────────────────────────

if ( ! function_exists( 'nvoos_algorave_ai_is_ready' ) ) :
	/**
	 * Check whether the AI addon is fully operational.
	 *
	 * Requires both the standalone NV oOS Algorave plugin (active and
	 * enabled) and the NV oOS base plugin (which provides the tool registry).
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	function nvoos_algorave_ai_is_ready() {
		return NV_oOS_Algorave_AI::is_ready();
	}
endif;

if ( ! function_exists( 'nvoos_algorave_ai_is_base_active' ) ) :
	/**
	 * Check whether the NV oOS base plugin is active.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	function nvoos_algorave_ai_is_base_active() {
		return defined( 'WP_MCP_AI_VERSION' );
	}
endif;
