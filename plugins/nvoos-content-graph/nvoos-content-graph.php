<?php
/**
 * Plugin Name:  NV oOS Content Graph
 * Plugin URI:   https://github.com/nvdigitalsolutions/nvoos-content-graph
 * Description:  Visual knowledge graph for WordPress. Maps your content into an interactive, navigable graph. Zero API keys required.
 * Version:      1.0.3
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author:       NV Digital Solutions
 * Author URI:   https://nvdigitalsolutions.com
 * License:      GPL-3.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:  nvoos-content-graph
 * Domain Path:  /languages
 *
 * @package NvoosContentGraph
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NVOOS_CONTENT_GRAPH_VERSION', '1.0.3' );
define( 'NVOOS_CONTENT_GRAPH_FILE', __FILE__ );
define( 'NVOOS_CONTENT_GRAPH_PATH', plugin_dir_path( __FILE__ ) );
define( 'NVOOS_CONTENT_GRAPH_URL', plugin_dir_url( __FILE__ ) );
define( 'NVOOS_CONTENT_GRAPH_DB_VERSION', '2' );

// ─── Autoloader ────────────────────────────────────────────────
$autoload = NVOOS_CONTENT_GRAPH_PATH . 'vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'NvoosContentGraph\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = NVOOS_CONTENT_GRAPH_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

// ─── Activation ────────────────────────────────────────────────
register_activation_hook(
	__FILE__,
	static function (): void {
		if ( PHP_VERSION_ID < 80100 ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'NV oOS Content Graph requires PHP 8.1 or higher.', 'nvoos-content-graph' ),
				esc_html__( 'Plugin Activation Failed', 'nvoos-content-graph' ),
				array( 'back_link' => true )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Create custom tables via dbDelta.
		if ( class_exists( 'NvoosContentGraph\\Graph\\Db' ) ) {
			\NvoosContentGraph\Graph\Db::install();
		}

		// Seed default settings.
		add_option(
			'nvoos_content_graph_settings',
			\NvoosContentGraph\Schema::defaultSettings(),
			'',
			false
		);

		// Schedule the recurring rebuild cron to match the default settings.
		if ( class_exists( 'NvoosContentGraph\\Plugin' ) ) {
			\NvoosContentGraph\Plugin::instance()->syncRebuildSchedule( true );
		}

		// Trigger initial build after a short delay.
		if ( ! wp_next_scheduled( 'nvoos_content_graph/initial_build' ) ) {
			wp_schedule_single_event( time() + 10, 'nvoos_content_graph/initial_build' );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_unschedule_hook( 'nvoos_content_graph/cron_build' );
		wp_unschedule_hook( 'nvoos_content_graph/cron_enrich' );
		wp_unschedule_hook( 'nvoos_content_graph/initial_build' );
	}
);

// ─── Boot ──────────────────────────────────────────────────────
add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( 'NvoosContentGraph\\Plugin' ) ) {
			\NvoosContentGraph\Plugin::instance()->register();
		}
	},
	10
);

// ─── Public API ────────────────────────────────────────────────

if ( ! function_exists( 'nvoos_content_graph_get_tool_registry' ) ) :
	/**
	 * Get the tool registry instance.
	 *
	 * Consumer addons call this to register their tools.
	 *
	 * @since 1.0.0
	 * @return \NvoosContentGraph\ToolRegistry
	 */
	function nvoos_content_graph_get_tool_registry(): \NvoosContentGraph\ToolRegistry {
		return \NvoosContentGraph\Plugin::instance()->getToolRegistry();
	}
endif;

if ( ! function_exists( 'nvoos_content_graph_get_setting' ) ) :
	/**
	 * Get a specific setting value.
	 *
	 * @since 1.0.0
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function nvoos_content_graph_get_setting( string $key, $default = null ) {
		return \NvoosContentGraph\Settings::get( $key, $default );
	}
endif;

if ( ! function_exists( 'nvoos_content_graph_is_enabled' ) ) :
	/**
	 * Check whether NV oOS Content Graph is enabled.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	function nvoos_content_graph_is_enabled(): bool {
		$settings = \NvoosContentGraph\Settings::all();
		return ! empty( $settings['enabled'] );
	}
endif;
