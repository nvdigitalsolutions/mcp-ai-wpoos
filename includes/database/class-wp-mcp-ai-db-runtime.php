<?php
/**
 * Availability guard and loader for the database toolkit adapters.
 *
 * Proposal 167 section 0.9 (decision D14/D15). This class answers one question —
 * "can this site run the database toolkit at all?" — and loads the adapter files
 * when it can. It is deliberately the **only** class in `includes/database/` that
 * is required unconditionally, so a base-only install pays for one file that
 * returns `false` and nothing else.
 *
 * ## What availability is *not*
 *
 * Availability is **not** gated on `enable_oos_engine`, `wp_mcp_ai_oos_engine_enabled()`
 * or any other chat-routing flag. Those flags decide which code path handles a
 * conversation; they do not decide which classes exist. Gating on them would
 * disable the toolkit on every install that runs chat through the legacy path,
 * which is not a security property and not what the flag means.
 *
 * The only two reasons availability is false are:
 *
 *  1. the Pro add-on is not installed, so `addons/pro/lib/database/` does not
 *     exist (decision D15 keeps the kernel there); or
 *  2. PHP is older than 8.2, which the kernel requires for `readonly` classes
 *     and enums.
 *
 * A missing `pdo_pgsql` is **not** an availability failure: the toolkit loads,
 * lists connections, and refuses a Postgres call with `db_extension_missing`.
 * That is a per-connection condition an operator can fix, not a build property.
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Nvoos\Database\Domain\Service\Database\RefusalCode;

/**
 * Availability guard and adapter loader.
 */
final class WP_MCP_AI_Db_Runtime {

	/**
	 * Minimum PHP version the kernel supports.
	 */
	public const MIN_PHP_VERSION = '8.2.0';

	/**
	 * Option that records the last availability reason, for Site Health only.
	 */
	public const STATUS_OPTION = 'wp_mcp_ai_database_runtime_status';

	/**
	 * Whether the adapter files have been required.
	 *
	 * @var bool
	 */
	private static bool $loaded = false;

	/**
	 * Files loaded after the kernel, in dependency order.
	 *
	 * @var list<string>
	 */
	private const FILES = array(
		'class-wp-mcp-ai-db-cache-interface.php',
		'class-wp-mcp-ai-db-array-cache.php',
		'class-wp-mcp-ai-db-transient-cache.php',
		'class-wp-mcp-ai-db-limit-defaults.php',
		'class-wp-mcp-ai-db-credential-interface.php',
		'class-wp-mcp-ai-db-encrypted-credential-store.php',
		'class-wp-mcp-ai-db-site-database.php',
		'class-wp-mcp-ai-db-engine.php',
		'class-wp-mcp-ai-db-engine-pgsql.php',
		'class-wp-mcp-ai-db-engine-mysql.php',
		'class-wp-mcp-ai-db-engine-mariadb.php',
		'class-wp-mcp-ai-db-engine-sqlite.php',
		'class-wp-mcp-ai-db-connection-factory.php',
		'class-wp-mcp-ai-db-probe.php',
		'class-wp-mcp-ai-db-catalogue.php',
		'class-wp-mcp-ai-db-registry.php',
		'class-wp-mcp-ai-db-scope-registry.php',
		'class-wp-mcp-ai-db-connection-registry.php',
		'class-wp-mcp-ai-db-audit-log.php',
		'class-wp-mcp-ai-db-redactor.php',
		'class-wp-mcp-ai-db-proposal-store.php',
		'class-wp-mcp-ai-db-write-compiler.php',
		'class-wp-mcp-ai-db-writer.php',
		'class-wp-mcp-ai-db-executor.php',
		'class-wp-mcp-ai-db-executor-builder.php',
	);

	/**
	 * Connection-provider files, relative to `includes/connections/`.
	 *
	 * @var list<string>
	 */
	private const CONNECTION_FILES = array(
		'class-wp-mcp-ai-connection-provider-interface.php',
		'class-wp-mcp-ai-connection-resolver.php',
		'class-wp-mcp-ai-native-connection-provider.php',
		'class-wp-mcp-ai-remote-site-connection-provider.php',
	);

