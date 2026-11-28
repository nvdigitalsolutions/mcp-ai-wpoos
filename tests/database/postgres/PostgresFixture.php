<?php

/**
 * PostgreSQL fixture harness.
 *
 * Proposal 167 section 7.2. One database, two roles, two tenants, loaded once
 * per suite. The suite passes on a machine with no Postgres: every test calls
 * `maybe_skip_postgres()` first, which skips unless `NVOOS_TEST_PGSQL_DSN` is
 * set.
 *
 * The fixture SQL is applied as a superuser, so the roles it creates are
 * genuinely unprivileged. That is what makes gate 6 meaningful: the reader role
 * has `SELECT` and nothing else, and no `BYPASSRLS`.
 *
 * @package WP_MCP_AI\Tests\Database
 * @since   3.1.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use PHPUnit\Framework\Assert;

/**
 * Shared setup for the Postgres-dependent tests.
 */
trait PostgresFixture {

	/**
	 * Whether the fixture schema has been applied in this process.
	 *
	 * @var bool
	 */
	private static bool $bootstrapped = false;

	/**
	 * Skip the test when no Postgres DSN is configured.
	 */
	protected static function maybe_skip_postgres(): void {
		if ( '' === (string) getenv( 'NVOOS_TEST_PGSQL_DSN' ) ) {
			self::markTestSkipped( 'NVOOS_TEST_PGSQL_DSN is not set.' );
		}
	}

	/**
	 * The reader DSN.
	 */
	protected static function pg_dsn(): string {
		return (string) getenv( 'NVOOS_TEST_PGSQL_DSN' );
	}

	/**
	 * Parse the DSN's parts.
	 *
	 * @return array{host: string, port: int, dbname: string}
	 */
	protected static function pg_parts(): array {
		$dsn  = preg_replace( '/^pgsql:/', '', self::pg_dsn() ) ?? '';
		$data = array();

		parse_str( str_replace( ';', '&', $dsn ), $data );

		return array(
			'host'   => (string) ( $data['host'] ?? '127.0.0.1' ),
			'port'   => (int) ( $data['port'] ?? 5432 ),
			'dbname' => (string) ( $data['dbname'] ?? 'nvoos_test' ),
		);
	}

	/**
	 * Open a connection as an arbitrary role.
	 *
	 * @param string $user     Role.
	 * @param string $password Password.
	 */
	protected static function pg_pdo( string $user, string $password ): PDO {
		return new PDO(
			self::pg_dsn(),
			$user,
			$password,
			array(
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			)
		);
	}

	/**
	 * The superuser connection used to apply the fixture SQL.
	 */
	protected static function admin_pdo(): PDO {
		return self::pg_pdo(
			(string) ( getenv( 'NVOOS_TEST_PGSQL_ADMIN_USER' ) ?: 'postgres' ),
			(string) ( getenv( 'NVOOS_TEST_PGSQL_ADMIN_PASSWORD' ) ?: 'postgres' )
		);
	}

	/**
	 * Apply the fixture SQL once per process.
	 */
	protected static function bootstrap_fixtures(): void {
		if ( self::$bootstrapped ) {
			return;
		}

		$sql = file_get_contents( __DIR__ . '/../fixtures/postgres.sql' );

		if ( false === $sql || '' === trim( $sql ) ) {
			self::fail( 'Could not read tests/database/fixtures/postgres.sql.' );
		}

		self::admin_pdo()->exec( $sql );

		self::$bootstrapped = true;
	}

	/**
	 * The reader role's PDO.
	 */
	protected static function reader_pdo(): PDO {
		return self::pg_pdo(
			(string) ( getenv( 'NVOOS_TEST_PGSQL_READER_USER' ) ?: 'nvoos_reader' ),
			(string) ( getenv( 'NVOOS_TEST_PGSQL_READER_PASSWORD' ) ?: 'reader' )
		);
	}

	/**
	 * The writer role's PDO.
	 */
	protected static function writer_pdo(): PDO {
		return self::pg_pdo(
			(string) ( getenv( 'NVOOS_TEST_PGSQL_WRITER_USER' ) ?: 'nvoos_writer' ),
			(string) ( getenv( 'NVOOS_TEST_PGSQL_WRITER_PASSWORD' ) ?: 'writer' )
		);
	}

