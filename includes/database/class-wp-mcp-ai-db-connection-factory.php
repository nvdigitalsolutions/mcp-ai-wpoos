<?php
/**
 * Builds PDO handles for a connection descriptor.
 *
 * Proposal 167 sections 4.1 and 4.2. This class is the only place a PDO is
 * constructed, which is what makes the option map auditable: one table, one
 * assertion helper, one place to review.
 *
 * ## Defence in depth at open time
 *
 * Before the handle exists, the factory re-checks:
 *
 *  - the driver extension is loaded (`db_extension_missing`, not a fatal);
 *  - the descriptor is not the WordPress database ({@see WP_MCP_AI_Db_Site_Database});
 *  - a SQLite path sits under a declared allowlist root.
 *
 * Those checks are repeated from the registry on purpose. The registry check runs
 * when an operator saves a record; this one runs when an assistant uses it, and
 * the two can disagree if `wp-config.php` changed in between.
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

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\Contract\Database\ConnectionFactoryInterface;

/**
 * Engine resolution and PDO construction.
 *
 * Not `final`: a host that needs a different handle (a connection pooler, or the
 * portable adapter tests) can replace {@see self::open()} while keeping the
 * DSN/option contract and the site-database guard.
 */
class WP_MCP_AI_Db_Connection_Factory implements ConnectionFactoryInterface {

	/**
	 * Credential resolver.
	 *
	 * @var WP_MCP_AI_Db_Credential_Interface
	 */
	private WP_MCP_AI_Db_Credential_Interface $credentials;

	/**
	 * Allowlisted SQLite roots.
	 *
	 * @var list<string>
	 */
	private array $sqlite_roots;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Db_Credential_Interface $credentials  Credential resolver.
	 * @param string[]                          $sqlite_roots Allowlisted filesystem roots for SQLite files.
	 */
	public function __construct( WP_MCP_AI_Db_Credential_Interface $credentials, array $sqlite_roots = array() ) {
		$this->credentials  = $credentials;
		$this->sqlite_roots = array_values( array_filter( array_map( 'strval', $sqlite_roots ) ) );
	}

	/**
	 * The engine adapter for a descriptor.
	 *
	 * @param ConnectionDescriptor $connection Descriptor.
	 * @throws DatabaseRefusal When no adapter matches the engine.
	 */
	public function engine( ConnectionDescriptor $connection ): WP_MCP_AI_Db_Engine {
		switch ( $connection->engine ) {
			case ConnectionDescriptor::PGSQL:
				return new WP_MCP_AI_Db_Engine_Pgsql();
			case ConnectionDescriptor::MYSQL:
				return new WP_MCP_AI_Db_Engine_Mysql();
			case ConnectionDescriptor::MARIADB:
				return new WP_MCP_AI_Db_Engine_Mariadb();
			case ConnectionDescriptor::SQLITE:
				return new WP_MCP_AI_Db_Engine_Sqlite();
		}

		throw DatabaseRefusal::of(
			RefusalCode::CONNECTION_UNAVAILABLE,
			'No adapter for engine ' . $connection->engine . '.'
		);
	}

	/**
	 * Open a connection.
	 *
	 * @param ConnectionDescriptor $connection Descriptor.
	 * @return PDO Open handle; the caller must let it go out of scope to close it.
	 * @throws DatabaseRefusal When the connection cannot be opened.
	 */
	public function open( ConnectionDescriptor $connection ): PDO {
		$engine = $this->engine( $connection );

		if ( ! extension_loaded( $engine->extension() ) ) {
			throw DatabaseRefusal::of(
				RefusalCode::EXTENSION_MISSING,
				'PHP extension ' . $engine->extension() . ' is not loaded.'
			);
		}

		WP_MCP_AI_Db_Site_Database::assert_not_site_database( $connection );

		if ( ConnectionDescriptor::SQLITE === $connection->engine ) {
			$this->assert_sqlite_path( $connection->database );
		}

		$dsn     = $engine->dsn( $connection );
		$options = $engine->options();

		try {
			if ( ConnectionDescriptor::SQLITE === $connection->engine ) {
				$pdo = new PDO( $dsn, null, null, $options );
			} else {
				$credential = $this->credentials->resolve( $connection->credential );
				$pdo        = new PDO( $dsn, $credential['username'], $credential['password'], $options );
			}
		} catch ( DatabaseRefusal $refusal ) {
			throw $refusal;
		} catch ( PDOException $error ) {
			throw new DatabaseRefusal(
				( new \Nvoos\Database\Domain\Service\Database\ErrorMapper() )->fromThrowable( $error, $connection->engine ),
				( new \Nvoos\Database\Domain\Service\Database\ErrorMapper() )->auditDetail( $error ),
				$error
			);
		}

		$this->assert_options( $pdo, $connection );

		return $pdo;
	}

