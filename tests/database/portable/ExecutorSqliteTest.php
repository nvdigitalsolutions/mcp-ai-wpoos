<?php

/**
 * End-to-end executor tests against SQLite.
 *
 * Proposal 167 gate 1 and the executor state machine in section 3.3. SQLite is the
 * only engine available in every environment, and it is a genuine engine: a real
 * driver, a real transaction, real rows. `PRAGMA query_only` gives the same shape
 * of guarantee the Postgres role gives, so the tests can prove the read-only path
 * and the rollback-in-`finally` rule rather than assert them in prose.
 *
 * These tests are the portable stand-in for the Postgres suites (gates 6 and 7),
 * which require `NVOOS_TEST_PGSQL_DSN` and are skipped without it.
 *
 * @package WP_MCP_AI\Tests\Database
 * @since   3.1.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\FilterOperator;
use Nvoos\Database\Domain\Service\Database\Metric;
use Nvoos\Database\Domain\ValueObject\Database\AggregateQuery;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\FilterClause;
use Nvoos\Database\Domain\ValueObject\Database\MetricClause;
use Nvoos\Database\Domain\ValueObject\Database\OrderClause;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use Nvoos\Database\Domain\ValueObject\Database\RawQuery;
use Nvoos\Database\Domain\ValueObject\Database\SearchQuery;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\SelectQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test factory: opens a fresh handle per call so each phase sees committed state.
 */
final class WP_MCP_AI_Db_Test_Sqlite_Factory extends WP_MCP_AI_Db_Connection_Factory {

	/**
	 * {@inheritDoc}
	 */
	public function open( ConnectionDescriptor $connection ): PDO {
		return new PDO(
			'sqlite:' . $connection->database,
			null,
			null,
			array(
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			)
		);
	}
}

/**
 * End-to-end executor tests against SQLite.
 */
#[CoversClass( WP_MCP_AI_Db_Executor::class )]
#[CoversClass( WP_MCP_AI_Db_Catalogue::class )]
final class ExecutorSqliteTest extends TestCase {

	/**
	 * Database file.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * Executor.
	 *
	 * @var WP_MCP_AI_Db_Executor
	 */
	private WP_MCP_AI_Db_Executor $executor;

	/**
	 * Audit log.
	 *
	 * @var WP_MCP_AI_Db_Audit_Log
	 */
	private WP_MCP_AI_Db_Audit_Log $audit;

	/**
	 * The seeded grant, for the preview tests.
	 *
	 * @var ScopeDescriptor
	 */
	private ScopeDescriptor $scope;

