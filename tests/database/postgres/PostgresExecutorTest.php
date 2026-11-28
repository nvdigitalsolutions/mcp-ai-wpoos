<?php

/**
 * PostgreSQL executor tests — the read path against a real engine.
 *
 * Proposal 167 gates 1 and 8 and the P2 row-filter behaviour. These prove the
 * pieces the SQLite suite cannot: `BEGIN READ ONLY`, `set_config(..., true)`
 * surviving only inside the transaction, RLS as the second control, the
 * server-side statement timeout, and scope-filtered introspection.
 *
 * @package WP_MCP_AI\Tests\Database
 * @since   3.1.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Nvoos\Database\Domain\Service\Database\FilterOperator;
use Nvoos\Database\Domain\Service\Database\GrantTier;
use Nvoos\Database\Domain\ValueObject\Database\AggregateQuery;
use Nvoos\Database\Domain\ValueObject\Database\FilterClause;
use Nvoos\Database\Domain\ValueObject\Database\MetricClause;
use Nvoos\Database\Domain\ValueObject\Database\OrderClause;
use Nvoos\Database\Domain\ValueObject\Database\RawQuery;
use Nvoos\Database\Domain\ValueObject\Database\SelectQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Executor behaviour on PostgreSQL.
 */
#[CoversClass( WP_MCP_AI_Db_Executor::class )]
#[CoversClass( WP_MCP_AI_Db_Catalogue::class )]
final class PostgresExecutorTest extends TestCase {

	use PostgresFixture;

	/**
	 * Skip without a DSN and apply fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		self::maybe_skip_postgres();
		self::bootstrap_fixtures();
	}

	/**
	 * Sensitive columns are masked on a real engine.
	 */
	public function test_select_masks_a_sensitive_column(): void {
		$parts = self::make_executor( 'reader', 'tenant-a' );

		$result = $parts['executor']->select(
			'warehouse',
			'tenant',
			new SelectQuery(
				relation: 'reporting.orders',
				columns: array( 'order_id', 'email' ),
				order: array( new OrderClause( 'order_id', 'asc' ) ),
				limit: 3
			)
		);

		$this->assertSame( 3, $result->rowCount );
		$this->assertSame( '[masked]', $result->rows[0]['email'] );
		$this->assertContains( 'reporting.orders.email', $result->masked );
	}

	/**
	 * The scope's tenant filter binds to `app.tenant_id` and restricts rows.
	 */
	public function test_tenant_scope_restricts_rows(): void {
		$parts = self::make_executor( 'reader', 'tenant-a' );

		$result = $parts['executor']->select(
			'warehouse',
			'tenant',
			new SelectQuery(
				relation: 'reporting.orders',
				columns: array( 'order_id', 'tenant_id' ),
				order: array( new OrderClause( 'order_id', 'asc' ) ),
				limit: 5
			)
		);

		$this->assertSame( 5, $result->rowCount );

		foreach ( $result->rows as $row ) {
			$this->assertSame( 'tenant-a', $row['tenant_id'] );
		}
	}

	/**
	 * A request filter that asks for another tenant cannot widen the scope: the
	 * scope predicate is ANDed, so the result is empty rather than leaky.
	 */
	public function test_request_filter_cannot_widen_the_scope(): void {
		$parts = self::make_executor( 'reader', 'tenant-a' );

		$result = $parts['executor']->select(
			'warehouse',
			'tenant',
			new SelectQuery(
				relation: 'reporting.orders',
				columns: array( 'order_id', 'tenant_id' ),
				filters: array( new FilterClause( 'tenant_id', FilterOperator::EQUALS, 'tenant-b' ) ),
				limit: 5
			)
		);

		$this->assertSame( 0, $result->rowCount );
	}

	/**
	 * A tenant-scoped aggregate counts only the bound tenant, without a request
	 * filter — the "forgot the tenant" case.
	 */
	public function test_tenant_aggregate_counts_one_tenant(): void {
		$tenant_a = self::make_executor( 'reader', 'tenant-a' );
		$tenant_b = self::make_executor( 'reader', 'tenant-b' );

		$count = static function ( array $parts ): int {
			$result = $parts['executor']->aggregate(
				'warehouse',
				'tenant',
				new AggregateQuery(
					relation: 'reporting.orders',
					metrics: array( new MetricClause( \Nvoos\Database\Domain\Service\Database\Metric::COUNT, 'n' ) ),
					limit: 1
				)
			);

			return (int) $result->rows[0]['n'];
		};

		$this->assertSame( 200000, $count( $tenant_a ) );
		$this->assertSame( 400, $count( $tenant_b ) );
	}

	/**
	 * A relation without the filter column is refused rather than widened.
	 */
	public function test_tenant_filter_on_a_relation_without_the_column_refuses(): void {
		$parts = self::make_executor( 'reader', 'tenant-a' );

		self::assert_refusal(
			static fn () => $parts['executor']->select(
				'warehouse',
				'tenant',
				new SelectQuery( relation: 'reporting.notes', limit: 1 )
			),
			'db_scope_filter_unmappable'
		);
	}

