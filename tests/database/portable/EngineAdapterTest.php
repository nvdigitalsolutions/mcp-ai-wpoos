<?php

/**
 * Engine adapter contract tests.
 *
 * Proposal 167 section 7.5, gate 1 and the timeout unit table in section 4.4.
 * Every assertion here protects a decision that is invisible in review and
 * expensive in production: a milliseconds/seconds swap is a 1000x timeout bug,
 * an emulated prepare is a stacking path, and a persistent connection is GUC and
 * RLS leakage.
 *
 * @package WP_MCP_AI\Tests\Database
 * @since   3.1.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

use Nvoos\Database\Domain\ValueObject\Database\CompiledStatement;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\DatabaseLimits;
use Nvoos\Database\Domain\ValueObject\Database\RowFilterRule;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Engine adapter contract tests.
 */
#[CoversClass( WP_MCP_AI_Db_Engine_Pgsql::class )]
#[CoversClass( WP_MCP_AI_Db_Engine_Mysql::class )]
#[CoversClass( WP_MCP_AI_Db_Engine_Mariadb::class )]
#[CoversClass( WP_MCP_AI_Db_Engine_Sqlite::class )]
#[CoversClass( WP_MCP_AI_Db_Site_Database::class )]
final class EngineAdapterTest extends TestCase {

	/**
	 * Descriptor fixture.
	 *
	 * @param string $engine Engine.
	 */
	private function connection( string $engine, array $overrides = array() ): ConnectionDescriptor {
		return new ConnectionDescriptor(
			id: 'warehouse',
			label: 'Warehouse',
			engine: $engine,
			host: $overrides['host'] ?? 'db.example.test',
			port: $overrides['port'] ?? 5432,
			database: $overrides['database'] ?? 'analytics',
			sslmode: 'require'
		);
	}

	/**
	 * Scope fixture.
	 *
	 * @param list<RowFilterRule> $rules Row filters.
	 */
	private function scope( array $rules = array() ): ScopeDescriptor {
		return new ScopeDescriptor(
			id: 'analyst',
			label: 'Analyst',
			connection: 'warehouse',
			schemas: array( 'reporting' ),
			tables: array( '*' ),
			rowFilter: $rules,
			tiers: array()
		);
	}

	/**
	 * pgsql DSN carries no credentials and pins sslmode.
	 */
	public function test_pgsql_dsn_has_no_credentials(): void {
		$engine = new WP_MCP_AI_Db_Engine_Pgsql();
		$dsn    = $engine->dsn( $this->connection( ConnectionDescriptor::PGSQL ) );

		$this->assertStringStartsWith( 'pgsql:host=db.example.test;port=5432;dbname=analytics', $dsn );
		$this->assertStringContainsString( 'sslmode=require', $dsn );
		$this->assertStringNotContainsString( 'password', $dsn );
	}

	/**
	 * pgsql adds a CA path when one is configured.
	 */
	public function test_pgsql_dsn_includes_root_cert(): void {
		$engine = new WP_MCP_AI_Db_Engine_Pgsql();
		$dsn    = $engine->dsn(
			new ConnectionDescriptor(
				id: 'warehouse',
				label: 'Warehouse',
				engine: ConnectionDescriptor::PGSQL,
				host: 'db.example.test',
				port: 5432,
				database: 'analytics',
				sslmode: 'verify-full',
				sslRootCert: '/etc/ssl/ca.pem'
			)
		);

		$this->assertStringContainsString( 'sslrootcert=/etc/ssl/ca.pem', $dsn );
	}

	/**
	 * Emulation and persistence are off on both server engines.
	 */
	public function test_server_engines_disable_emulation_and_persistence(): void {
		foreach ( array( new WP_MCP_AI_Db_Engine_Pgsql(), new WP_MCP_AI_Db_Engine_Mysql(), new WP_MCP_AI_Db_Engine_Mariadb() ) as $engine ) {
			$options = $engine->options();

			$this->assertFalse( $options[ PDO::ATTR_EMULATE_PREPARES ], $engine->name() );
			$this->assertFalse( $options[ PDO::ATTR_PERSISTENT ], $engine->name() );
			$this->assertSame( PDO::ERRMODE_EXCEPTION, $options[ PDO::ATTR_ERRMODE ], $engine->name() );
			$this->assertSame( PDO::FETCH_ASSOC, $options[ PDO::ATTR_DEFAULT_FETCH_MODE ], $engine->name() );
		}
	}

