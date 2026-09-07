<?php
/**
 * Plugin Name:  NV oOS Content Graph — Pro
 * Plugin URI:   https://github.com/nvdigitalsolutions/nvoos-content-graph-pro
 * Description:  Pro toolkit layer for NV oOS Content Graph. Adds the Pro module registry, password vault, vector storage, skills manager, toolkit data-store factory, and privacy APIs on top of the AI Platform addon.
 * Version:      1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: nvoos-content-graph, nvoos-content-graph-ai, nvoos-content-graph-ai-platform
 * Author:       NV Digital Solutions
 * Author URI:   https://nvdigitalsolutions.com
 * License:      Proprietary
 * License URI:  https://nvdigitalsolutions.com/license
 * Text Domain:  nvoos-content-graph-pro
 * Domain Path:  /languages
 *
 * @package NvoosContentGraphPro
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NVOOS_CONTENT_GRAPH_PRO_VERSION', '1.0.0' );
define( 'NVOOS_CONTENT_GRAPH_PRO_FILE', __FILE__ );
define( 'NVOOS_CONTENT_GRAPH_PRO_PATH', plugin_dir_path( __FILE__ ) );
define( 'NVOOS_CONTENT_GRAPH_PRO_URL', plugin_dir_url( __FILE__ ) );

// Autoloader — Composer primary, spl fallback.
$nvoos_content_graph_pro_autoload = NVOOS_CONTENT_GRAPH_PRO_PATH . 'vendor/autoload.php';
if ( file_exists( $nvoos_content_graph_pro_autoload ) ) {
	require_once $nvoos_content_graph_pro_autoload;
}

spl_autoload_register(
	static function ( string $fqcn ): void {
		// Namespaced composition root.
		$nvoos_content_graph_pro_ns = 'NvoosContentGraphPro\\';
		if ( 0 === strpos( $fqcn, $nvoos_content_graph_pro_ns ) ) {
			$nvoos_content_graph_pro_relative = substr( $fqcn, strlen( $nvoos_content_graph_pro_ns ) );
			$nvoos_content_graph_pro_file     = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/' . str_replace( '\\', '/', $nvoos_content_graph_pro_relative ) . '.php';
			if ( file_exists( $nvoos_content_graph_pro_file ) ) {
				require_once $nvoos_content_graph_pro_file;
			}
			return;
		}

		// Ported Pro classes keep their global WP_MCP_AI_* names so the
		// public surface stays byte-identical with the base Pro addon
		// (which owns the same classes in monolith installs — see
		// plugins/nvoos-content-graph-pro/README.md §Modes).
		if ( 0 !== strpos( $fqcn, 'WP_MCP_AI_' ) ) {
			return;
		}
		if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			// Monolith mode: the base Pro addon owns these classes.
			return;
		}

		// Ported files mirror the base addon's includes/ layout; scan the
		// known subtree roots (extend this list as new waves land). `class-`,
		// `interface-`, and `trait-` file prefixes are probed.
		$nvoos_content_graph_pro_file_name = strtolower( str_replace( '_', '-', $fqcn ) ) . '.php';
		$nvoos_content_graph_pro_subdirs   = array(
			'src/',
			'src/admin/',
			'src/data-stores/',
			'src/interfaces/',
			'src/rest/',
			'src/services/',
			'src/tools/',
			'src/tools/capture/',
			'src/tools/crm/',
			'src/tools/crm/activities/',
			'src/tools/crm/analytics/',
			'src/tools/crm/customers/',
			'src/tools/crm/deals/',
			'src/tools/crm/icp/',
			'src/tools/crm/inbound/',
			'src/tools/crm/leads/',
			'src/tools/crm/outbound/',
			'src/tools/crm/routing/',
			'src/tools/vault/',
			'src/tools/vector-storage/',
			'src/traits/',
			'src/vault/',
		);
		foreach ( $nvoos_content_graph_pro_subdirs as $nvoos_content_graph_pro_subdir ) {
			foreach ( array( 'class-', 'interface-', 'trait-' ) as $nvoos_content_graph_pro_prefix ) {
				$nvoos_content_graph_pro_file = NVOOS_CONTENT_GRAPH_PRO_PATH . $nvoos_content_graph_pro_subdir . $nvoos_content_graph_pro_prefix . $nvoos_content_graph_pro_file_name;
				if ( file_exists( $nvoos_content_graph_pro_file ) ) {
					require_once $nvoos_content_graph_pro_file;
					return;
				}
			}
		}
	}
);

// ─── Boot — runs after the AI addon (priority 5) and Platform addon (priority 10).
add_action(
	'plugins_loaded',
	static function (): void {
		// Monolith mode: the base plugin's Pro addon (addons/pro) owns every
		// Pro subsystem — booting this addon too would redeclare the same
		// classes and double-run CPT/REST/tool wiring.
		if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			return;
		}

		// Activation guard: nvoos-content-graph must be active.
		if ( ! function_exists( 'nvoos_content_graph_is_enabled' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'NV oOS Content Graph — Pro requires the NV oOS Content Graph core plugin to be installed and activated.', 'nvoos-content-graph-pro' )
					);
				}
			);
			return;
		}

		// Activation guard: nvoos-content-graph-ai must be active.
		if ( ! class_exists( 'NvoosContentGraphAi\\Plugin' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'NV oOS Content Graph — Pro requires the NV oOS Content Graph — AI addon to be installed and activated.', 'nvoos-content-graph-pro' )
					);
				}
			);
			return;
		}

		// Activation guard: nvoos-content-graph-ai-platform must be active.
		if ( ! class_exists( 'NvoosContentGraphAiPlatform\\Plugin' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'NV oOS Content Graph — Pro requires the NV oOS Content Graph — Platform addon to be installed and activated.', 'nvoos-content-graph-pro' )
					);
				}
			);
			return;
		}

		if ( class_exists( 'NvoosContentGraphPro\\Plugin' ) ) {
			\NvoosContentGraphPro\Plugin::instance()->register();
		}
	},
	15
);
