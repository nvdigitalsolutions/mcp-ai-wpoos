<?php
/**
 * Health and privilege probe for a connection.
 *
 * Proposal 167 amendment A1. The probe answers "what can this credential do?"
 * by **introspection**, not by attempting a write. A temporary-table write test
 * was rejected during research because it mutates the target database, can fail
 * for reasons unrelated to grants, and leaves an artefact when the cleanup path
 * is interrupted.
 *
 * The result drives three decisions:
 *
 *  - `reachable`: whether the connection can serve at all;
 *  - `bypassesRls`: a role with `BYPASSRLS` (or superuser) disqualifies every
 *    tenant-filtered scope on that connection — the row filter would be the only
 *    control, which is a deliberate step down an operator must take knowingly;
 *  - `canWrite`: recorded and surfaced, so an operator can see that a "read-only"
 *    connection is not actually read-only at the role level.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Service\Database;

use PDO;
use Throwable;

use Nvoos\Database\Domain\Contract\Database\ConnectionFactoryInterface;
use Nvoos\Database\Domain\Contract\Database\ConnectionProbeInterface;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\ProbeResult;

/**
 * Privilege introspection.
 */
class PdoProbe implements ConnectionProbeInterface {

	/**
	 * Connection factory.
	 *
	 * @var ConnectionFactoryInterface
	 */
	private ConnectionFactoryInterface $factory;

	/**
	 * Constructor.
	 *
	 * @param ConnectionFactoryInterface $factory Factory.
	 */
	public function __construct( ConnectionFactoryInterface $factory ) {
		$this->factory = $factory;
	}

	/**
	 * Probe a connection.
	 *
	 * Never throws: an unreachable connection is a recorded state, because the
	 * caller (a settings screen or `db_test_connection`) needs to render the
	 * reason rather than handle an exception.
	 *
	 * @param ConnectionDescriptor $connection Descriptor.
	 */
	public function probe( ConnectionDescriptor $connection ): ProbeResult {
		try {
			$pdo = $this->factory->open( $connection );
		} catch ( DatabaseRefusal $refusal ) {
			return ProbeResult::unreachable( $refusal->getRefusalCode() );
		} catch ( Throwable $error ) {
			return ProbeResult::unreachable( 'db_connection_unavailable' );
		}

		try {
			$result = match ( $connection->engine ) {
				ConnectionDescriptor::PGSQL   => $this->probe_pgsql( $pdo ),
				ConnectionDescriptor::MYSQL   => $this->probe_mysql( $pdo, false ),
				ConnectionDescriptor::MARIADB => $this->probe_mysql( $pdo, true ),
				ConnectionDescriptor::SQLITE  => $this->probe_sqlite( $pdo, $connection ),
				default                       => ProbeResult::unreachable( 'db_connection_unavailable' ),
			};
		} catch ( Throwable $error ) {
			return ProbeResult::unreachable( 'db_connection_unavailable' );
		}

		return $result;
	}

	/**
	 * PostgreSQL introspection.
	 *
	 * @param PDO $pdo Handle.
	 */
	private function probe_pgsql( PDO $pdo ): ProbeResult {
		$role = $pdo->query(
			"SELECT current_user AS role,
			        current_setting('server_version') AS server_version,
			        current_setting('transaction_read_only') AS transaction_read_only,
			        r.rolsuper,
			        r.rolbypassrls,
			        r.rolcreatedb
			   FROM pg_roles r
			  WHERE r.rolname = current_user"
		)->fetch( PDO::FETCH_ASSOC );

		if ( ! is_array( $role ) ) {
			return ProbeResult::unreachable( 'db_connection_unavailable' );
		}

		$can_write = $this->pgsql_has_write_grant( $pdo )
			|| (bool) $role['rolsuper']
			|| $this->pgsql_owns_writable_relation( $pdo );

		$can_create = (bool) $role['rolsuper']
			|| (bool) $role['rolcreatedb']
			|| $this->pgsql_can_create_in_any_schema( $pdo );

		return new ProbeResult(
			reachable: true,
			serverVersion: (string) $role['server_version'],
			role: (string) $role['role'],
			transactionReadOnly: $this->to_bool( $role['transaction_read_only'] ?? false ),
			canWrite: $can_write,
			canCreateSchema: $can_create,
			bypassesRls: (bool) $role['rolsuper'] || (bool) $role['rolbypassrls'],
			checkedAt: time(),
		);
	}

	/**
	 * Whether the role holds any INSERT/UPDATE/DELETE grant.
	 *
	 * @param PDO $pdo Handle.
	 */
	private function pgsql_has_write_grant( PDO $pdo ): bool {
		$row = $pdo->query(
			"SELECT EXISTS (
			        SELECT 1
			          FROM information_schema.role_table_grants
			         WHERE grantee = current_user
			           AND privilege_type IN ('INSERT', 'UPDATE', 'DELETE')
			     ) AS can_write"
		)->fetch( PDO::FETCH_ASSOC );