	/**
	 * Introspection is scope-filtered and never exposes a system schema.
	 */
	public function test_describe_excludes_system_schemas(): void {
		$parts     = self::make_executor( 'reader' );
		$catalogue = $parts['executor']->describe( 'warehouse' );

		$this->assertTrue( $catalogue->has( 'reporting', 'orders' ) );
		$this->assertTrue( $catalogue->has( 'reporting', 'orders_barrier' ) );
		$this->assertFalse( $catalogue->has( 'pg_catalog', 'pg_authid' ) );
		$this->assertFalse( $catalogue->has( 'information_schema', 'tables' ) );

		foreach ( $catalogue->tables as $table ) {
			$this->assertSame( 'reporting', $table->schema );
		}
	}

	/**
	 * A plain plan needs metadata+read; an analysed plan needs the extra tier.
	 */
	public function test_explain_plan_and_analyze_tier(): void {
		$parts = self::make_executor( 'reader', 'tenant-a' );
		$query = new SelectQuery( relation: 'reporting.orders', columns: array( 'order_id' ), limit: 5 );

		$plan = $parts['executor']->explain( 'warehouse', 'tenant', $query );

		$this->assertFalse( $plan->analyzed );
		$this->assertSame( 'pgsql', $plan->engine );
		$this->assertNotSame( array(), $plan->plan );

		self::assert_refusal(
			static fn () => $parts['executor']->explain( 'warehouse', 'tenant', $query, true ),
			'db_tier_required'
		);

		$analyst = self::make_executor( 'reader' );
		$analyzed = $analyst['executor']->explain( 'warehouse', 'analyst', $query, true );

		$this->assertTrue( $analyzed->analyzed );
		$this->assertNotSame( array(), $analyzed->plan );
	}

	/**
	 * Raw SQL is a high tier, and RLS still bounds it to the tenant.
	 */
	public function test_raw_sql_is_rls_scoped(): void {
		$parts  = self::make_executor( 'reader', 'tenant-a' );
		$result = $parts['executor']->query(
			'warehouse',
			'tenant',
			new RawQuery( 'SELECT count(*) AS n FROM reporting.orders' )
		);

		$this->assertSame( 200000, (int) $result->rows[0]['n'] );
	}

	/**
	 * Raw SQL with no tenant resolved sees nothing: RLS fails closed.
	 */
	public function test_raw_sql_without_a_tenant_sees_nothing(): void {
		$parts  = self::make_executor( 'reader', null );
		$result = $parts['executor']->query(
			'warehouse',
			'tenant',
			new RawQuery( 'SELECT count(*) AS n FROM reporting.orders' )
		);

		$this->assertSame( 0, (int) $result->rows[0]['n'] );
	}

	/**
	 * `statement_timeout` is applied in milliseconds and mapped to `db_timeout`.
	 *
	 * The statement is admission-safe (`SELECT count(*)` over a cross join) so the
	 * only thing that stops it is the session timeout.
	 */
	public function test_statement_timeout_is_mapped(): void {
		$parts = self::make_executor( 'reader', 'tenant-a' );

		self::assert_refusal(
			static fn () => $parts['executor']->query(
				'warehouse',
				'slow',
				new RawQuery( 'SELECT count(*) AS n FROM reporting.orders a CROSS JOIN reporting.orders b' )
			),
			'db_timeout'
		);
	}

	/**
	 * A 4 MiB cell is truncated to the scope's per-cell cap.
	 */
	public function test_cell_cap_truncates(): void {
		$parts  = self::make_executor( 'reader' );
		$result = $parts['executor']->select(
			'warehouse',
			'analyst',
			new SelectQuery( relation: 'reporting.blobs', columns: array( 'id', 'payload' ), limit: 1 )
		);

		$this->assertSame( 1, $result->rowCount );
		$this->assertStringContainsString( '…[truncated]', (string) $result->rows[0]['payload'] );
		$this->assertLessThan( 4096, strlen( (string) $result->rows[0]['payload'] ) );
	}

	/**
	 * The audit row carries the template and the scope version, never a literal.
	 */
	public function test_audit_is_literal_free(): void {
		$parts = self::make_executor( 'reader' );

		$parts['executor']->select(
			'warehouse',
			'analyst',
			new SelectQuery(
				relation: 'reporting.orders',
				columns: array( 'order_id' ),
				filters: array( new FilterClause( 'status', FilterOperator::EQUALS, 'refunded' ) ),
				limit: 1
			)
		);

		$tail = $parts['audit']->tail( 1 );

		$this->assertSame( 'select', $tail[0]['action'] );
		$this->assertSame( 1, $tail[0]['scope_version'] );
		$this->assertStringContainsString( ':p0', $tail[0]['sql_template'] );
		$this->assertStringNotContainsString( 'refunded', (string) wp_json_encode( $tail ) );
	}

	/**
	 * The explain-analyze tier gate is per scope.
	 */
	public function test_analyze_tier_is_per_scope(): void {
		$parts = self::make_executor( 'reader', 'tenant-a' );
		$scope = $parts['scopes']->get( 'tenant' );

		$this->assertInstanceOf( \Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor::class, $scope );
		$this->assertFalse( $scope->hasTier( GrantTier::EXPLAIN_ANALYZE ) );
	}
}
