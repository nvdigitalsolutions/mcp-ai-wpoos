<?php
/**
 * PHPUnit bootstrap for the Schedule Anything Platform addon.
 *
 * The WordPress test environment itself is bootstrapped by the root test
 * suite; this file's job is to define the addon's constants and require its
 * PHP classes so addon tests can run inside the shared suite.
 *
 * Only constants and class definitions are loaded - no file-scope hooks -
 * so it is safe to require from the root bootstrap.
 *
 * @package Schedule_Anything
 * @since   0.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	return; // Not inside the WordPress test environment - nothing to do.
}

// Keep in sync with schedule-anything-platform.php.
if ( ! defined( 'SA_PLATFORM_VERSION' ) ) {
	define( 'SA_PLATFORM_VERSION', '0.1.2' );
}

if ( ! defined( 'SA_PLATFORM_FILE' ) ) {
	define( 'SA_PLATFORM_FILE', dirname( __DIR__ ) . '/schedule-anything-platform.php' );
}

if ( ! defined( 'SA_PLATFORM_PATH' ) ) {
	define( 'SA_PLATFORM_PATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'SA_PLATFORM_URL' ) ) {
	define( 'SA_PLATFORM_URL', '' );
}

require_once SA_PLATFORM_PATH . 'includes/class-sa-plugin.php';
require_once SA_PLATFORM_PATH . 'includes/class-sa-cross-tenant-security.php';
require_once SA_PLATFORM_PATH . 'includes/class-sa-toolkit-manager.php';
require_once SA_PLATFORM_PATH . 'includes/class-sa-multisite-provisioner.php';
require_once SA_PLATFORM_PATH . 'includes/class-sa-usage-tracker.php';
require_once SA_PLATFORM_PATH . 'includes/rest/class-sa-rest-controller.php';

// One deliberate hook: simulate a booted single-site platform. Production
// registers the capability seam in SA_Plugin::init(), which the shared
// suite never runs. Without it the Pro SA controllers default to
// sa_manage_workspace (they proxy "platform active" off
// SA_PLATFORM_VERSION, which IS defined above), and admin-driven tests
// 403. On single-site the filter resolves to manage_options - the exact
// production behaviour (Proposal 082, D5); wp-phpunit restores hook
// tables between tests, so this does not leak into other suites.
add_filter( 'wp_mcp_ai_pro_schedule_rest_capability', array( 'SA_Plugin', 'filter_schedule_rest_capability' ) );

// TEMP DEBUG: trace every write/delete of the shared settings option.
add_action(
	'update_option_wp_mcp_ai_settings',
	static function ( $old, $value ) {
		$bt = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 6 ); // phpcs:ignore
		$who = '';
		foreach ( $bt as $f ) {
			if ( isset( $f['class'] ) && false !== strpos( $f['class'], 'Test' ) ) {
				$who = $f['class'] . '::' . ( isset( $f['function'] ) ? $f['function'] : '?' );
				break;
			}
		}
		fwrite( STDERR, 'SA_DEBUG_UPDATE keys=' . wp_json_encode( array_keys( (array) $value ) ) . ' who=' . $who . PHP_EOL ); // phpcs:ignore
	},
	10,
	2
);
add_action(
	'delete_option_wp_mcp_ai_settings',
	static function () {
		$bt = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 6 ); // phpcs:ignore
		$who = '';
		foreach ( $bt as $f ) {
			if ( isset( $f['class'] ) && false !== strpos( $f['class'], 'Test' ) ) {
				$who = $f['class'] . '::' . ( isset( $f['function'] ) ? $f['function'] : '?' );
				break;
			}
		}
		fwrite( STDERR, 'SA_DEBUG_DELETE who=' . $who . PHP_EOL ); // phpcs:ignore
	}
);