		return is_array( $row ) && $this->to_bool( $row['can_write'] ?? false );
	}

	/**
	 * Whether the role owns a relation it could therefore write to.
	 *
	 * Ownership is the privilege hole a grant-only check misses: an owner writes
	 * without an explicit `INSERT` grant.
	 *
	 * @param PDO $pdo Handle.
	 */
	private function pgsql_owns_writable_relation( PDO $pdo ): bool {
		$row = $pdo->query(
			"SELECT EXISTS (
			        SELECT 1
			          FROM pg_class c
			          JOIN pg_namespace n ON n.oid = c.relnamespace
			         WHERE c.relkind IN ('r', 'p')
			           AND n.nspname NOT IN ('pg_catalog', 'information_schema')
			           AND n.nspname NOT LIKE 'pg_toast%'
			           AND pg_has_role(current_user, c.relowner, 'USAGE')
			     ) AS owns"
		)->fetch( PDO::FETCH_ASSOC );

		return is_array( $row ) && $this->to_bool( $row['owns'] ?? false );
	}

	/**
	 * Whether the role may create in any non-system schema.
	 *
	 * @param PDO $pdo Handle.
	 */
	private function pgsql_can_create_in_any_schema( PDO $pdo ): bool {
		$rows = $pdo->query(
			"SELECT n.nspname
			   FROM pg_namespace n
			  WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
			    AND n.nspname NOT LIKE 'pg_%'
			  LIMIT 50"
		)->fetchAll( PDO::FETCH_COLUMN );

		if ( ! is_array( $rows ) ) {
			return false;
		}

		$statement = $pdo->prepare( "SELECT has_schema_privilege(current_user, :s, 'CREATE')" );

		foreach ( $rows as $schema ) {
			$statement->execute( array( ':s' => (string) $schema ) );

			if ( $this->to_bool( $statement->fetchColumn() ) ) {
				$statement->closeCursor();

				return true;
			}
		}

		$statement->closeCursor();

		return false;
	}

	/**
	 * MySQL/MariaDB introspection.
	 *
	 * `SHOW GRANTS` is a statement, not a write: it is the only portable way to
	 * see global and database-level grants without assembling three
	 * `information_schema` joins whose column names differ across forks.
	 *
	 * @param PDO  $pdo     Handle.
	 * @param bool $mariadb Whether this is MariaDB.
	 */
	private function probe_mysql( PDO $pdo, bool $mariadb ): ProbeResult {
		$row = $pdo->query(
			'SELECT CURRENT_USER() AS role, VERSION() AS server_version, @@read_only AS read_only'
		)->fetch( PDO::FETCH_ASSOC );

		if ( ! is_array( $row ) ) {
			return ProbeResult::unreachable( 'db_connection_unavailable' );
		}

		$grants = $pdo->query( 'SHOW GRANTS FOR CURRENT_USER()' )->fetchAll( PDO::FETCH_COLUMN );
		$text   = is_array( $grants ) ? strtoupper( implode( "\n", array_map( 'strval', $grants ) ) ) : '';

		$can_write = (bool) preg_match( '/\b(ALL PRIVILEGES|INSERT|UPDATE|DELETE)\b/', $text );
		$can_create = (bool) preg_match( '/\b(ALL PRIVILEGES|CREATE)\b/', $text );

		return new ProbeResult(
			reachable: true,
			serverVersion: (string) $row['server_version'],
			role: (string) $row['role'],
			transactionReadOnly: $this->to_bool( $row['read_only'] ?? false ),
			canWrite: $can_write,
			canCreateSchema: $can_create,
			bypassesRls: false,
			checkedAt: time(),
		);
	}

	/**
	 * SQLite introspection.
	 *
	 * @param PDO                  $pdo        Handle.
	 * @param ConnectionDescriptor $connection Descriptor, for the file path.
	 */
	private function probe_sqlite( PDO $pdo, ConnectionDescriptor $connection ): ProbeResult {
		$version = $pdo->query( 'SELECT sqlite_version()' )->fetchColumn();
		$path    = $connection->database;

		return new ProbeResult(
			reachable: true,
			serverVersion: (string) $version,
			role: 'sqlite',
			transactionReadOnly: false,
			canWrite: '' !== $path && ':memory:' !== $path && is_writable( $path ),
			canCreateSchema: '' === $path || ':memory:' === $path,
			bypassesRls: false,
			checkedAt: time(),
		);
	}

	/**
	 * Normalise a driver boolean.
	 *
	 * pgsql returns `on`/`off` strings for `current_setting`, and PDO may hand
	 * back `1`/`0`, `t`/`f` or `true`/`false` depending on the driver.
	 *
	 * @param mixed $value Raw value.
	 */
	private function to_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			return 1 === $value;
		}

		if ( ! is_string( $value ) ) {
			return false;
		}

		return in_array( strtolower( trim( $value ) ), array( '1', 't', 'true', 'on', 'yes' ), true );
	}
}
