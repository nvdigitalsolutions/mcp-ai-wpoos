<?php
/**
 * Plugin Name: NV oOS Fantasy Football Addon
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: Fantasy Football extension for NV oOS. Provides ESPN and Yahoo Fantasy Sports API integration with team management, player research, trade analysis, league reports, and AI-powered team logo generation through the oOS chat interface. Requires NV oOS base plugin.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: Proprietary
 * License URI: https://nvdigitalsolutions.com/wpoos/license
 * Text Domain: nvoos-fantasy-football
 * Domain Path: /languages
 *
 * @package NV_oOS_Fantasy_Football
 *
 * ⚠️ PROPRIETARY SOFTWARE
 * This is commercial software licensed for authorized users only.
 * Patent Pending (Application #19/410,504)
 * © 2025 NV Digital Solutions - All Rights Reserved
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com)
 * All rights reserved. This is proprietary software.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
define( 'NVOOS_FANTASY_FOOTBALL_VERSION', '0.1.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_FANTASY_FOOTBALL_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_FANTASY_FOOTBALL_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_FANTASY_FOOTBALL_URL', plugin_dir_url( __FILE__ ) );

// Load core classes.
require_once NVOOS_FANTASY_FOOTBALL_PATH . 'includes/class-nvoos-fantasy-football.php';

// Load CPTs.
require_once NVOOS_FANTASY_FOOTBALL_PATH . 'includes/fantasy-football/class-wp-mcp-ai-fantasy-team-cpt.php';
require_once NVOOS_FANTASY_FOOTBALL_PATH . 'includes/fantasy-football/class-wp-mcp-ai-fantasy-player-cpt.php';

// Load ESPN Fantasy Client.
require_once NVOOS_FANTASY_FOOTBALL_PATH . 'includes/class-wp-mcp-ai-espn-fantasy-client.php';

// Load admin classes.
if ( is_admin() ) {
	require_once NVOOS_FANTASY_FOOTBALL_PATH . 'includes/admin/class-nvoos-fantasy-football-settings.php';
	require_once NVOOS_FANTASY_FOOTBALL_PATH . 'includes/admin/class-wp-mcp-ai-fantasy-football-research-page.php';
}

/**
 * Check whether the NV oOS base plugin is active.
 *
 * @since 0.1.0
 *
 * @return bool True when the base plugin is available.
 */
function nvoos_fantasy_football_is_base_active() {
	return defined( 'WP_MCP_AI_VERSION' );
}

/**
 * Check whether the fantasy football addon is fully ready.
 *
 * @since 0.1.0
 *
 * @return bool True when the addon is operational.
 */
function nvoos_fantasy_football_is_ready() {
	return nvoos_fantasy_football_is_base_active() && NV_oOS_Fantasy_Football::is_enabled();
}

// Boot the plugin.
NV_oOS_Fantasy_Football::init();
