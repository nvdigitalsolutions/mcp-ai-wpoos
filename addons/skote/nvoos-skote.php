<?php
/**
 * Plugin Name: NV oOS Skote Addon
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: Embeds the Skote React admin template (Themesbrand) inside WordPress as a single-page operator console for NV oOS. Talks to WP REST + custom Pro REST endpoints (users, WooCommerce, JetEngine, CPTs, settings, workflows, tools) instead of Skote's bundled fakebackend. Works standalone; unlocks workflow / HITL / tool features when NV oOS Pro is active.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-skote
 * Domain Path: /languages
 *
 * @package NV_oOS_Skote
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU General Public License v3 or later.
 *
 * NOTE: The Skote React admin template (https://themesbrand.com/skote-react/)
 * is a commercial product owned by Themesbrand and is NOT bundled with this
 * addon. Site owners must obtain their own Skote license (Envato Regular or
 * Extended) and import the source via `bin/import-skote.sh` at build time.
 * See README.md for details.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
define( 'NVOOS_SKOTE_VERSION', '0.1.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_SKOTE_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_SKOTE_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_SKOTE_URL', plugin_dir_url( __FILE__ ) );

/** Absolute path to the built React assets directory (trailing slash). */
define( 'NVOOS_SKOTE_DIST', NVOOS_SKOTE_PATH . 'dist/' );

/** REST namespace exposed by this addon. */
define( 'NVOOS_SKOTE_REST_NAMESPACE', 'nvoos-skote/v1' );

// Core boot.
require_once NVOOS_SKOTE_PATH . 'includes/class-nvoos-skote.php';
require_once NVOOS_SKOTE_PATH . 'includes/class-nvoos-skote-assets.php';
require_once NVOOS_SKOTE_PATH . 'includes/class-nvoos-skote-admin-page.php';
require_once NVOOS_SKOTE_PATH . 'includes/class-nvoos-skote-shortcode.php';

// REST controllers.
require_once NVOOS_SKOTE_PATH . 'includes/rest/class-nvoos-skote-rest-base.php';
require_once NVOOS_SKOTE_PATH . 'includes/rest/class-nvoos-skote-rest-settings.php';
require_once NVOOS_SKOTE_PATH . 'includes/rest/class-nvoos-skote-rest-bridge.php';
require_once NVOOS_SKOTE_PATH . 'includes/rest/class-nvoos-skote-rest-workflows.php';

// Integration bridges.
require_once NVOOS_SKOTE_PATH . 'includes/integrations/class-nvoos-skote-pro-bridge.php';
require_once NVOOS_SKOTE_PATH . 'includes/integrations/class-nvoos-skote-jetengine-bridge.php';
require_once NVOOS_SKOTE_PATH . 'includes/integrations/class-nvoos-skote-woocommerce-bridge.php';

// Boot.
NV_oOS_Skote::init();
