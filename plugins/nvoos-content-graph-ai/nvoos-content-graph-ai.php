<?php
/**
 * Plugin Name:  NV oOS Content Graph — AI
 * Plugin URI:   https://github.com/nvdigitalsolutions/nvoos-content-graph-ai
 * Description:  AI chat assistant addon for NV oOS Content Graph. Adds chat, 13 AI providers, AI tools, embeddings, and agent memory to your knowledge graph. One install, one API key.
 * Version:      1.0.4
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: nvoos-content-graph
 * Author:       NV Digital Solutions
 * Author URI:   https://nvdigitalsolutions.com
 * License:      Proprietary
 * License URI:  https://nvdigitalsolutions.com/license
 * Text Domain:  nvoos-content-graph-ai
 * Domain Path:  /languages
 *
 * @package NvoosContentGraphAi
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NVOOS_CONTENT_GRAPH_AI_VERSION', '1.0.4' );
define( 'NVOOS_CONTENT_GRAPH_AI_FILE', __FILE__ );
define( 'NVOOS_CONTENT_GRAPH_AI_PATH', plugin_dir_path( __FILE__ ) );
define( 'NVOOS_CONTENT_GRAPH_AI_URL', plugin_dir_url( __FILE__ ) );

// Autoloader — Composer primary, spl fallback.
$autoload = NVOOS_CONTENT_GRAPH_AI_PATH . 'vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

spl_autoload_register(
	static function ( string $fqcn ): void {
		$prefix = 'NvoosContentGraphAi\\';
		if ( 0 !== strpos( $fqcn, $prefix ) ) {
			return;
		}
		$relative = substr( $fqcn, strlen( $prefix ) );
		$file     = NVOOS_CONTENT_GRAPH_AI_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

// ─── Lib autoloader (nvoos/core + nvoos/wordpress-adapter) ─────
// Autoload framework libraries from the monorepo lib/ directory so
// the addon works without requiring its own composer install.
// lib/ is at the repo root, a sibling of plugins/ — go up 2 levels.
$libRoot = dirname( NVOOS_CONTENT_GRAPH_AI_PATH, 2 ) . '/lib/';
spl_autoload_register(
	static function ( string $class ) use ( $libRoot ): void {
		$prefixes = array(
			'Nvoos\\Core\\'      => $libRoot . 'core/src/',
			'Nvoos\\WordPress\\' => $libRoot . 'wordpress-adapter/src/',
		);
		foreach ( $prefixes as $prefix => $baseDir ) {
			if ( 0 === strpos( $class, $prefix ) ) {
				$relative = substr( $class, strlen( $prefix ) );
				$file     = $baseDir . str_replace( '\\', '/', $relative ) . '.php';
				if ( file_exists( $file ) ) {
					require_once $file;
				}
				return;
			}
		}
	}
);

// ─── Activation — migrate legacy plaintext keys to encrypted storage ──
// Runs before plugins_loaded; the parent plugin is guaranteed active by
// the Requires Plugins header, so its Remote\Crypto primitive is present.
register_activation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( 'NvoosContentGraphAi\Security\CredentialStore' ) ) {
			\NvoosContentGraphAi\Security\CredentialStore::migrateAll();
			update_option( \NvoosContentGraphAi\Security\CredentialStore::MIGRATION_FLAG, true, false );
		}
	}
);

// Boot — checks run at plugins_loaded (priority 5) so all plugin files
// have been included, avoiding false errors from alphabetical load order
// (nvoos-content-graph-ai/ loads before nvoos-content-graph/ because '-' < '/' in ASCII).
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
						esc_html__( 'NV oOS Content Graph — AI requires the NV oOS Content Graph plugin to be installed and activated.', 'nvoos-content-graph-ai' )
					);
				}
			);
			return;
		}

		if ( class_exists( 'NvoosContentGraphAi\\Plugin' ) ) {
			\NvoosContentGraphAi\Plugin::instance()->register();
		}
	},
	5
);