	/**
	 * The multi-statement guard is present whenever the driver exposes it.
	 */
	public function test_mysql_disables_multi_statements(): void {
		if ( ! defined( 'PDO::MYSQL_ATTR_MULTI_STATEMENTS' ) && ! defined( 'Pdo\\Mysql::ATTR_MULTI_STATEMENTS' ) ) {
			$this->markTestSkipped( 'pdo_mysql is not loaded.' );
		}

		$attribute = defined( 'Pdo\\Mysql::ATTR_MULTI_STATEMENTS' )
			? (int) constant( 'Pdo\\Mysql::ATTR_MULTI_STATEMENTS' )
			: (int) constant( 'PDO::MYSQL_ATTR_MULTI_STATEMENTS' );

		$this->assertArrayHasKey( $attribute, ( new WP_MCP_AI_Db_Engine_Mysql() )->options() );
		$this->assertFalse( ( new WP_MCP_AI_Db_Engine_Mysql() )->options()[ $attribute ] );
	}

	/**
	 * Timeout units differ per engine and this is the test that catches a swap.
	 */
	public function test_timeout_units_are_per_engine(): void {
		$limits = new DatabaseLimits( timeoutMs: 5000 );
		$scope  = $this->scope();
		$conn   = $this->connection( ConnectionDescriptor::PGSQL );

		$pgsql = ( new WP_MCP_AI_Db_Engine_Pgsql() )->session_statements( $conn, $scope, $limits, 'nvoos:test', null );
		$this->assertSame( '5000', $this->binding( $pgsql[0] ), 'pgsql statement_timeout is milliseconds as a string.' );

		$mysql = ( new WP_MCP_AI_Db_Engine_Mysql() )->session_statements(
			$this->connection( ConnectionDescriptor::MYSQL, array( 'port' => 3306 ) ),
			$scope,
			$limits,
			'nvoos:test',
			null
		);
		$this->assertSame( 5000, $this->binding( $mysql[0] ), 'mysql max_execution_time is milliseconds.' );

		$mariadb = ( new WP_MCP_AI_Db_Engine_Mariadb() )->session_statements(
			$this->connection( ConnectionDescriptor::MARIADB, array( 'port' => 3306 ) ),
			$scope,
			$limits,
			'nvoos:test',
			null
		);
		$this->assertSame( '5.000', $this->binding( $mariadb[0] ), 'mariadb max_statement_time is fractional seconds.' );
	}

	/**
	 * Every engine starts read-only where the engine supports it.
	 */
	public function test_begin_statements_are_read_only(): void {
		$this->assertSame( 'BEGIN READ ONLY', ( new WP_MCP_AI_Db_Engine_Pgsql() )->begin_statement() );
		$this->assertSame( 'START TRANSACTION READ ONLY', ( new WP_MCP_AI_Db_Engine_Mysql() )->begin_statement() );
		$this->assertSame( 'START TRANSACTION READ ONLY', ( new WP_MCP_AI_Db_Engine_Mariadb() )->begin_statement() );
		$this->assertSame( 'BEGIN DEFERRED', ( new WP_MCP_AI_Db_Engine_Sqlite() )->begin_statement() );
	}

	/**
	 * pgsql pins search_path to the scope schemas plus pg_catalog.
	 */
	public function test_pgsql_search_path_is_scope_pinned(): void {
		$statements = ( new WP_MCP_AI_Db_Engine_Pgsql() )->session_statements(
			$this->connection( ConnectionDescriptor::PGSQL ),
			$this->scope(),
			new DatabaseLimits(),
			'nvoos:test',
			null
		);

		$search_path = null;

		foreach ( $statements as $statement ) {
			if ( str_contains( $statement->sql, 'search_path' ) ) {
				$search_path = $statement->bindings[':p0'] ?? null;
			}
		}

		$this->assertSame( '"reporting", pg_catalog', $search_path );
	}

