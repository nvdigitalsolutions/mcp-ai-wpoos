<?php
/**
 * PostgreSQL engine adapter.
 *
 * Proposal 167 section 4.3.1. PostgreSQL is the reference engine: it is the only
 * one in v1 with row-level security, a real read-only transaction, and a
 * statement timeout expressed in the same unit the toolkit stores.
 *
 * ## Why `set_config()` rather than `SET LOCAL`
 *
 * `SET LOCAL statement_timeout = $1` is not valid: `SET` does not accept
 * placeholders. `set_config('statement_timeout', $1, true)` does, and its third
 * argument makes the change transaction-local — the same lifetime as
 * `SET LOCAL`, with the value still bound rather than interpolated.
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
 * PostgreSQL session behaviour.
 */
final class WP_MCP_AI_Db_Engine_Pgsql extends WP_MCP_AI_Db_Engine {

	/**
	 * Idle-in-transaction guard, in milliseconds.
	 *
	 * Twice the statement timeout: a transaction that is idle longer than this is
	 * abandoned by a caller that died, and holding it open only burns a backend.
	 */
	private const IDLE_TX_MULTIPLIER = 2;

	/**
	 * Lock wait, in milliseconds.
	 */
	private const LOCK_TIMEOUT_MS = 2000;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return ConnectionDescriptor::PGSQL;
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_port(): int {
		return 5432;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ConnectionDescriptor $connection Descriptor, for the SSL settings.
	 */
	public function dsn( ConnectionDescriptor $connection ): string {
		$dsn = sprintf(
			'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=5;application_name=nvoos-agent',
			$connection->host,
			$connection->port > 0 ? $connection->port : $this->default_port(),
			$connection->database,
			$connection->sslmode
		);

		if ( null !== $connection->sslRootCert && '' !== $connection->sslRootCert ) {
			$dsn .= ';sslrootcert=' . $connection->sslRootCert;
		}

		return $dsn;
	}

	/**
	 * {@inheritDoc}
	 */
	public function options(): array {
		return array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_EMULATE_PREPARES   => false,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_STRINGIFY_FETCHES  => false,
			PDO::ATTR_PERSISTENT         => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function begin_statement(): string {
		return 'BEGIN READ ONLY';
	}

	/**
	 * {@inheritDoc}
	 */
	public function begin_write_statement(): string {
		return 'BEGIN';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ConnectionDescriptor $connection       Descriptor.
	 * @param ScopeDescriptor      $scope            Scope, for the schema path.
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
		$statements = array(
			new CompiledStatement(
				"SELECT set_config('statement_timeout', :p0, true)",
				array( ':p0' => (string) max( 1, $limits->timeoutMs ) )
			),
			new CompiledStatement(
				"SELECT set_config('idle_in_transaction_session_timeout', :p0, true)",
				array( ':p0' => (string) max( 1, $limits->timeoutMs * self::IDLE_TX_MULTIPLIER ) )
			),
			new CompiledStatement(
				"SELECT set_config('lock_timeout', :p0, true)",
				array( ':p0' => (string) self::LOCK_TIMEOUT_MS )
			),
			new CompiledStatement(
				"SELECT set_config('search_path', :p0, true)",
				array( ':p0' => $this->search_path( $scope ) )
			),
			new CompiledStatement(
				"SELECT set_config('application_name', :p0, true)",
				array( ':p0' => $this->application_name( $application_name ) )
			),
		);

		if ( null !== $tenant_id && '' !== $tenant_id ) {
			$statements[] = new CompiledStatement(
				"SELECT set_config('app.tenant_id', :p0, true)",
				array( ':p0' => $tenant_id )
			);
		}

		return $statements;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param CompiledStatement $statement Statement to explain.
	 * @param bool              $analyze   Whether execution is permitted.
	 */
	public function explain_statement( CompiledStatement $statement, bool $analyze ): CompiledStatement {
		$sql = sprintf(
			'EXPLAIN (FORMAT JSON, ANALYZE %s, COSTS true, VERBOSE false) ',
			$analyze ? 'true' : 'false'
		);

		return new CompiledStatement( $sql . $statement->sql, $statement->bindings );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ConnectionDescriptor $connection Descriptor, for the probed version.
	 */
	public function supports_explain_analyze( ConnectionDescriptor $connection ): bool {
		return true;
	}

	/**
	 * Build the `search_path` value from the scope's schemas.
	 *
	 * `pg_catalog` is appended explicitly so an unqualified built-in function
	 * resolves to the system object even if a scope schema later grows a
	 * same-named relation. `pg_temp` is deliberately absent: a temporary schema
	 * ahead of the allowed schemas is a resolution hazard, and the grammar emits
	 * schema-qualified names anyway.
	 *
	 * @param ScopeDescriptor $scope Scope.
	 */
	private function search_path( ScopeDescriptor $scope ): string {
		$parts = array();

		foreach ( $scope->schemas as $schema ) {
			$parts[] = $this->grammar->quote( $schema, ConnectionDescriptor::PGSQL );
		}

		$parts[] = 'pg_catalog';

		return implode( ', ', $parts );
	}
}