	/**
	 * Build an executor wired to the fixture database.
	 *
	 * @param string      $role   `reader` or `writer`.
	 * @param string|null $tenant Tenant value the scope resolves, or null.
	 * @return array{executor: WP_MCP_AI_Db_Executor, audit: WP_MCP_AI_Db_Audit_Log, connections: WP_MCP_AI_Db_Connection_Registry, scopes: WP_MCP_AI_Db_Scope_Registry, factory: WP_MCP_AI_Db_Connection_Factory}
	 */
	protected static function make_executor( string $role = 'reader', ?string $tenant = null ): array {
		self::bootstrap_fixtures();

		$parts      = self::pg_parts();
		$can_write  = 'writer' === $role;
		$connection = array(
			'id'       => 'warehouse',
			'label'    => 'Warehouse',
			'engine'   => 'pgsql',
			'host'     => $parts['host'],
			'port'     => $parts['port'],
			'database' => $parts['dbname'],
			'sslmode'  => 'disable',
			'credential' => 'test',
			'probe'    => array(
				'reachable'     => true,
				'can_write'     => $can_write,
				'bypasses_rls'  => false,
				'checked_at'    => time(),
			),
		);

		$scopes = new WP_MCP_AI_Db_Scope_Registry(
			array(
				'analyst' => array(
					'id'         => 'analyst',
					'connection' => 'warehouse',
					'schemas'    => array( 'reporting' ),
					'tables'     => array( '*' ),
					'columns'    => array(
						'reporting.orders' => array( 'sensitive' => array( 'email' ) ),
					),
					'tiers'      => array( 'metadata', 'read', 'raw_sql', 'explain_analyze' ),
					'limits'     => array(
						'cell_bytes' => 1024,
					),
					'principals' => array( 'assistant:42' ),
				),
				'slow'    => array(
					'id'         => 'slow',
					'connection' => 'warehouse',
					'schemas'    => array( 'reporting' ),
					'tables'     => array( 'reporting.orders' ),
					'row_filter' => array(
						array(
							'column'     => 'tenant_id',
							'op'         => 'eq',
							'value_from' => 'scope_tenant',
						),
					),
					'tiers'      => array( 'metadata', 'read', 'raw_sql' ),
					'limits'     => array( 'timeout_ms' => 150 ),
					'principals' => array( 'assistant:42' ),
				),
				'tenant'  => array(
					'id'         => 'tenant',
					'connection' => 'warehouse',
					'schemas'    => array( 'reporting' ),
					'tables'     => array( 'reporting.orders', 'reporting.orders_barrier', 'reporting.financial', 'reporting.notes' ),
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
				'writer'  => array(
					'id'         => 'writer',
					'connection' => 'warehouse',
					'schemas'    => array( 'reporting' ),
					'tables'     => array( 'reporting.orders', 'reporting.notes' ),
					'row_filter' => array(
						array(
							'column'     => 'tenant_id',
							'op'         => 'eq',
							'value_from' => 'scope_tenant',
						),
					),
					'tiers'      => array( 'metadata', 'read', 'propose_write', 'apply_write' ),
					'limits'     => array(
						'max_affected_rows' => 10,
						'max_rows'          => 50,
					),
					'principals' => array( 'assistant:42' ),
				),
			)
		);

		$connections = new WP_MCP_AI_Db_Connection_Registry( null, $scopes, array( 'warehouse' => $connection ) );

		$credentials = new class( $role ) implements WP_MCP_AI_Db_Credential_Interface {
			/**
			 * Role name.
			 *
			 * @var string
			 */
			private string $role;

			/**
			 * Constructor.
			 *
			 * @param string $role Role.
			 */
			public function __construct( string $role ) {
				$this->role = $role;
			}

			/**
			 * {@inheritDoc}
			 */
			public function resolve( string $handle ): array {
				unset( $handle );

				if ( 'writer' === $this->role ) {
					return array(
						'username' => (string) ( getenv( 'NVOOS_TEST_PGSQL_WRITER_USER' ) ?: 'nvoos_writer' ),
						'password' => (string) ( getenv( 'NVOOS_TEST_PGSQL_WRITER_PASSWORD' ) ?: 'writer' ),
					);
				}

				return array(
					'username' => (string) ( getenv( 'NVOOS_TEST_PGSQL_READER_USER' ) ?: 'nvoos_reader' ),
					'password' => (string) ( getenv( 'NVOOS_TEST_PGSQL_READER_PASSWORD' ) ?: 'reader' ),
				);
			}
		};

		$factory   = new WP_MCP_AI_Db_Connection_Factory( $credentials );
		$catalogue = new WP_MCP_AI_Db_Catalogue( $factory, new WP_MCP_AI_Db_Array_Cache() );
		$audit     = new WP_MCP_AI_Db_Audit_Log( array() );

		$executor = ( new WP_MCP_AI_Db_Executor(
			$connections,
			$scopes,
			$catalogue,
			$factory,
			$audit,
			new WP_MCP_AI_Db_Redactor(),
			'postgres-fixture-cursor-secret',
			null,
			static fn (): ?string => $tenant
		) )->for_principal( new PrincipalRef( 'assistant', '42' ) );

		return array(
			'executor'    => $executor,
			'audit'       => $audit,
			'connections' => $connections,
			'scopes'      => $scopes,
			'factory'     => $factory,
		);
	}

	/**
	 * Assert a callable refuses with a specific code.
	 *
	 * @param callable $call   Call.
	 * @param string   $code   Expected refusal code.
	 * @param string   $reason Failure message.
	 */
	protected static function assert_refusal( callable $call, string $code, string $reason = '' ): void {
		try {
			$call();
		} catch ( DatabaseRefusal $refusal ) {
			Assert::assertSame( $code, $refusal->getRefusalCode(), $reason );

			return;
		}

		Assert::fail( 'Expected a refusal (' . $code . '). ' . $reason );
	}
}
