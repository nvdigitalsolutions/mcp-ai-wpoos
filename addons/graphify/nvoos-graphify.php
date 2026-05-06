<?php
/**
 * Plugin Name: NV oOS Graphify
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: WordPress Knowledge Graph addon for NV oOS. Extracts entities and relationships from your content, builds a navigable knowledge graph, and exposes it to AI assistants via oOS tools and a REST API. Requires NV oOS base plugin.
 * Version:     0.6.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: Proprietary
 * License URI: https://nvdigitalsolutions.com/wpoos/license
 * Text Domain: nvoos-graphify
 * Domain Path: /languages
 *
 * @package NV_oOS_Graphify
 *
 * ⚠️ PROPRIETARY SOFTWARE
 * This is commercial software licensed for authorized users only.
 * Patent Pending (Application #19/410,504)
 * © 2025 NV Digital Solutions - All Rights Reserved
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com)
 * All rights reserved. This is proprietary software.
 *
 * Bundled third-party assets retain their upstream MIT licenses; see README.md
 * and the repository-wide CREDITS.md for the full attribution index.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
define( 'NVOOS_GRAPHIFY_VERSION', '0.6.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_GRAPHIFY_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_GRAPHIFY_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_GRAPHIFY_URL', plugin_dir_url( __FILE__ ) );

/** DB schema version — bump when tables change. */
define( 'NVOOS_GRAPHIFY_DB_VERSION', '2' );

// Load core classes.
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-db.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-detector.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-structural-extractor.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-semantic-extractor.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-builder.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-analyzer.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-report.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-exporter.php';

// Load remote source system.
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/interface-nvoos-graphify-remote-source.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/class-nvoos-graphify-remote-source-base.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/class-nvoos-graphify-crypto.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/class-nvoos-graphify-http-client.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/class-nvoos-graphify-remote-registry.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/class-nvoos-graphify-remote-state-store.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/class-nvoos-graphify-oauth-broker.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/class-nvoos-graphify-field-mapper.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/class-nvoos-graphify-field-map-validator.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/class-nvoos-graphify-entity-resolver.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/class-nvoos-graphify-schema-org-mapper.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-wikidata.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-oos-federation.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-generic-rest.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-rss-sitemap.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-sparql.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-woocommerce.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-csv.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-webhook.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-hubspot.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-github.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-slack.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-google-drive.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-jira.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-zendesk.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-m365.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-servicenow.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-generic-graphql.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-generic-sql.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/remote/drivers/class-nvoos-graphify-remote-s3.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-remote-enricher.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-embeddings.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-embeddings-on-ingest.php';
require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-memory-bridge.php';

// Load NV oOS data bridge (only when the base plugin is active).
// Delay to plugins_loaded so WP_MCP_AI_VERSION is defined before we test.
add_action(
	'plugins_loaded',
	static function () {
		if ( defined( 'WP_MCP_AI_VERSION' )
			&& ! class_exists( 'NV_oOS_Graphify_NV_oOS_Bridge' )
		) {
			require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify-nvoos-bridge.php';
			NV_oOS_Graphify_NV_oOS_Bridge::register();
		}
	},
	20
);

require_once NVOOS_GRAPHIFY_PATH . 'includes/class-nvoos-graphify.php';

// Load admin classes.
if ( is_admin() ) {
	require_once NVOOS_GRAPHIFY_PATH . 'includes/admin/class-nv-oos-graphify-settings.php';
	require_once NVOOS_GRAPHIFY_PATH . 'includes/admin/class-nvoos-graphify-remote-admin.php';
}

// Load REST controller.
require_once NVOOS_GRAPHIFY_PATH . 'includes/rest/class-nvoos-graphify-rest.php';

/**
 * Check whether the NV oOS base plugin is active.
 *
 * @since 0.5.0
 *
 * @return bool True when the base plugin is available.
 */
function nvoos_graphify_is_base_active() {
	return defined( 'WP_MCP_AI_VERSION' );
}

/**
 * Check whether Graphify is fully ready.
 *
 * @since 0.5.0
 *
 * @return bool True when the addon is operational.
 */
function nvoos_graphify_is_ready() {
	return nvoos_graphify_is_base_active() && NV_oOS_Graphify::is_enabled();
}

// Register activation / deactivation / uninstall hooks.
register_activation_hook( __FILE__, array( 'NV_oOS_Graphify', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NV_oOS_Graphify', 'deactivate' ) );

// Boot the plugin.
NV_oOS_Graphify::init();
