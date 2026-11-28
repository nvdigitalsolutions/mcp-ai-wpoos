<?php

/**
 * Structured write compiler tests.
 *
 * Proposal 167 phase P6. The compiler is pure, so these assertions run without a
 * database and still prove the properties that matter: identifiers come from the
 * catalogue, values are bound, the scope row filter is always present, and an
 * unbounded write cannot be compiled at all.
 *
 * @package WP_MCP_AI\Tests\Database
 * @since   3.1.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\FilterOperator;
use Nvoos\Database\Domain\ValueObject\Database\CatalogueDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\ColumnDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\FilterClause;
use Nvoos\Database\Domain\ValueObject\Database\RowFilterRule;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\TableDescriptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Write compiler contract tests.
 */
#[CoversClass( WP_MCP_AI_Db_Write_Compiler::class )]
final class WriteCompilerTest extends TestCase {

	/**
	 * Scope fixture.
	 *
	 * @param list<RowFilterRule> $rules Row filters.
	 * @param array<string, mixed> $columns Column rules.
	 */
	private function scope( array $rules = array(), array $columns = array() ): ScopeDescriptor {
		return new ScopeDescriptor(
			id: 'writer',
			label: 'Writer',
			connection: 'warehouse',
			schemas: array( 'reporting' ),
			tables: array( 'reporting.orders' ),
			columns: $columns,
			rowFilter: $rules,
			tiers: array()
		);
	}

	/**
	 * Catalogue fixture.
	 */
	private function catalogue(): CatalogueDescriptor {
		$table = new TableDescriptor(
			'reporting',
			'orders',
			array(
				'order_id' => new ColumnDescriptor( 'order_id', 'integer', false ),
				'status'   => new ColumnDescriptor( 'status', 'text', false ),
				'tenant'   => new ColumnDescriptor( 'tenant', 'text', false ),
				'secret'   => new ColumnDescriptor( 'secret', 'text', true ),
			),
			array( 'order_id' )
		);

		return new CatalogueDescriptor( array( 'reporting.orders' => $table ) );
	}

	/**
	 * A DELETE compiles with the predicate bound.
	 */
	public function test_delete_compiles(): void {
		$compiler = new WP_MCP_AI_Db_Write_Compiler();
		$compiled = $compiler->compile(
			$this->scope(),
			$this->catalogue(),
			'reporting.orders',
			'delete',
			array(),
			array( new FilterClause( 'status', FilterOperator::EQUALS, 'refunded' ) ),
			null,
			'pgsql'
		);

		$this->assertSame( 'DELETE FROM "reporting"."orders" WHERE "status" = :p0', $compiled['write']->sql );
		$this->assertSame( array( ':p0' => 'refunded' ), $compiled['write']->bindings );
		$this->assertStringStartsWith( 'SELECT COUNT(*) AS n FROM "reporting"."orders" WHERE', $compiled['count']->sql );
	}

	/**
	 * An UPDATE binds its values with a separate namespace from its predicates.
	 */
	public function test_update_uses_separate_binding_namespaces(): void {
		$compiler = new WP_MCP_AI_Db_Write_Compiler();
		$compiled = $compiler->compile(
			$this->scope(),
			$this->catalogue(),
			'reporting.orders',
			'update',
			array( 'status' => 'paid' ),
			array( new FilterClause( 'order_id', FilterOperator::EQUALS, 7 ) ),
			null,
			'pgsql'
		);

		$this->assertSame(
			'UPDATE "reporting"."orders" SET "status" = :w0 WHERE "order_id" = :p0',
			$compiled['write']->sql
		);
		$this->assertSame( array( ':w0' => 'paid', ':p0' => 7 ), $compiled['write']->bindings );
	}

