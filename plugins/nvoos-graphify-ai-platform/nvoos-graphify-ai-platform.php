<?php
/**
 * Plugin Name:  NV oOS Graphify — Platform
 * Plugin URI:   https://github.com/nvdigitalsolutions/nvoos-graphify-ai-platform
 * Description:  Platform layer for NV oOS Graphify. Adds agents, skills, slash-commands, harness, measurement, professions, A2A, ACP, federation, and blueprints on top of the AI addon.
 * Version:      1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: nvoos-graphify, nvoos-graphify-ai
 * Author:       NV Digital Solutions
 * Author URI:   https://nvdigitalsolutions.com
 * License:      Proprietary
 * License URI:  https://nvdigitalsolutions.com/license
 * Text Domain:  nvoos-graphify-ai-platform
 * Domain Path:  /languages
 *
 * @package NvoosGraphifyAiPlatform
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NVOOS_GRAPHIFY_AI_PLATFORM_VERSION', '1.0.0-dev' );
define( 'NVOOS_GRAPHIFY_AI_PLATFORM_FILE', __FILE__ );
define( 'NVOOS_GRAPHIFY_AI_PLATFORM_PATH', plugin_dir_path( __FILE__ ) );
define( 'NVOOS_GRAPHIFY_AI_PLATFORM_URL', plugin_dir_url( __FILE__ ) );

// Autoloader — Composer primary, spl fallback.
$nvoos_graphify_ai_platform_autoload = NVOOS_GRAPHIFY_AI_PLATFORM_PATH . 'vendor/autoload.php';
if ( file_exists( $nvoos_graphify_ai_platform_autoload ) ) {
	require_once $nvoos_graphify_ai_platform_autoload;
}

spl_autoload_register(
	static function ( string $fqcn ): void {
		$prefix = 'NvoosGraphifyAiPlatform\\';
		if ( 0 !== strpos( $fqcn, $prefix ) ) {
			return;
		}
		$relative = substr( $fqcn, strlen( $prefix ) );
		$file     = NVOOS_GRAPHIFY_AI_PLATFORM_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
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
		if ( class_exists( 'NvoosGraphifyAiPlatform\Schema\Defaults' ) ) {
			$defaults = \NvoosGraphifyAiPlatform\Schema\Defaults::platformSettings();
			add_option( 'ai_platform_settings', $defaults, '', false );
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
		// Activation guard: nvoos-graphify must be active.
		if ( ! function_exists( 'nvoos_graphify_is_enabled' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'NV oOS Graphify — Platform requires the NV oOS Graphify core plugin to be installed and activated.', 'nvoos-graphify-ai-platform' )
					);
				}
			);
			return;
		}

		// Activation guard: nvoos-graphify-ai must be active.
		if ( ! class_exists( 'NvoosGraphifyAi\\Plugin' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'NV oOS Graphify — Platform requires the NV oOS Graphify — AI addon to be installed and activated.', 'nvoos-graphify-ai-platform' )
					);
				}
			);
			return;
		}

		if ( class_exists( 'NvoosGraphifyAiPlatform\Plugin' ) ) {
			\NvoosGraphifyAiPlatform\Plugin::instance()->register();
		}
	},
	10
);