	/**
	 * Build the fixture database and toolkit.
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			$this->markTestSkipped( 'pdo_sqlite is not loaded.' );
		}

		$this->path = tempnam( sys_get_temp_dir(), 'nvoos-db-' ) . '.sqlite';

		$pdo = new PDO( 'sqlite:' . $this->path );
		$pdo->exec(
			'CREATE TABLE orders (
				order_id INTEGER PRIMARY KEY,
				status TEXT NOT NULL,
				email TEXT,
				total NUMERIC
			)'
		);
		$pdo->exec(
			"INSERT INTO orders (order_id, status, email, total) VALUES
				(1, 'paid',     'a@example.test', 10),
				(2, 'paid',     'b@example.test', 20),
				(3, 'refunded', 'c@example.test', 30),
				(4, 'pending',  'd@example.test', 40)"
		);
		$pdo = null;

		$connection_record = array(
			'id'       => 'warehouse',
			'label'    => 'Warehouse',
			'engine'   => 'sqlite',
			'database' => $this->path,
			'probe'    => array(
				'reachable'      => true,
				'server_version' => '3.45',
				'checked_at'     => time(),
			),
		);

		$scope_record = array(
			'id'         => 'analyst',
			'label'      => 'Analyst',
			'connection' => 'warehouse',
			'schemas'    => array( 'main' ),
			'tables'     => array( 'main.orders' ),
			'columns'    => array(
				'main.orders' => array(
					'sensitive'  => array( 'email' ),
					'searchable' => array( 'status' ),
				),
			),
			'tiers'      => array( 'metadata', 'read', 'raw_sql' ),
			'principals' => array( 'assistant:42' ),
		);

		$scopes      = new WP_MCP_AI_Db_Scope_Registry( array( 'analyst' => $scope_record ) );
		$connections = new WP_MCP_AI_Db_Connection_Registry( null, $scopes, array( 'warehouse' => $connection_record ) );
		$factory     = new WP_MCP_AI_Db_Test_Sqlite_Factory( new WP_MCP_AI_Db_Encrypted_Credential_Store() );
		$catalogue   = new WP_MCP_AI_Db_Catalogue( $factory, new WP_MCP_AI_Db_Array_Cache() );
		$this->audit = new WP_MCP_AI_Db_Audit_Log( array() );

		$this->executor = ( new WP_MCP_AI_Db_Executor(
			$connections,
			$scopes,
			$catalogue,
			$factory,
			$this->audit,
			new WP_MCP_AI_Db_Redactor(),
			'portable-cursor-secret'
		) )->for_principal( new PrincipalRef( 'assistant', '42' ) );

		$seeded = $scopes->get( 'analyst' );

		$this->assertNotNull( $seeded );
		$this->scope = $seeded;
	}

	/**
	 * Remove the fixture database.
	 */
	protected function tearDown(): void {
		if ( isset( $this->path ) && is_file( $this->path ) ) {
			@unlink( $this->path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test cleanup.
		}

		parent::tearDown();
	}

	/**
	 * The preview compiles the scope's statement and reads no row.
	 *
	 * This is what the console's editor shows before a grant is saved: the
	 * statement with placeholders, the masked column, the effective caps — and
	 * no audit row, because nothing was executed.
	 */
	public function test_preview_compiles_the_grant_without_executing_it(): void {
		$preview = $this->executor->preview(
			'warehouse',
			$this->scope,
			new SelectQuery(
				relation: 'main.orders',
				columns: array( 'order_id', 'status', 'email' ),
				filters: array( new FilterClause( 'status', FilterOperator::EQUALS, 'paid' ) ),
				limit: 10
			)
		);

		$this->assertSame( 'main.orders', $preview->relation );
		$this->assertSame( 'sqlite', $preview->engine );
		$this->assertStringContainsString( 'select', strtolower( $preview->statement ) );
		$this->assertStringNotContainsString( 'paid', $preview->statement, 'A preview must never carry a bound value.' );
		$this->assertSame( 1, $preview->bindings );
		$this->assertContains( 'order_id', $preview->projected );
		$this->assertContains( 'email', $preview->masked );
		$this->assertSame( 10, $preview->rowLimit );
		$this->assertFalse( $preview->tenantBound );
		$this->assertSame( 500, $preview->limits['max_rows'] );

		$this->assertSame( array(), $this->audit->tail( 10 ), 'A preview is not an executed call.' );
	}

	/**
	 * A preview of a relation outside the grant is refused, not widened.
	 *
	 * The scope-filtered catalogue is the identifier source, so a relation the
	 * scope does not allow is indistinguishable from one that does not exist —
	 * which is the point: the refusal discloses nothing about the database.
	 */
	public function test_preview_refuses_a_relation_outside_the_scope(): void {
		try {
			$this->executor->preview( 'warehouse', $this->scope, new SelectQuery( relation: 'main.customers' ) );
			$this->fail( 'The preview should have been refused.' );
		} catch ( DatabaseRefusal $refusal ) {
			$this->assertSame( 'db_relation_not_found', $refusal->getRefusalCode() );
		}
	}

	/**
	 * A scope bound to another connection cannot be previewed against this one.
	 */
	public function test_preview_refuses_a_scope_bound_to_another_connection(): void {
		$elsewhere = ScopeDescriptor::fromArray(
			array(
				'id'         => 'analyst',
				'connection' => 'elsewhere',
				'schemas'    => array( 'main' ),
				'tables'     => array( 'main.orders' ),
				'tiers'      => array( 'read' ),
			)
		);

		try {
			$this->executor->preview( 'warehouse', $elsewhere, new SelectQuery( relation: 'main.orders' ) );
			$this->fail( 'The preview should have been refused.' );
		} catch ( DatabaseRefusal $refusal ) {
			$this->assertSame( 'db_scope_not_allowed', $refusal->getRefusalCode() );
		}
	}

	/**
	 * A structured select returns rows, masks a sensitive column, and audits.
	 */
	public function test_select_masks_and_audits(): void {
		$result = $this->executor->select(
			'warehouse',
			'analyst',
			new SelectQuery(
				relation: 'main.orders',
				columns: array( 'order_id', 'status', 'email' ),
				order: array( new OrderClause( 'order_id', 'asc' ) ),
				limit: 2
			)
		);

		$this->assertSame( 2, $result->rowCount );
		$this->assertSame( '[masked]', $result->rows[0]['email'] );
		$this->assertContains( 'main.orders.email', $result->masked );
		$this->assertNotNull( $result->nextCursor );
		$this->assertNotSame( '', $result->auditId );

		$audit = $this->audit->tail( 1 );
		$this->assertSame( 'select', $audit[0]['action'] );
		$this->assertSame( 'assistant:42', $audit[0]['principal'] );
		$this->assertSame( 1, $audit[0]['scope_version'] );
		$this->assertStringNotContainsString( 'a@example.test', (string) $audit[0]['sql_template'] );
	}

	/**
	 * A cursor resumes without repeating or skipping rows.
	 */
	public function test_cursor_paging_is_stable(): void {
		$first = $this->executor->select(
			'warehouse',
			'analyst',
			new SelectQuery(
				relation: 'main.orders',
				columns: array( 'order_id' ),
				order: array( new OrderClause( 'order_id', 'asc' ) ),
				limit: 2
			)
		);

		$second = $this->executor->select(
			'warehouse',
			'analyst',
			new SelectQuery(
				relation: 'main.orders',
				columns: array( 'order_id' ),
				order: array( new OrderClause( 'order_id', 'asc' ) ),
				limit: 2,
				cursor: $first->nextCursor
			)
		);

		$this->assertSame( array( 1, 2 ), array_map( 'intval', array_column( $first->rows, 'order_id' ) ) );
		$this->assertSame( array( 3, 4 ), array_map( 'intval', array_column( $second->rows, 'order_id' ) ) );
	}

	/**
	 * A tampered cursor is refused.
	 */
	public function test_tampered_cursor_is_refused(): void {
		$first = $this->executor->select(
			'warehouse',
			'analyst',
			new SelectQuery(
				relation: 'main.orders',
				columns: array( 'order_id' ),
				order: array( new OrderClause( 'order_id', 'asc' ) ),
				limit: 1
			)
		);

		$this->expectException( DatabaseRefusal::class );

		$this->executor->select(
			'warehouse',
			'analyst',
			new SelectQuery(
				relation: 'main.orders',
				columns: array( 'order_id' ),
				order: array( new OrderClause( 'order_id', 'asc' ) ),
				limit: 1,
				cursor: substr( (string) $first->nextCursor, 0, -2 ) . 'xx'
			)
		);
	}

	/**
	 * Filters are applied and bound, not interpolated.
	 */
	public function test_filter_is_bound(): void {
		$result = $this->executor->select(
			'warehouse',
			'analyst',
			new SelectQuery(
				relation: 'main.orders',
				columns: array( 'order_id', 'status' ),
				filters: array( new FilterClause( 'status', FilterOperator::EQUALS, 'paid' ) ),
				order: array( new OrderClause( 'order_id', 'asc' ) ),
				limit: 10
			)
		);

		$this->assertSame( 2, $result->rowCount );
		$this->assertSame( array( 'paid', 'paid' ), array_column( $result->rows, 'status' ) );
		$this->assertStringContainsString( ':p0', $this->audit->tail( 1 )[0]['sql_template'] );
	}

	/**
	 * An aggregate groups and counts.
	 */
	public function test_aggregate_groups(): void {
		$result = $this->executor->aggregate(
			'warehouse',
			'analyst',
			new AggregateQuery(
				relation: 'main.orders',
				groupBy: array( 'status' ),
				metrics: array( new MetricClause( Metric::COUNT, 'n' ) ),
				limit: 10
			)
		);

		$byStatus = array();

		foreach ( $result->rows as $row ) {
			$byStatus[ (string) $row['status'] ] = (int) $row['n'];
		}

		$this->assertSame( 2, $byStatus['paid'] );
		$this->assertSame( 1, $byStatus['refunded'] );
		$this->assertSame( 1, $byStatus['pending'] );
	}

	/**
	 * Search matches only the declared searchable column.
	 */
	public function test_search_uses_declared_columns(): void {
		$result = $this->executor->search(
			'warehouse',
			'analyst',
			new SearchQuery( relation: 'main.orders', term: 'refund', columns: array( 'status' ), limit: 10 )
		);

		$this->assertSame( 1, $result->rowCount );
		$this->assertSame( 'refunded', $result->rows[0]['status'] );
	}

	/**
	 * A relation outside the scope is refused.
	 */
	public function test_relation_outside_scope_is_refused(): void {
		$this->expectException( DatabaseRefusal::class );

		$this->executor->select( 'warehouse', 'analyst', new SelectQuery( relation: 'main.secrets' ) );
	}

	/**
	 * A table outside the allowlist is refused even though it exists.
	 */
	public function test_unlisted_table_is_refused(): void {
		$pdo = new PDO( 'sqlite:' . $this->path );
		$pdo->exec( 'CREATE TABLE secrets (id INTEGER PRIMARY KEY, token TEXT)' );
		$pdo = null;

		$this->expectException( DatabaseRefusal::class );

		$this->executor->select( 'warehouse', 'analyst', new SelectQuery( relation: 'main.secrets' ) );
	}

	/**
	 * A write statement never reaches the engine through the raw path.
	 */
	public function test_write_is_refused_by_admission(): void {
		$this->expectException( DatabaseRefusal::class );

		$this->executor->query( 'warehouse', 'analyst', new RawQuery( 'DELETE FROM orders' ) );
	}

	/**
	 * A refusal before execution still produces exactly one audit record.
	 */
	public function test_pre_execution_refusal_is_audited(): void {
		try {
			$this->executor->select( 'warehouse', 'analyst', new SelectQuery( relation: 'main.secrets' ) );
		} catch ( DatabaseRefusal $refusal ) {
			// Expected.
			unset( $refusal );
		}

		$tail = $this->audit->tail( 1 );

		$this->assertCount( 1, $tail );
		$this->assertSame( 'db_relation_not_found', $tail[0]['refusal_code'] );
		$this->assertSame( 'select', $tail[0]['action'] );
		$this->assertSame( 'assistant:42', $tail[0]['principal'] );
		$this->assertSame( '', $tail[0]['sql_template'] );
	}

	/**
	 * A failed call leaves no transaction behind: the next call succeeds.
	 */
	public function test_failure_does_not_poison_the_connection(): void {
		try {
			$this->executor->select( 'warehouse', 'analyst', new SelectQuery( relation: 'main.secrets' ) );
		} catch ( DatabaseRefusal $refusal ) {
			// Expected.
			unset( $refusal );
		}

		$result = $this->executor->select(
			'warehouse',
			'analyst',
			new SelectQuery( relation: 'main.orders', columns: array( 'order_id' ), limit: 1 )
		);

		$this->assertSame( 1, $result->rowCount );
	}

	/**
	 * The catalogue is scope-filtered.
	 */
	public function test_describe_is_scope_filtered(): void {
		$catalogue = $this->executor->describe( 'warehouse' );

		$this->assertTrue( $catalogue->has( 'main', 'orders' ) );
		$this->assertFalse( $catalogue->has( 'main', 'sqlite_master' ) );
	}

	/**
	 * A raw SELECT is allowed when the scope holds the tier.
	 */
	public function test_raw_select_is_allowed_with_tier(): void {
		$result = $this->executor->query(
			'warehouse',
			'analyst',
			new RawQuery( 'SELECT order_id FROM "main"."orders" WHERE status = :status ORDER BY order_id', array( ':status' => 'paid' ) )
		);

		$this->assertSame( 2, $result->rowCount );
		$this->assertSame( array( 1, 2 ), array_map( 'intval', array_column( $result->rows, 'order_id' ) ) );
	}

	/**
	 * EXPLAIN returns a plan and refuses ANALYZE on SQLite.
	 */
	public function test_explain_and_analyze_gate(): void {
		$plan = $this->executor->explain(
			'warehouse',
			'analyst',
			new SelectQuery( relation: 'main.orders', columns: array( 'order_id' ), limit: 5 )
		);

		$this->assertFalse( $plan->analyzed );
		$this->assertSame( 'sqlite', $plan->engine );
		$this->assertNotSame( array(), $plan->plan );

		$this->expectException( DatabaseRefusal::class );

		$this->executor->explain(
			'warehouse',
			'analyst',
			new SelectQuery( relation: 'main.orders', columns: array( 'order_id' ), limit: 5 ),
			true
		);
	}

	/**
	 * SQLite's `query_only` pragma refuses a write at the connection level.
	 *
	 * This is the portable analogue of gate 6 (L2 removal) and of the Postgres
	 * SELECT-only-role test: admission is bypassed entirely here, and the engine
	 * still refuses.
	 */
	public function test_sqlite_query_only_blocks_writes(): void {
		$pdo = new PDO( 'sqlite:' . $this->path );
		$pdo->exec( 'BEGIN DEFERRED' );
		$pdo->exec( 'PRAGMA query_only = ON' );

		$this->expectException( PDOException::class );

		try {
			$pdo->exec( "INSERT INTO orders (order_id, status) VALUES (99, 'paid')" );
		} finally {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
		}
	}
}
