<?php
/**
 * Plugin Name:  NV oOS Graphify — AI
 * Plugin URI:   https://github.com/nvdigitalsolutions/nvoos-graphify-ai
 * Description:  AI chat assistant addon for NV oOS Graphify. Adds chat, 13 AI providers, AI tools, embeddings, and agent memory to your knowledge graph. One install, one API key.
 * Version:      1.0.0-dev
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: nvoos-graphify
 * Author:       NV Digital Solutions
 * Author URI:   https://nvdigitalsolutions.com
 * License:      Proprietary
 * License URI:  https://nvdigitalsolutions.com/license
 * Text Domain:  nvoos-graphify-ai
 * Domain Path:  /languages
 *
 * @package NvoosGraphifyAi
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NVOOS_GRAPHIFY_AI_VERSION', '1.0.0-dev' );
define( 'NVOOS_GRAPHIFY_AI_FILE', __FILE__ );
define( 'NVOOS_GRAPHIFY_AI_PATH', plugin_dir_path( __FILE__ ) );
define( 'NVOOS_GRAPHIFY_AI_URL', plugin_dir_url( __FILE__ ) );

// Autoloader — Composer primary, spl fallback.
$autoload = NVOOS_GRAPHIFY_AI_PATH . 'vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

spl_autoload_register(
	static function ( string $fqcn ): void {
		$prefix = 'NvoosGraphifyAi\\';
		if ( 0 !== strpos( $fqcn, $prefix ) ) {
			return;
		}
		$relative = substr( $fqcn, strlen( $prefix ) );
		$file     = NVOOS_GRAPHIFY_AI_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

// Activation guard: nvoos-graphify must be active.
if ( ! function_exists( 'nvoos_graphify_is_enabled' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'NV oOS Graphify — AI requires the NV oOS Graphify plugin to be installed and activated.', 'nvoos-graphify-ai' )
			);
		}
	);
	return;
}

// Boot — after nvoos-graphify (priority 10).
add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( 'NvoosGraphifyAi\\Plugin' ) ) {
			\NvoosGraphifyAi\Plugin::instance()->register();
		}
	},
	20
);
