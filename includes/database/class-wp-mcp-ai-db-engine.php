<?php
/**
 * Engine contract for the database adapters.
 *
 * Proposal 167 section 4. The engine object is deliberately **pure**: it builds a
 * DSN, a PDO option map, a transaction preamble and the session statements for a
 * call. It never opens a connection and never executes anything, so the unit
 * conversion that matters most — milliseconds versus seconds — is testable with
 * no database server at all.
 *
 * ## The asymmetry this class exists to contain
 *
 * | Engine  | Timeout setting             | Unit              | Scope        |
 * |---------|-----------------------------|-------------------|--------------|
 * | pgsql   | `statement_timeout`         | milliseconds      | any          |
 * | mysql   | `max_execution_time`        | milliseconds      | SELECT only  |
 * | mariadb | `max_statement_time`        | seconds (decimal) | any          |
 * | sqlite  | —                           | —                 | —            |
 *
 * Getting that wrong is a silent 1000× timeout bug, so each engine converts
 * inside {@see self::session_statements()} and the executor never converts.
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

use Nvoos\Database\Domain\Service\Database\StatementGrammar;
use Nvoos\Database\Domain\ValueObject\Database\CompiledStatement;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\DatabaseLimits;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\Contract\Database\DatabaseEngineInterface;

/**
 * Base class for the four engine adapters.
 */
abstract class WP_MCP_AI_Db_Engine implements DatabaseEngineInterface {

	/**
	 * Statement grammar, for identifier quoting in session SQL.
	 *
	 * @var StatementGrammar
	 */
	protected StatementGrammar $grammar;

	/**
	 * Constructor.
	 *
	 * @param StatementGrammar|null $grammar Grammar instance; a default one is created when omitted.
	 */
	public function __construct( ?StatementGrammar $grammar = null ) {
		$this->grammar = $grammar ?? new StatementGrammar();
	}

	/**
	 * Engine key, matching {@see ConnectionDescriptor} constants.
	 */
	abstract public function name(): string;

	/**
	 * The PDO DSN.
	 *
	 * Credentials are never part of the DSN: they are constructor arguments to
	 * `PDO`, which keeps them out of any driver error that echoes the DSN.
	 *
	 * @param ConnectionDescriptor $connection Descriptor.
	 */
	abstract public function dsn( ConnectionDescriptor $connection ): string;

	/**
	 * PDO attributes for the constructor.
	 *
	 * @return array<int, mixed>
	 */
	abstract public function options(): array;

	/**
	 * The statement that opens the transaction.
	 *
	 * Read-only where the engine supports it, so a write cannot succeed even if
	 * every other layer failed.
	 */
	abstract public function begin_statement(): string;

	/**
	 * The statement that opens a write transaction.
	 *
	 * Only the apply-write path uses this, after a human approval and a drift
	 * check. Separating it from {@see self::begin_statement()} means a read can
	 * never accidentally run in a writable transaction.
	 */
	abstract public function begin_write_statement(): string;

	/**
	 * Session statements, run in order inside the transaction.
	 *
	 * Every value is bound, and every setting that supports it is applied with
	 * transaction-local scope, so nothing survives the `ROLLBACK`.
	 *
	 * @param ConnectionDescriptor $connection       Descriptor.
	 * @param ScopeDescriptor      $scope            Scope, for the schema path.
	 * @param DatabaseLimits       $limits           Caps, including the timeout.
	 * @param string               $application_name Audit label for `pg_stat_activity`.
	 * @param string|null          $tenant_id        Tenant value, when the scope declares one.
	 * @return list<CompiledStatement>
	 */
	abstract public function session_statements(
		ConnectionDescriptor $connection,
		ScopeDescriptor $scope,
		DatabaseLimits $limits,
		string $application_name,
		?string $tenant_id
	): array;

	/**
	 * Session statements for an approved write transaction.
	 *
	 * Defaults to the read preamble, which is correct for every engine whose
	 * read-only guarantee lives in `BEGIN` (PostgreSQL, MySQL, MariaDB): the write
	 * transaction simply begins without `READ ONLY`. SQLite is the exception — its
	 * guarantee is `PRAGMA query_only`, so it must be turned off explicitly.
	 *
	 * @param ConnectionDescriptor $connection       Descriptor.
	 * @param ScopeDescriptor      $scope            Scope.
	 * @param DatabaseLimits       $limits           Caps.
	 * @param string               $application_name Audit label.
	 * @param string|null          $tenant_id        Tenant value.
	 * @return list<CompiledStatement>
	 */
	public function write_session_statements(
		ConnectionDescriptor $connection,
		ScopeDescriptor $scope,
		DatabaseLimits $limits,
		string $application_name,
		?string $tenant_id
	): array {
		return $this->session_statements( $connection, $scope, $limits, $application_name, $tenant_id );
	}

	/**
	 * Wrap a compiled statement in the engine's EXPLAIN form.
	 *
	 * @param CompiledStatement $statement Statement to explain.
	 * @param bool              $analyze   Whether execution is permitted.
	 */
	abstract public function explain_statement( CompiledStatement $statement, bool $analyze ): CompiledStatement;

	/**
	 * Whether the engine can execute an `EXPLAIN ANALYZE` equivalent.
	 *
	 * @param ConnectionDescriptor $connection Descriptor, for the probed version.
	 */
	abstract public function supports_explain_analyze( ConnectionDescriptor $connection ): bool;

	/**
	 * Default TCP port, used when a record omits one.
	 */
	abstract public function default_port(): int;

	/**
	 * Driver extension name.
	 */
	public function extension(): string {
		return 'pdo_' . $this->name();
	}

	/**
	 * Whether a metric can be computed on this engine.
	 *
	 * @param \Nvoos\Database\Domain\Service\Database\Metric $metric Metric.
	 */
	public function supports_metric( \Nvoos\Database\Domain\Service\Database\Metric $metric ): bool {
		return $metric->isSupportedBy( $this->name() );
	}

	/**
	 * Trim an application name to the engine's identifier limit.
	 *
	 * @param string $value Candidate label.
	 * @param int    $limit Maximum bytes.
	 */
	protected function application_name( string $value, int $limit = 63 ): string {
		$value = strtolower( preg_replace( '/[^a-z0-9:_.\\-]+/i', '-', $value ) ?? '' );
		$value = trim( $value, '-' );

		if ( '' === $value ) {
			$value = 'nvoos-database';
		}

		return substr( $value, 0, $limit );
	}

	/**
	 * Milliseconds as a decimal-seconds string for MariaDB.
	 *
	 * @param int $timeout_ms Milliseconds.
	 */
	protected function as_seconds( int $timeout_ms ): string {
		return number_format( max( 1, $timeout_ms ) / 1000, 3, '.', '' );
	}
}