	/**
	 * Assert the attributes that make the driver safe.
	 *
	 * A driver that ignores `ATTR_EMULATE_PREPARES` would route statements through
	 * a path that permits stacking, so this is a hard failure rather than a
	 * warning: better a refusal than a connection that only *looks* governed.
	 *
	 * @param PDO                  $pdo        Open handle.
	 * @param ConnectionDescriptor $connection Descriptor.
	 * @throws DatabaseRefusal When the driver emulates prepared statements.
	 */
	public function assert_options( PDO $pdo, ConnectionDescriptor $connection ): void {
		if ( ConnectionDescriptor::SQLITE === $connection->engine ) {
			return;
		}

		$emulate = $pdo->getAttribute( PDO::ATTR_EMULATE_PREPARES );

		if ( false !== $emulate && 0 !== $emulate ) {
			throw DatabaseRefusal::of(
				RefusalCode::CONNECTION_UNAVAILABLE,
				'Driver is emulating prepares; refusing to use this connection.'
			);
		}
	}

	/**
	 * Ensure a SQLite file sits under an allowlisted root.
	 *
	 * @param string $path Candidate path, or `:memory:`.
	 * @throws DatabaseRefusal When the path is missing or outside every allowlisted root.
	 */
	private function assert_sqlite_path( string $path ): void {
		$path = trim( $path );

		if ( '' === $path || ':memory:' === $path ) {
			return;
		}

		$real = realpath( $path );

		if ( false === $real ) {
			throw DatabaseRefusal::of(
				RefusalCode::CONNECTION_UNAVAILABLE,
				'SQLite path does not exist: ' . $path
			);
		}

		foreach ( $this->allowed_roots() as $root ) {
			$root_real = realpath( $root );

			if ( false === $root_real ) {
				continue;
			}

			$prefix = rtrim( str_replace( '\\', '/', $root_real ), '/' ) . '/';

			if ( 0 === strpos( str_replace( '\\', '/', $real ), $prefix ) ) {
				return;
			}
		}

		throw DatabaseRefusal::of(
			RefusalCode::CONNECTION_UNAVAILABLE,
			'SQLite path is outside every allowlisted root.'
		);
	}

	/**
	 * Effective SQLite roots.
	 *
	 * @return list<string>
	 */
	private function allowed_roots(): array {
		if ( array() !== $this->sqlite_roots ) {
			return $this->sqlite_roots;
		}

		if ( defined( 'WP_MCP_AI_DB_SQLITE_ROOTS' ) && is_array( WP_MCP_AI_DB_SQLITE_ROOTS ) ) {
			return array_values( array_map( 'strval', WP_MCP_AI_DB_SQLITE_ROOTS ) );
		}

		/**
		 * Allowlist filesystem roots for SQLite-backed connections.
		 *
		 * Empty by default: a SQLite file must be explicitly allowed, because a
		 * path is a filesystem read primitive if it can point anywhere.
		 *
		 * @since 3.1.0
		 *
		 * @param list<string> $roots Absolute directory paths.
		 */
		$roots = apply_filters( 'wp_mcp_ai_database_sqlite_roots', array() );

		return is_array( $roots ) ? array_values( array_map( 'strval', $roots ) ) : array();
	}
}
