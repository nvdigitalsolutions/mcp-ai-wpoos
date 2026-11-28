<?php
/**
 * SQLite engine adapter — tests and single-file analytics only.
 *
 * Proposal 167 section 4.3.4. SQLite has no row-level security, no server-side
 * statement timeout and no read-only transaction started from `BEGIN`. What it
 * does have is `PRAGMA query_only = ON`, which refuses every write at the
 * connection level — the same *shape* of guarantee the Postgres role provides,
 * which is why the portable executor tests can prove the read-only path without
 * a server.
 *
 * A scope that sources a row filter from the tenant is refused rather than
 * silently unfiltered: there is no tenant context to bind.
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

use Nvoos\Database\Domain\ValueObject\Database\CompiledStatement;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\DatabaseLimits;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;

/**
 * SQLite session behaviour.
 */
final class WP_MCP_AI_Db_Engine_Sqlite extends WP_MCP_AI_Db_Engine {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return ConnectionDescriptor::SQLITE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_port(): int {
		return 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ConnectionDescriptor $connection Descriptor, for the database path.
	 */
	public function dsn( ConnectionDescriptor $connection ): string {
		if ( '' === trim( $connection->database ) ) {
			return 'sqlite::memory:';
		}

		return 'sqlite:' . $connection->database;
	}

	/**
	 * {@inheritDoc}
	 */
	public function options(): array {
		return array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_STRINGIFY_FETCHES  => false,
			PDO::ATTR_PERSISTENT         => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function begin_statement(): string {
		return 'BEGIN DEFERRED';
	}

	/**
	 * {@inheritDoc}
	 */
	public function begin_write_statement(): string {
		return 'BEGIN IMMEDIATE';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ConnectionDescriptor $connection       Descriptor.
	 * @param ScopeDescriptor      $scope            Scope.
	 * @param DatabaseLimits       $limits           Caps, including the timeout.
	 * @param string               $application_name Audit label for the session.
	 * @param string|null          $tenant_id        Tenant value, bound when non-empty.
	 */
	public function session_statements(
		ConnectionDescriptor $connection,
		ScopeDescriptor $scope,
		DatabaseLimits $limits,
		string $application_name,
		?string $tenant_id
	): array {
		return array(
			// Connection-level read-only. `PRAGMA` takes no placeholders, but the
			// value is a constant, so there is nothing to bind.
			new CompiledStatement( 'PRAGMA query_only = ON' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * SQLite's read-only guarantee is the connection pragma, so an approved write
	 * has to clear it. The `PRAGMA` is constant, and the transaction is opened by
	 * the writer with `BEGIN IMMEDIATE`.
	 *
	 * @param ConnectionDescriptor $connection       Descriptor.
	 * @param ScopeDescriptor      $scope            Scope.
	 * @param DatabaseLimits       $limits           Caps.
	 * @param string               $application_name Audit label.
	 * @param string|null          $tenant_id        Tenant value.
	 */
	public function write_session_statements(
		ConnectionDescriptor $connection,
		ScopeDescriptor $scope,
		DatabaseLimits $limits,
		string $application_name,
		?string $tenant_id
	): array {
		return array( new CompiledStatement( 'PRAGMA query_only = OFF' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param CompiledStatement $statement Statement to explain.
	 * @param bool              $analyze   Whether execution is permitted.
	 */
	public function explain_statement( CompiledStatement $statement, bool $analyze ): CompiledStatement {
		return new CompiledStatement( 'EXPLAIN QUERY PLAN ' . $statement->sql, $statement->bindings );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ConnectionDescriptor $connection Descriptor, for the probed version.
	 */
	public function supports_explain_analyze( ConnectionDescriptor $connection ): bool {
		return false;
	}
}
