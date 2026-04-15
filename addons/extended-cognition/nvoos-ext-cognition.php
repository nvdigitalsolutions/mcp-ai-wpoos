<?php
/**
 * Plugin Name: NV oOS Extended Cognition Toolkit
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: Extended Cognition Pro Toolkit for NV oOS. Grants AI agents a sensory periphery — camera, microphone, screen capture, and motion sensors — enabling active sensing loops grounded in Clark & Chalmers (1998) extended mind theory and functionalism. Requires NV oOS base plugin.
 * Version:     1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-ext-cognition
 * Domain Path: /languages
 *
 * @package NV_oOS_Ext_Cognition
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU General Public License v3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Addon version. */
define( 'NVOOS_EXT_COG_VERSION', '1.0.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_EXT_COG_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_EXT_COG_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_EXT_COG_URL', plugin_dir_url( __FILE__ ) );

// Load core classes.
require_once NVOOS_EXT_COG_PATH . 'includes/class-nvoos-ext-cognition-sensor-session.php';
require_once NVOOS_EXT_COG_PATH . 'includes/class-nvoos-ext-cognition.php';

// Load admin classes.
if ( is_admin() ) {
	require_once NVOOS_EXT_COG_PATH . 'includes/admin/class-nvoos-ext-cognition-settings.php';
}

// Load REST controller.
require_once NVOOS_EXT_COG_PATH . 'includes/rest/class-nvoos-ext-cognition-rest.php';

/**
 * Check whether the NV oOS base plugin is active.
 *
 * @since 1.0.0
 *
 * @return bool True when the base plugin is available.
 */
function nvoos_ext_cog_is_base_active() {
	return defined( 'WP_MCP_AI_VERSION' );
}

/**
 * Check whether the Extended Cognition addon is fully ready.
 *
 * @since 1.0.0
 *
 * @return bool True when the addon is operational.
 */
function nvoos_ext_cog_is_ready() {
	return nvoos_ext_cog_is_base_active() && NV_oOS_Ext_Cognition::is_enabled();
}

/**
 * Check whether the Extended Cognition Toolkit is enabled.
 *
 * @since 1.0.0
 *
 * @return bool True when the addon is active and enabled in settings.
 */
function nvoos_ext_cog_is_enabled() {
	return nvoos_ext_cog_is_base_active() && NV_oOS_Ext_Cognition::is_enabled();
}

// Boot the plugin.
NV_oOS_Ext_Cognition::init();
