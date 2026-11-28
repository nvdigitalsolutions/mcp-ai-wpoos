<?php
/**
 * MySQL engine adapter.
 *
 * Proposal 167 section 4.3.2. Two MySQL-specific facts drive this class:
 *
 *  1. `max_execution_time` is **milliseconds** and applies to `SELECT` only. A
 *     statement that is not a `SELECT` has no server-side deadline, so the
 *     executor also enforces a PHP-side deadline.
 *  2. `MYSQL_ATTR_MULTI_STATEMENTS` is honoured **only when passed to the PDO
 *     constructor**. `setAttribute()` after construction silently does nothing,
 *     which is why it lives in {@see self::options()} and not in the executor.
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
 * MySQL session behaviour.
 */
class WP_MCP_AI_Db_Engine_Mysql extends WP_MCP_AI_Db_Engine {

	/**
	 * Lock wait, in seconds (MySQL's unit for this setting).
	 */
	protected const LOCK_WAIT_SECONDS = 2;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return ConnectionDescriptor::MYSQL;
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_port(): int {
		return 3306;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ConnectionDescriptor $connection Descriptor.
	 */
	public function dsn( ConnectionDescriptor $connection ): string {
		return sprintf(
			'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
			$connection->host,
			$connection->port > 0 ? $connection->port : $this->default_port(),
			$connection->database
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function options(): array {
		$options = array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_EMULATE_PREPARES   => false,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_STRINGIFY_FETCHES  => false,
			PDO::ATTR_PERSISTENT         => false,
		);

		$multi = self::multi_statement_attribute();

		if ( null !== $multi ) {
			$options[ $multi ] = false;
		}

		return $options;
	}

	/**
	 * Locate the multi-statement attribute without assuming a driver version.
	 *
	 * PHP 8.4 moved the MySQL attributes onto `Pdo\Mysql`; the legacy
	 * `PDO::MYSQL_ATTR_*` constants still exist but are deprecated. Resolving by
	 * name keeps this file loadable on a host with no `pdo_mysql` at all.
	 */
	protected static function multi_statement_attribute(): ?int {
		if ( defined( 'Pdo\\Mysql::ATTR_MULTI_STATEMENTS' ) ) {
			return (int) constant( 'Pdo\\Mysql::ATTR_MULTI_STATEMENTS' );
		}

		if ( defined( 'PDO::MYSQL_ATTR_MULTI_STATEMENTS' ) ) {
			return (int) constant( 'PDO::MYSQL_ATTR_MULTI_STATEMENTS' );
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function begin_statement(): string {
		return 'START TRANSACTION READ ONLY';
	}

	/**
	 * {@inheritDoc}
	 */
	public function begin_write_statement(): string {
		return 'START TRANSACTION';
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
		$statements = array(
			new CompiledStatement(
				'SET SESSION max_execution_time = :p0',
				array( ':p0' => max( 1, $limits->timeoutMs ) )
			),
			new CompiledStatement(
				'SET SESSION innodb_lock_wait_timeout = :p0',
				array( ':p0' => self::LOCK_WAIT_SECONDS )
			),
			new CompiledStatement(
				'SET @nvoos_application_name = :p0',
				array( ':p0' => $this->application_name( $application_name, 64 ) )
			),
		);

		if ( null !== $tenant_id && '' !== $tenant_id ) {
			$statements[] = new CompiledStatement(
				'SET @nvoos_tenant_id = :p0',
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
		return new CompiledStatement( 'EXPLAIN FORMAT=JSON ' . $statement->sql, $statement->bindings );
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
