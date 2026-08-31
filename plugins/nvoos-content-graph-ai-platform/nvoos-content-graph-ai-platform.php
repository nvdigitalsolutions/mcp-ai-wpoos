<?php
/**
 * Plugin Name:  NV oOS Content Graph — Platform
 * Plugin URI:   https://github.com/nvdigitalsolutions/nvoos-content-graph-ai-platform
 * Description:  Platform layer for NV oOS Content Graph. Adds agents, skills, slash-commands, harness, measurement, professions, A2A, ACP, federation, and blueprints on top of the AI addon.
 * Version:      2.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: nvoos-content-graph, nvoos-content-graph-ai
 * Author:       NV Digital Solutions
 * Author URI:   https://nvdigitalsolutions.com
 * License:      Proprietary
 * License URI:  https://nvdigitalsolutions.com/license
 * Text Domain:  nvoos-content-graph-ai-platform
 * Domain Path:  /languages
 *
 * @package NvoosContentGraphAiPlatform
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION', '2.0.0' );
define( 'NVOOS_CONTENT_GRAPH_AI_PLATFORM_FILE', __FILE__ );
define( 'NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH', plugin_dir_path( __FILE__ ) );
define( 'NVOOS_CONTENT_GRAPH_AI_PLATFORM_URL', plugin_dir_url( __FILE__ ) );

// Autoloader — Composer primary, spl fallback.
$nvoos_content_graph_ai_platform_autoload = NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'vendor/autoload.php';
if ( file_exists( $nvoos_content_graph_ai_platform_autoload ) ) {
	require_once $nvoos_content_graph_ai_platform_autoload;
}

spl_autoload_register(
	static function ( string $fqcn ): void {
		$prefix = 'NvoosContentGraphAiPlatform\\';
		if ( 0 !== strpos( $fqcn, $prefix ) ) {
			return;
		}
		$relative = substr( $fqcn, strlen( $prefix ) );
		$file     = NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

// ─── Lifecycle hooks ──────────────────────────────────────────────

register_activation_hook(
	__FILE__,
	static function (): void {
		// Seed default platform settings (autoload = no; fetched per page load).
		if ( class_exists( 'NvoosContentGraphAiPlatform\Schema\Defaults' ) ) {
			$defaults = \NvoosContentGraphAiPlatform\Schema\Defaults::platformSettings();
			add_option( 'ai_platform_settings', $defaults, '', false );
		}

		// Standalone mode: schedule the deferred bundled-skills install — the
		// base plugin owns this wiring in monolith mode (extraction Wave B).
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			set_transient( 'wp_mcp_ai_install_bundled_skills', true, HOUR_IN_SECONDS );
		}

		// Flush rewrite rules so CPT permalinks are recognised.
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		flush_rewrite_rules();
	}
);

// ─── Boot — checks run at plugins_loaded (priority 10) after AI addon (priority 5).
add_action(
	'plugins_loaded',
	static function (): void {
		// Activation guard: nvoos-content-graph must be active.
		if ( ! function_exists( 'nvoos_content_graph_is_enabled' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'NV oOS Content Graph — Platform requires the NV oOS Content Graph core plugin to be installed and activated.', 'nvoos-content-graph-ai-platform' )
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
						esc_html__( 'NV oOS Content Graph — Platform requires the NV oOS Content Graph — AI addon to be installed and activated.', 'nvoos-content-graph-ai-platform' )
					);
				}
			);
			return;
		}

		if ( class_exists( 'NvoosContentGraphAiPlatform\Plugin' ) ) {
			\NvoosContentGraphAiPlatform\Plugin::instance()->register();
		}
	},
	10
);