	/**
	 * A tenant value is bound, never interpolated.
	 */
	public function test_tenant_value_is_bound(): void {
		$scope = $this->scope(
			array( new RowFilterRule( 'tenant_id', \Nvoos\Database\Domain\Service\Database\FilterOperator::EQUALS ) )
		);

		$statements = ( new WP_MCP_AI_Db_Engine_Pgsql() )->session_statements(
			$this->connection( ConnectionDescriptor::PGSQL ),
			$scope,
			new DatabaseLimits(),
			'nvoos:test',
			'tenant-a'
		);

		$tenant = null;

		foreach ( $statements as $statement ) {
			if ( str_contains( $statement->sql, 'app.tenant_id' ) ) {
				$tenant = $statement->bindings[':p0'] ?? null;
			}
		}

		$this->assertSame( 'tenant-a', $tenant );
	}

	/**
	 * SQLite is read-only at the connection level.
	 */
	public function test_sqlite_sets_query_only(): void {
		$statements = ( new WP_MCP_AI_Db_Engine_Sqlite() )->session_statements(
			$this->connection( ConnectionDescriptor::SQLITE, array( 'host' => '', 'port' => 0, 'database' => '/tmp/x.sqlite' ) ),
			$this->scope(),
			new DatabaseLimits(),
			'nvoos:test',
			null
		);

		$this->assertSame( 'PRAGMA query_only = ON', $statements[0]->sql );
	}

	/**
	 * The site database is refused, and only the explicit override passes.
	 */
	public function test_site_database_is_refused(): void {
		if ( ! defined( 'DB_HOST' ) || ! defined( 'DB_NAME' ) ) {
			$this->markTestSkipped( 'DB_HOST/DB_NAME are not defined in this environment.' );
		}

		$host = (string) DB_HOST;
		$name = (string) DB_NAME;

		$local = 'localhost' === $host || '127.0.0.1' === $host ? 'localhost' : 'db.example.test';

		$connection = new ConnectionDescriptor(
			id: 'site',
			label: 'Site',
			engine: ConnectionDescriptor::PGSQL,
			host: $local,
			port: 5432,
			database: $name
		);

		$this->assertTrue( WP_MCP_AI_Db_Site_Database::matches( $connection ) );

		$this->expectException( \Nvoos\Database\Domain\Service\Database\DatabaseRefusal::class );
		WP_MCP_AI_Db_Site_Database::assert_not_site_database( $connection );
	}

	/**
	 * A different database on the same host is allowed.
	 */
	public function test_different_database_is_allowed(): void {
		$connection = new ConnectionDescriptor(
			id: 'warehouse',
			label: 'Warehouse',
			engine: ConnectionDescriptor::PGSQL,
			host: 'db.example.test',
			port: 5432,
			database: 'analytics'
		);

		$this->assertFalse( WP_MCP_AI_Db_Site_Database::matches( $connection ) );
	}

	/**
	 * Engine EXPLAIN forms.
	 */
	public function test_explain_forms(): void {
		$statement = new CompiledStatement( 'SELECT 1', array() );

		$this->assertStringStartsWith(
			'EXPLAIN (FORMAT JSON, ANALYZE false',
			( new WP_MCP_AI_Db_Engine_Pgsql() )->explain_statement( $statement, false )->sql
		);
		$this->assertSame(
			'EXPLAIN FORMAT=JSON SELECT 1',
			( new WP_MCP_AI_Db_Engine_Mysql() )->explain_statement( $statement, false )->sql
		);
		$this->assertSame(
			'ANALYZE FORMAT=JSON SELECT 1',
			( new WP_MCP_AI_Db_Engine_Mariadb() )->explain_statement( $statement, true )->sql
		);
		$this->assertSame(
			'EXPLAIN QUERY PLAN SELECT 1',
			( new WP_MCP_AI_Db_Engine_Sqlite() )->explain_statement( $statement, false )->sql
		);
	}

	/**
	 * Extract the first binding of a session statement.
	 *
	 * @param CompiledStatement $statement Session statement.
	 * @return mixed
	 */
	private function binding( CompiledStatement $statement ): mixed {
		return $statement->bindings[':p0'] ?? null;
	}
}
