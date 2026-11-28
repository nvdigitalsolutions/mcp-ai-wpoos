<?php
/**
 * MariaDB engine adapter.
 *
 * Proposal 167 section 4.3.3. MariaDB shares the MySQL wire protocol and almost
 * all of its session SQL, but **not** its timeout unit: `max_statement_time` is
 * fractional **seconds** while MySQL's `max_execution_time` is milliseconds.
 * This class exists so that difference lives in exactly one place and has one
 * unit test, rather than being a milestone note someone has to remember.
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
 * MariaDB session behaviour.
 */
final class WP_MCP_AI_Db_Engine_Mariadb extends WP_MCP_AI_Db_Engine_Mysql {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return ConnectionDescriptor::MARIADB;
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
				'SET SESSION max_statement_time = :p0',
				array( ':p0' => $this->as_seconds( $limits->timeoutMs ) )
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
	 * MariaDB's analyse form is `ANALYZE FORMAT=JSON`, not MySQL's
	 * `EXPLAIN ANALYZE`, and the plain plan form is shared.
	 *
	 * @param CompiledStatement $statement Statement to explain.
	 * @param bool              $analyze   Whether execution is permitted.
	 */
	public function explain_statement( CompiledStatement $statement, bool $analyze ): CompiledStatement {
		$sql = $analyze ? 'ANALYZE FORMAT=JSON ' : 'EXPLAIN FORMAT=JSON ';

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
}
