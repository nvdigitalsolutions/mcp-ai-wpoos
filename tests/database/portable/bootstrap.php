<?php

/**
 * Bootstrap for the portable database-adapter tests.
 *
 * Proposal 167 section 7.4. These tests exercise `includes/database/` without a
 * WordPress install and without a database server: the engine adapters are pure,
 * the registries accept a test-only record seed, and the executor runs against
 * SQLite in a temp file. That is the difference between "the plan says it rolls
 * back" and "a test observed it roll back".
 *
 * When the WordPress suite loads this bootstrap, WordPress has already defined
 * the functions stubbed below; every stub is guarded so the real implementation
 * wins.
 *
 * @package WP_MCP_AI\Tests\Database
 * @since   3.1.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', 'db.example.test:5432' );
}

if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', 'wordpress' );
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function __( $text ) {
		return (string) $text;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON stub.
	 *
	 * @param mixed $value Value.
	 * @return string|false
	 */
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Filter stub: returns the value unchanged.
	 *
	 * @param string $hook  Hook.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Action stub.
	 *
	 * @param string $hook Hook.
	 */
	function do_action( $hook ) {
		unset( $hook );
	}
}

if ( ! function_exists( 'wp_mcp_ai_test_options' ) ) {
	/**
	 * Option store for the stubs below.
	 *
	 * A static local rather than a global: PHPUnit includes this bootstrap inside
	 * a closure, so a file-scope variable would not be global.
	 *
	 * @return array<string, mixed>
	 */
	function &wp_mcp_ai_test_options() {
		static $options = array();

		return $options;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Option getter stub.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $name, $default = false ) {
		$options = &wp_mcp_ai_test_options();

		return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Option writer stub.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value.
	 * @param mixed  $autoload Autoload flag (ignored).
	 * @return bool
	 */
	function update_option( $name, $value, $autoload = null ) {
		unset( $autoload );

		$options         = &wp_mcp_ai_test_options();
		$options[ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Sanitiser stub.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}

$kernel = dirname( __DIR__, 3 ) . '/addons/pro/lib/database/autoload.php';

require_once $kernel;

$adapter_dir = dirname( __DIR__, 3 ) . '/includes/database';

require_once $adapter_dir . '/class-wp-mcp-ai-db-cache-interface.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-array-cache.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-credential-interface.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-encrypted-credential-store.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-site-database.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-engine.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-engine-pgsql.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-engine-mysql.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-engine-mariadb.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-engine-sqlite.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-connection-factory.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-probe.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-catalogue.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-registry.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-scope-registry.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-connection-registry.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-audit-log.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-redactor.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-proposal-store.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-write-compiler.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-writer.php';
require_once $adapter_dir . '/class-wp-mcp-ai-db-executor.php';

require_once __DIR__ . '/wp-encryption-stub.php';

$connections_dir = dirname( __DIR__, 3 ) . '/includes/connections';

require_once $connections_dir . '/class-wp-mcp-ai-connection-provider-interface.php';
require_once $connections_dir . '/class-wp-mcp-ai-connection-resolver.php';
require_once $connections_dir . '/class-wp-mcp-ai-native-connection-provider.php';
