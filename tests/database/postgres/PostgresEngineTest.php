<?php

/**
 * PostgreSQL engine and privilege tests — gates 6 and 7.
 *
 * Proposal 167 section 7.5. These are the two gates that make the design true
 * rather than merely documented:
 *
 *  - **gate 6 (L2 removal):** with admission bypassed entirely, a write must
 *    still fail at the database role. The test opens the reader connection and
 *    issues the write directly, so nothing in `includes/database/` is involved.
 *  - **gate 7 (hostile corpus):** every committed statement must be refused by
 *    admission, and the server must independently refuse the ones that reach it.
 *
 * Plus the RLS proof: without `app.tenant_id` the reader sees zero rows, and with
 * it sees exactly one tenant.
 *
 * @package WP_MCP_AI\Tests\Database
 * @since   3.1.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Nvoos\Database\Domain\Service\Database\AdmissionClassifier;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Engine-level guarantees.
 */
#[CoversClass( AdmissionClassifier::class )]
#[CoversClass( WP_MCP_AI_Db_Probe::class )]
final class PostgresEngineTest extends TestCase {

	use PostgresFixture;

	/**
	 * The fixture is applied before each test (idempotent, once per process).
	 */
	protected function setUp(): void {
		parent::setUp();
		self::maybe_skip_postgres();
		self::bootstrap_fixtures();
	}

	/**
	 * The reader probe is reachable and safe for read scopes.
	 */
	public function test_probe_of_reader_is_read_safe(): void {
		$parts   = self::pg_parts();
		$factory = new WP_MCP_AI_Db_Connection_Factory( self::reader_store() );
		$probe   = ( new WP_MCP_AI_Db_Probe( $factory ) )->run( self::descriptor( $parts ) );

		$this->assertTrue( $probe->reachable );
		$this->assertFalse( $probe->canWrite, 'The reader role must hold no DML grant.' );
		$this->assertFalse( $probe->bypassesRls, 'The reader role must not bypass RLS.' );
		$this->assertTrue( $probe->isReadSafe() );
		$this->assertNotSame( '', $probe->serverVersion );
		$this->assertSame( 'nvoos_reader', $probe->role );
	}

	/**
	 * The writer probe reports its write capability rather than hiding it.
	 */
	public function test_probe_of_writer_reports_write_capability(): void {
		$parts   = self::pg_parts();
		$factory = new WP_MCP_AI_Db_Connection_Factory( self::writer_store() );
		$probe   = ( new WP_MCP_AI_Db_Probe( $factory ) )->run( self::descriptor( $parts ) );

		$this->assertTrue( $probe->reachable );
		$this->assertTrue( $probe->canWrite );
		$this->assertFalse( $probe->bypassesRls );
	}

	/**
	 * Gate 6: inside `BEGIN READ ONLY`, a write fails at the server.
	 *
	 * Admission, the grammar and every adapter class are bypassed: this is the
	 * reader role and raw PDO, which is exactly the "L2 removed" condition.
	 */
	public function test_gate6_write_fails_inside_read_only_transaction(): void {
		$pdo = self::reader_pdo();
		$pdo->exec( 'BEGIN READ ONLY' );

		try {
			$pdo->exec( "INSERT INTO reporting.notes (body) VALUES ('x')" );
			$this->fail( 'A write succeeded inside a read-only transaction.' );
		} catch ( PDOException $error ) {
			$this->assertSame( '25006', (string) $error->getCode(), 'Expected read_only_sql_transaction.' );
		} finally {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
		}
	}

	/**
	 * Gate 6 (second form): the role itself cannot write, even in a read-write
	 * transaction. This is the guarantee that survives a bug in the executor.
	 */
	public function test_gate6_role_cannot_write_outside_a_read_only_transaction(): void {
		$pdo = self::reader_pdo();
		$pdo->exec( 'BEGIN' );

		try {
			$pdo->exec( "INSERT INTO reporting.notes (body) VALUES ('x')" );
			$this->fail( 'The reader role was able to write.' );
		} catch ( PDOException $error ) {
			$this->assertSame( '42501', (string) $error->getCode(), 'Expected insufficient_privilege.' );
		} finally {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
		}
	}

	/**
	 * Gate 7: a stacked statement never becomes a prepared statement.
	 *
	 * Admission is bypassed here too; the server refuses two commands in one
	 * prepared statement with 42601.
	 */
	public function test_gate7_stacking_is_refused_by_the_server(): void {
		$pdo = self::reader_pdo();

		try {
			$statement = $pdo->prepare( 'SELECT 1; DROP TABLE reporting.notes' );
			$statement->execute();
			$this->fail( 'The server accepted a stacked statement.' );
		} catch ( PDOException $error ) {
			$this->assertSame( '42601', (string) $error->getCode(), 'Expected syntax_error for multiple commands.' );
		}
	}

