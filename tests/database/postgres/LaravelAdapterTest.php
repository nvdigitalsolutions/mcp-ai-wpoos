<?php

/**
 * The Laravel port against a real PostgreSQL (Proposal 167 gate 9, live half).
 *
 * Everything above this file is structural: the tool contracts match, the engine
 * profiles match. This one asks the question that matters — with the WordPress
 * stack nowhere in the process, does the Laravel binding read tenant-scoped rows,
 * mask sensitive columns, refuse a write, and record an audit trail without
 * bound values?
 *
 * The classes under test are the real ones: `DatabaseConfig`,
 * `PdoConnectionFactory`, `InformationSchemaCatalogue`, `ScopeRedactor`,
 * `LoggerAuditSink` and `PdoDatabaseConnection`. None of them imports
 * `Illuminate\`, which is why this can run here at all — the only Laravel-aware
 * piece is the service provider's binding.
 *
 * The role is `nvoos_reader` (SELECT only, NOBYPASSRLS), so a passing write test
 * would mean every layer failed at once.
 *
 * @package Nvoos\Tests
 * @since   3.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Tests\Database\Postgres;

use Nvoos\Core\Tests\Support\ArrayErrorFactory;
use Nvoos\Core\Tests\Support\FakeFileStore;
use Nvoos\Core\Tool\Database\ExportTool;
use Nvoos\Core\Tool\Database\TailAuditTool;
use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\FilterOperator;
use Nvoos\Database\Domain\Service\Database\GrantTier;
use Nvoos\Database\Domain\Service\Database\Metric;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\AggregateQuery;
use Nvoos\Database\Domain\ValueObject\Database\FilterClause;
use Nvoos\Database\Domain\ValueObject\Database\MetricClause;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use Nvoos\Database\Domain\ValueObject\Database\RawQuery;
use Nvoos\Database\Domain\ValueObject\Database\SelectQuery;
use Nvoos\Laravel\Database\DatabaseConfig;
use Nvoos\Laravel\Database\PdoConnectionFactory;
use Nvoos\Laravel\Database\PdoDatabaseConnection;
use Nvoos\Laravel\Database\TableAuditLog;
use Nvoos\Database\Domain\Service\Database\PdoProbe;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Nvoos\\Laravel\\';
		$length = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class_name, $length ) ) {
			return;
		}

		$file = dirname( __DIR__, 3 ) . '/lib/laravel-adapter/src/' . str_replace( '\\', '/', substr( $class_name, $length ) ) . '.php';

		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

final class LaravelAdapterTest extends TestCase {

	use \PostgresFixture;

	/**
	 * Audit records captured by the sink.
	 *
	 * @var list<array<string, mixed>>
	 */
	private static array $audit = array();

	protected function setUp(): void {
		self::maybe_skip_postgres();
		self::bootstrap_fixtures();
		self::$audit = array();
	}

	public function test_the_config_parses_into_registries(): void {
		$config = new DatabaseConfig( $this->config() );

		$this->assertCount( 1, $config->all() );
		$this->assertSame( 'warehouse', $config->get_or_refuse( 'warehouse' )->id );
		$this->assertCount( 1, $config->visible_to( $this->principal() ) );
		$this->assertSame( 'tenant', $config->resolve_for( $this->principal(), 'warehouse', null, GrantTier::READ )->id );
	}

	public function test_a_malformed_scope_is_dropped_rather_than_widened(): void {
		$raw            = $this->config();
		$raw['scopes']['wild'] = array(
			'connection' => 'warehouse',
			'schemas'    => array( '*' ),
			'tables'     => array( '*' ),
			'tiers'      => array( 'read' ),
			'principals' => array( 'assistant:42' ),
		);

		$config = new DatabaseConfig( $raw );

		$this->assertSame( 'tenant', $config->resolve_for( $this->principal(), 'warehouse', null, GrantTier::READ )->id );
	}

	public function test_a_scoped_read_is_tenant_filtered_and_masked(): void {
		$result = $this->port()->select(
			'warehouse',
			'tenant',
			new SelectQuery( relation: 'reporting.orders', limit: 3 )
		);

		        $this->assertSame(3, $result->rowCount);
		        $this->assertContains( 'reporting.orders.email', $result->masked );

		foreach ( $result->rows as $row ) {
			$this->assertSame( 'tenant-a', $row['tenant_id'] );
			$this->assertSame( '[masked]', $row['email'] );
		}
	}

	public function test_the_laravel_catalogue_sees_the_scope_and_nothing_else(): void {
		$port    = $this->port();
		$catalog = $port->describe( 'warehouse', 'reporting.orders' );

		$this->assertCount( 1, $catalog->tables );

		$tables = $catalog->tables;
		$table  = reset( $tables );

		$this->assertSame( 'reporting.orders', $table->key() );
		$this->assertArrayHasKey( 'tenant_id', $table->columns );
		$this->assertArrayHasKey( 'email', $table->columns );

		$this->expectException( DatabaseRefusal::class );

		$port->describe( 'warehouse', 'reporting.secrets' );
	}

	public function test_an_aggregate_counts_only_the_bound_tenant(): void {
		$result = $this->port()->aggregate(
			'warehouse',
			'tenant',
			new AggregateQuery(
				relation: 'reporting.orders',
				metrics: array( new MetricClause( Metric::COUNT, 'n' ) ),
				limit: 1
			)
		);

		$this->assertSame( 200000, (int) $result->rows[0]['n'] );
	}

	public function test_a_write_is_refused_even_with_raw_sql_granted(): void {
		$this->expectException( DatabaseRefusal::class );

		$this->port()->query(
			'warehouse',
			'tenant',
			new RawQuery( 'DELETE FROM reporting.orders WHERE tenant_id = :t', array( ':t' => 'tenant-a' ) )
		);
	}

	public function test_the_audit_trail_carries_no_bound_values(): void {
		$this->port()->select(
			'warehouse',
			'tenant',
			            new SelectQuery(
			                relation: 'reporting.orders',
			                filters: array(
			                    new FilterClause( 'status', FilterOperator::EQUALS, 'paid' ),
			                ),
			                limit: 1,
			            )
		);

		$this->assertNotEmpty( self::$audit );

		$encoded = (string) json_encode( self::$audit );

		$this->assertStringNotContainsString( 'paid', $encoded );
		$this->assertStringNotContainsString( 'tenant-a', $encoded );
		$this->assertStringContainsString( 'sql_template', $encoded );
	}

	public function test_the_probe_sees_a_select_only_role(): void {
		$connections = $this->port()->connections( $this->principal() );
		$descriptor  = $connections[0];

		$probe = ( new PdoProbe( new PdoConnectionFactory() ) )->probe( $descriptor );

		$this->assertTrue( $probe->reachable, 'fixture role unreachable: ' . (string) $probe->error );
		$this->assertSame( 'nvoos_reader', $probe->role );
		$this->assertFalse( $probe->canWrite, 'nvoos_reader must not hold a write grant' );
		$this->assertFalse( $probe->bypassesRls, 'nvoos_reader must not bypass RLS' );
	}

	public function test_the_export_tool_refuses_a_scope_without_the_export_tier(): void {
		$tool = new ExportTool( new ArrayErrorFactory(), $this->port(), new FakeFileStore() );

		$refused = $tool->execute(
			array( 'relation' => 'reporting.orders' ),
			array( 'assistant_id' => 42 )
		);

		$this->assertIsArray( $refused );
		$this->assertSame( 'db_tier_required', $refused['error']['data']['refusal_code'] );
	}

	public function test_the_table_ledger_records_a_call_and_can_be_tailed(): void {
		$pdo = static fn (): \PDO => self::admin_pdo();
		$pdo()->exec( 'DELETE FROM oos_database_audit' );

		$audit = new TableAuditLog( $pdo );
		$port  = PdoDatabaseConnection::build(
			$this->config(),
			null,
			null,
			static fn (): string => 'tenant-a',
			$audit
		)->for_principal( $this->principal() );

		$port->select( 'warehouse', 'tenant', new SelectQuery( relation: 'reporting.orders', limit: 2 ) );

		$records = $audit->tail( 5, array( 'connection' => 'warehouse' ) );

		$this->assertCount( 1, $records, 'one call, one record' );
		$this->assertSame( 'select', $records[0]['action'] );
		$this->assertSame( 'db_select', $records[0]['tool'] );
		$this->assertSame( 'reporting.orders', $records[0]['relation'] );
		$this->assertSame( 2, $records[0]['row_count'] );
		$this->assertSame( 'tenant', $records[0]['scope'] );
		$this->assertNull( $records[0]['refusal_code'] );
		$this->assertNotSame( '', (string) $records[0]['sql_template'] );

		// The whole point of the template: neither the tenant value nor any bound
		// value reaches the ledger.
		$this->assertStringNotContainsString( 'tenant-a', (string) json_encode( $records ) );

		// And the tool that reads it, over the same ledger.
		$page = ( new TailAuditTool( new ArrayErrorFactory(), $port, $audit ) )->execute( array( 'limit' => 5 ) );

		$this->assertTrue( $page['success'] );
		$this->assertSame( 1, $page['count'] );
		$this->assertSame( 0, $page['retained'], 'the ledger has no record ceiling' );
	}

	public function test_the_table_ledger_records_a_refusal(): void {
		$pdo = static fn (): \PDO => self::admin_pdo();
		$pdo()->exec( 'DELETE FROM oos_database_audit' );

		$audit = new TableAuditLog( $pdo );
		$port  = PdoDatabaseConnection::build(
			$this->config(),
			null,
			null,
			static fn (): string => 'tenant-a',
			$audit
		)->for_principal( $this->principal() );

		$code = '';

		try {
			$port->query(
				'warehouse',
				'tenant',
				new RawQuery( 'DELETE FROM reporting.orders WHERE tenant_id = :t', array( ':t' => 'tenant-a' ) )
			);

			$this->fail( 'a write must be refused' );
		} catch ( DatabaseRefusal $refusal ) {
			$code = $refusal->getRefusalCode();
		}

		$records = $audit->tail( 5, array( 'refusal_code' => $code ) );

		$this->assertCount( 1, $records );
		$this->assertSame( $code, $records[0]['refusal_code'] );
		$this->assertSame( $this->principal()->key(), $records[0]['principal'] );
	}

	public function test_the_export_tool_exports_real_masked_rows(): void {
		$store = new FakeFileStore();
		$tool  = new ExportTool( new ArrayErrorFactory(), $this->port(), $store );

		$payload = $tool->execute(
			array(
				'relation' => 'reporting.orders',
				'scope'    => 'exporter',
				'limit'    => 2,
			),
			array( 'assistant_id' => 42 )
		);

		$this->assertIsArray( $payload );
		$this->assertTrue( $payload['success'] );
		$this->assertSame( 2, $payload['rows'] );
		$this->assertContains( 'reporting.orders.email', $payload['masked'] );
		$this->assertStringContainsString( '[masked]', $store->payload );
		$this->assertStringContainsString( 'tenant-a', $store->payload );
		$this->assertSame( 'sha256:' . hash( 'sha256', $store->payload ), $payload['checksum'] );
	}

	private function port(): PdoDatabaseConnection {
		$port = PdoDatabaseConnection::build(
			$this->config(),
			null,
			static function ( array $record ): void {
				self::$audit[] = $record;
			},
			static fn (): string => 'tenant-a'
		);

		return $port->for_principal( $this->principal() );
	}

	private function principal(): PrincipalRef {
		return new PrincipalRef( PrincipalRef::ASSISTANT, '42' );
	}

	/**
	 * The config array a Laravel app would carry in `oos.database`.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		$parts = self::pg_parts();

		return array(
			'cursor_secret' => 'test-cursor-secret',
			'connections'   => array(
				'warehouse' => array(
					'label'       => 'Warehouse',
					'engine'      => 'pgsql',
					'host'        => $parts['host'],
					'port'        => $parts['port'],
					'database'    => $parts['dbname'],
					'sslmode'     => 'disable',
					'username'    => (string) ( getenv( 'NVOOS_TEST_PGSQL_READER_USER' ) ?: 'nvoos_reader' ),
					'password'    => (string) ( getenv( 'NVOOS_TEST_PGSQL_READER_PASSWORD' ) ?: 'reader' ),
					'probe'       => array(
						'reachable'    => true,
						'can_write'    => false,
						'bypasses_rls' => false,
					),
				),
			),
			'scopes'        => array(
				'tenant' => array(
					'label'      => 'Tenant',
					'connection' => 'warehouse',
					'schemas'    => array( 'reporting' ),
					'tables'     => array( 'reporting.orders', 'reporting.financial', 'reporting.notes' ),
					'columns'    => array(
						'reporting.orders' => array( 'sensitive' => array( 'email' ) ),
					),
					'row_filter' => array(
						array(
							'column'     => 'tenant_id',
							'op'         => 'eq',
							'value_from' => 'scope_tenant',
						),
					),
					'tiers'      => array( 'metadata', 'read', 'raw_sql' ),
					'principals' => array( 'assistant:42' ),
				),
				'other'    => array(
					'label'      => 'Other',
					'connection' => 'warehouse',
					'schemas'    => array( 'reporting' ),
					'tables'     => array( 'reporting.orders' ),
					'tiers'      => array( 'read' ),
					'principals' => array( 'user:999' ),
				),
				'exporter' => array(
					'label'      => 'Exporter',
					'connection' => 'warehouse',
					'schemas'    => array( 'reporting' ),
					'tables'     => array( 'reporting.orders' ),
					'columns'    => array(
						'reporting.orders' => array( 'sensitive' => array( 'email' ) ),
					),
					'row_filter' => array(
						array(
							'column'     => 'tenant_id',
							'op'         => 'eq',
							'value_from' => 'scope_tenant',
						),
					),
					'tiers'      => array( 'metadata', 'read', 'export' ),
					'principals' => array( 'assistant:42' ),
				),
			),
		);
	}
}