	/**
	 * Absolute path to the kernel package.
	 */
	public static function kernel_root(): string {
		if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			return rtrim( (string) WP_MCP_AI_PRO_PATH, '/\\' ) . '/lib/database';
		}

		return dirname( __DIR__, 2 ) . '/addons/pro/lib/database';
	}

	/**
	 * Whether the toolkit can run on this site.
	 */
	public static function is_available(): bool {
		return '' === self::reason();
	}

	/**
	 * Why the toolkit is unavailable; an empty string when it is available.
	 *
	 * The reason is a stable machine code, not a translated sentence, so Site
	 * Health and the admin notice can map it.
	 */
	public static function reason(): string {
		if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
			return 'php_version';
		}

		if ( class_exists( RefusalCode::class, false ) ) {
			return '';
		}

		if ( is_file( self::kernel_root() . '/autoload.php' ) ) {
			return '';
		}

		return 'kernel_missing';
	}

	/**
	 * A human-readable explanation for the admin notice.
	 */
	public static function reason_label(): string {
		switch ( self::reason() ) {
			case 'php_version':
				return sprintf(
					/* translators: %s: minimum PHP version. */
					__( 'The database toolkit requires PHP %s or newer.', 'mcp-ai-wpoos' ),
					self::MIN_PHP_VERSION
				);
			case 'kernel_missing':
				return __( 'The database toolkit requires the Pro add-on, which provides its query kernel.', 'mcp-ai-wpoos' );
			case '':
				return __( 'The database toolkit is available.', 'mcp-ai-wpoos' );
			default:
				return __( 'The database toolkit is unavailable.', 'mcp-ai-wpoos' );
		}
	}

	/**
	 * Load the kernel and the adapter layer.
	 *
	 * Idempotent. Returns false — without throwing — when the toolkit cannot run,
	 * so a caller can simply skip registration.
	 */
	public static function load(): bool {
		if ( self::$loaded ) {
			return self::is_available();
		}

		if ( ! self::is_available() ) {
			return false;
		}

		if ( ! class_exists( RefusalCode::class, false ) ) {
			require_once self::kernel_root() . '/autoload.php';
		}

		// The kernel autoloader is registered by autoload.php, but touching a
		// class here makes a broken autoload fail at the loader rather than
		// halfway through a tool call.
		if ( ! class_exists( RefusalCode::class ) ) {
			return false;
		}

		foreach ( self::FILES as $file ) {
			$path = __DIR__ . '/' . $file;

			if ( is_file( $path ) ) {
				require_once $path;
			}
		}

		foreach ( self::CONNECTION_FILES as $file ) {
			$path = dirname( __DIR__ ) . '/connections/' . $file;

			if ( is_file( $path ) ) {
				require_once $path;
			}
		}

		self::$loaded = true;

		return true;
	}

	/**
	 * Whether the adapter layer has been loaded in this request.
	 */
	public static function is_loaded(): bool {
		return self::$loaded;
	}

	/**
	 * Record the availability reason for Site Health.
	 *
	 * Called from the toolkit initialiser so a support engineer can see *why* the
	 * toolkit is missing without reading the file system. The status is only
	 * written when it changes: `checked_at` would otherwise make every request a
	 * database write.
	 */
	public static function record_status(): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		$status = array(
			'available'   => self::is_available(),
			'reason'      => self::reason(),
			'php'         => PHP_VERSION,
			'kernel_root' => self::kernel_root(),
		);

		$existing = get_option( self::STATUS_OPTION, null );

		if (
			is_array( $existing )
			&& ( $existing['available'] ?? null ) === $status['available']
			&& ( $existing['reason'] ?? null ) === $status['reason']
			&& ( $existing['php'] ?? null ) === $status['php']
			&& ( $existing['kernel_root'] ?? null ) === $status['kernel_root']
		) {
			return;
		}

		$status['checked_at'] = time();

		update_option( self::STATUS_OPTION, $status, false );
	}
}