	/**
	 * The scope row filter is always ANDed into the WHERE.
	 */
	public function test_scope_row_filter_is_always_applied(): void {
		$compiler = new WP_MCP_AI_Db_Write_Compiler();
		$scope    = $this->scope( array( new RowFilterRule( 'tenant', FilterOperator::EQUALS ) ) );

		$compiled = $compiler->compile(
			$scope,
			$this->catalogue(),
			'reporting.orders',
			'delete',
			array(),
			array( new FilterClause( 'status', FilterOperator::EQUALS, 'paid' ) ),
			'tenant-a',
			'pgsql'
		);

		$this->assertStringContainsString( '"status" = :p0', $compiled['write']->sql );
		$this->assertStringContainsString( '"tenant" = :p1', $compiled['write']->sql );
		$this->assertSame( array( ':p0' => 'paid', ':p1' => 'tenant-a' ), $compiled['write']->bindings );
	}

	/**
	 * A tenant-sourced filter with no tenant refuses rather than widening.
	 */
	public function test_missing_tenant_refuses(): void {
		$compiler = new WP_MCP_AI_Db_Write_Compiler();
		$scope    = $this->scope( array( new RowFilterRule( 'tenant', FilterOperator::EQUALS ) ) );

		$this->expectException( DatabaseRefusal::class );

		$compiler->compile(
			$scope,
			$this->catalogue(),
			'reporting.orders',
			'delete',
			array(),
			array( new FilterClause( 'status', FilterOperator::EQUALS, 'paid' ) ),
			null,
			'pgsql'
		);
	}

	/**
	 * An unbounded write cannot be compiled.
	 */
	public function test_unbounded_write_is_refused(): void {
		$compiler = new WP_MCP_AI_Db_Write_Compiler();

		$this->expectException( DatabaseRefusal::class );

		$compiler->compile(
			$this->scope(),
			$this->catalogue(),
			'reporting.orders',
			'delete',
			array(),
			array(),
			null,
			'pgsql'
		);
	}

	/**
	 * A relation outside the scope cannot be written.
	 */
	public function test_relation_outside_scope_is_refused(): void {
		$compiler = new WP_MCP_AI_Db_Write_Compiler();

		$this->expectException( DatabaseRefusal::class );

		$compiler->compile(
			$this->scope(),
			$this->catalogue(),
			'reporting.customers',
			'delete',
			array(),
			array( new FilterClause( 'status', FilterOperator::EQUALS, 'paid' ) ),
			null,
			'pgsql'
		);
	}

	/**
	 * Writing to an excluded column is refused.
	 */
	public function test_excluded_column_cannot_be_set(): void {
		$compiler = new WP_MCP_AI_Db_Write_Compiler();
		$scope    = $this->scope( array(), array( 'reporting.orders' => array( 'exclude' => array( 'secret' ) ) ) );

		$this->expectException( DatabaseRefusal::class );

		$compiler->compile(
			$scope,
			$this->catalogue(),
			'reporting.orders',
			'update',
			array( 'secret' => 'x' ),
			array( new FilterClause( 'order_id', FilterOperator::EQUALS, 1 ) ),
			null,
			'pgsql'
		);
	}

	/**
	 * An unknown operation is refused.
	 */
	public function test_unknown_operation_is_refused(): void {
		$compiler = new WP_MCP_AI_Db_Write_Compiler();

		$this->expectException( DatabaseRefusal::class );

		$compiler->compile(
			$this->scope(),
			$this->catalogue(),
			'reporting.orders',
			'insert',
			array( 'status' => 'paid' ),
			array( new FilterClause( 'order_id', FilterOperator::EQUALS, 1 ) ),
			null,
			'pgsql'
		);
	}

	/**
	 * MySQL quoting differs from PostgreSQL and comes from the grammar.
	 */
	public function test_engine_quoting_is_applied(): void {
		$compiler = new WP_MCP_AI_Db_Write_Compiler();
		$compiled = $compiler->compile(
			$this->scope(),
			$this->catalogue(),
			'reporting.orders',
			'delete',
			array(),
			array( new FilterClause( 'status', FilterOperator::EQUALS, 'paid' ) ),
			null,
			'mysql'
		);

		$this->assertStringStartsWith( 'DELETE FROM `reporting`.`orders` WHERE `status` = :p0', $compiled['write']->sql );
	}
}
