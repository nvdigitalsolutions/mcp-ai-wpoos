<?php
/**
 * Compiles a structured UPDATE or DELETE.
 *
 * Proposal 167 phase P6. There is no `db_write` tool that takes SQL. A write is
 * expressed as an operation, a relation, a `set` map and filters, and this class
 * turns that into two statements: the write itself and a `SELECT COUNT(*)` with
 * the identical `WHERE`. The count is what makes the proposal honest — the
 * estimate an approver sees is computed by the database, not guessed by the
 * model — and what makes drift detectable at apply time.
 *
 * ## Why the filters must be non-empty
 *
 * An `UPDATE` with no predicates is a table-wide rewrite. The scope's row filter
 * still applies (and may itself be the only predicate), but a request that names
 * no filter is refused, because "change everything this scope can see" is never
 * what a caller means and is not something a human can review in a proposal.
 *
 * ## Bind namespaces
 *
 * `set` values use `:wN` and predicates use `:pN`, so merging the two binding
 * maps cannot collide. Identifiers never appear as values and values never appear
 * in the SQL.
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
use Nvoos\Database\Domain\Service\Database\ScopePolicy;
use Nvoos\Database\Domain\Service\Database\StatementGrammar;
use Nvoos\Database\Domain\ValueObject\Database\CatalogueDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\CompiledStatement;
use Nvoos\Database\Domain\ValueObject\Database\FilterClause;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\TableDescriptor;

/**
 * Structured write compiler.
 */
final class WP_MCP_AI_Db_Write_Compiler {

	/**
	 * Grammar.
	 *
	 * @var StatementGrammar
	 */
	private StatementGrammar $grammar;

	/**
	 * Policy.
	 *
	 * @var ScopePolicy
	 */
	private ScopePolicy $policy;

	/**
	 * Constructor.
	 *
	 * @param StatementGrammar|null $grammar Grammar.
	 * @param ScopePolicy|null      $policy  Policy.
	 */
	public function __construct( ?StatementGrammar $grammar = null, ?ScopePolicy $policy = null ) {
		$this->policy  = $policy ?? new ScopePolicy();
		$this->grammar = $grammar ?? new StatementGrammar( $this->policy );
	}

	/**
	 * Compile one write and its matching count.
	 *
	 * @param ScopeDescriptor      $scope     Scope.
	 * @param CatalogueDescriptor  $catalogue Scope-filtered catalogue.
	 * @param string               $relation  Schema-qualified relation.
	 * @param string               $operation `update` or `delete`.
	 * @param array<string, mixed> $set       Column to new value, for `update`.
	 * @param FilterClause[]       $filters   Predicates; must be non-empty.
	 * @param string|null          $tenant_id Tenant value, when the scope needs one.
	 * @param string               $engine    Engine key.
	 * @return array{write: CompiledStatement, count: CompiledStatement, table: TableDescriptor}
	 * @throws DatabaseRefusal When the request cannot be compiled safely.
	 */
	public function compile(
		ScopeDescriptor $scope,
		CatalogueDescriptor $catalogue,
		string $relation,
		string $operation,
		array $set,
		array $filters,
		?string $tenant_id,
		string $engine
	): array {
		$operation = strtolower( trim( $operation ) );

		if ( ! in_array( $operation, array( 'update', 'delete' ), true ) ) {
			throw DatabaseRefusal::of( RefusalCode::STATEMENT_NOT_ADMITTED, 'Only update and delete may be proposed.' );
		}

		if ( array() === $filters ) {
			throw DatabaseRefusal::of(
				RefusalCode::STATEMENT_NOT_ADMITTED,
				'A write must name at least one filter; an unbounded write is refused.'
			);
		}

		if ( 'update' === $operation && array() === $set ) {
			throw DatabaseRefusal::of( RefusalCode::STATEMENT_NOT_ADMITTED, 'An update must set at least one column.' );
		}

		$where = $this->grammar->compileWhere( $scope, $catalogue, $relation, $filters, $tenant_id, $engine );
		$table = $where['table'];
		$from  = $this->grammar->quote( $table->schema, $engine ) . '.' . $this->grammar->quote( $table->name, $engine );
		$count = new CompiledStatement( 'SELECT COUNT(*) AS n FROM ' . $from . $where['sql'], $where['bindings'] );

		if ( 'delete' === $operation ) {
			return array(
				'write' => new CompiledStatement( 'DELETE FROM ' . $from . $where['sql'], $where['bindings'] ),
				'count' => $count,
				'table' => $table,
			);
		}

		$assignments = array();
		$bindings    = array();
		$index       = 0;

		foreach ( $set as $column => $value ) {
			$resolved = $this->policy->assertColumn( $scope, $table, (string) $column );

			if ( in_array( strtolower( $resolved ), $scope->excludedColumns( $table ), true ) ) {
				throw DatabaseRefusal::of(
					RefusalCode::COLUMN_NOT_ALLOWED,
					'Column is excluded from the scope: ' . $resolved
				);
			}

			$placeholder              = ':w' . $index;
			$assignments[]            = $this->grammar->quote( $resolved, $engine ) . ' = ' . $placeholder;
			$bindings[ $placeholder ] = $value;
			++$index;
		}

		return array(
			'write' => new CompiledStatement(
				'UPDATE ' . $from . ' SET ' . implode( ', ', $assignments ) . $where['sql'],
				array_merge( $bindings, $where['bindings'] )
			),
			'count' => $count,
			'table' => $table,
		);
	}
}