	/**
	 * Gate 7: the committed hostile corpus is refused lexically.
	 *
	 * One assertion per entry, with the entry named in the failure message.
	 */
	public function test_gate7_hostile_corpus_is_refused(): void {
		$classifier = new AdmissionClassifier();

		$corpus = array(
			// Stacking.
			'SELECT 1; DROP TABLE reporting.notes',
			'SELECT 1;COMMIT',
			"SELECT 1;--\nDELETE FROM reporting.notes",
			// Transaction escape.
			'COMMIT',
			'ROLLBACK',
			'END',
			'ABORT',
			// CTE write.
			'WITH d AS (DELETE FROM reporting.notes RETURNING *) SELECT * FROM d',
			// Privilege.
			'SET ROLE app_admin',
			'SET SESSION AUTHORIZATION postgres',
			'SET search_path = public',
			'RESET ROLE',
			// File / OS.
			"COPY reporting.notes FROM PROGRAM 'id'",
			"SELECT pg_read_file('/etc/passwd')",
			"SELECT lo_import('/etc/passwd')",
			"SELECT dblink('dbname=x', 'select 1')",
			// Volume / delay.
			'SELECT pg_sleep(30)',
			// Credential surfaces.
			'SELECT * FROM pg_authid',
			'SELECT * FROM pg_shadow',
		);

		foreach ( $corpus as $statement ) {
			$refused = false;

			try {
				$classifier->admit( $statement, 'pgsql' );
			} catch ( \Nvoos\Database\Domain\Service\Database\DatabaseRefusal $refusal ) {
				$refused = true;
				$this->assertSame( RefusalCode::STATEMENT_NOT_ADMITTED->value, $refusal->getRefusalCode(), $statement );
			}

			$this->assertTrue( $refused, 'Admission allowed: ' . $statement );
		}
	}

	/**
	 * Gate 7: the corpus that reaches the server is also refused there.
	 *
	 * Each statement is a valid single `SELECT` on its face but reaches a surface
	 * the reader role may not use, so the failure is 42501 rather than lexical.
	 */
	public function test_gate7_privilege_corpus_is_refused_by_the_server(): void {
		$pdo = self::reader_pdo();

		$corpus = array(
			'SELECT * FROM reporting.orders FOR UPDATE',
			'SELECT * FROM pg_catalog.pg_authid',
		);

		foreach ( $corpus as $statement ) {
			try {
				$pdo->query( $statement );
				$this->fail( 'The server allowed: ' . $statement );
			} catch ( PDOException $error ) {
				$this->assertSame( '42501', (string) $error->getCode(), $statement );
			}
		}
	}

	/**
	 * RLS: with no tenant bound, the reader sees nothing.
	 */
	public function test_rls_hides_rows_without_a_tenant(): void {
		$count = (int) self::reader_pdo()->query( 'SELECT count(*) FROM reporting.orders' )->fetchColumn();

		$this->assertSame( 0, $count );
	}

	/**
	 * RLS: with the tenant bound, exactly one tenant is visible.
	 */
	public function test_rls_shows_only_the_bound_tenant(): void {
		$pdo = self::reader_pdo();
		$pdo->exec( 'BEGIN' );
		$pdo->exec( "SELECT set_config('app.tenant_id', 'tenant-a', true)" );

		$tenant_a = (int) $pdo->query( 'SELECT count(*) FROM reporting.orders' )->fetchColumn();

		$pdo->exec( "SELECT set_config('app.tenant_id', 'tenant-b', true)" );

		$tenant_b = (int) $pdo->query( 'SELECT count(*) FROM reporting.orders' )->fetchColumn();

		$pdo->rollBack();

		$this->assertSame( 200000, $tenant_a );
		$this->assertSame( 400, $tenant_b );
	}

	/**
	 * Descriptor for the fixture database.
	 *
	 * @param array{host: string, port: int, dbname: string} $parts Parsed DSN.
	 */
	private static function descriptor( array $parts ): \Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor {
		return new \Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor(
			id: 'warehouse',
			label: 'Warehouse',
			engine: 'pgsql',
			host: $parts['host'],
			port: $parts['port'],
			database: $parts['dbname'],
			sslmode: 'disable',
			credential: 'test'
		);
	}

	/**
	 * Credential store returning the reader role.
	 */
	private static function reader_store(): WP_MCP_AI_Db_Credential_Interface {
		return new class() implements WP_MCP_AI_Db_Credential_Interface {
			/**
			 * {@inheritDoc}
			 */
			public function resolve( string $handle ): array {
				unset( $handle );

				return array(
					'username' => (string) ( getenv( 'NVOOS_TEST_PGSQL_READER_USER' ) ?: 'nvoos_reader' ),
					'password' => (string) ( getenv( 'NVOOS_TEST_PGSQL_READER_PASSWORD' ) ?: 'reader' ),
				);
			}
		};
	}

	/**
	 * Credential store returning the writer role.
	 */
	private static function writer_store(): WP_MCP_AI_Db_Credential_Interface {
		return new class() implements WP_MCP_AI_Db_Credential_Interface {
			/**
			 * {@inheritDoc}
			 */
			public function resolve( string $handle ): array {
				unset( $handle );

				return array(
					'username' => (string) ( getenv( 'NVOOS_TEST_PGSQL_WRITER_USER' ) ?: 'nvoos_writer' ),
					'password' => (string) ( getenv( 'NVOOS_TEST_PGSQL_WRITER_PASSWORD' ) ?: 'writer' ),
				);
			}
		};
	}
}
