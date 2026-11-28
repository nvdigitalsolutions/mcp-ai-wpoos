<?php
/**
 * The audit ledger: schema, round trip, reader, retention.
 *
 * Proposal 167. The Laravel host's trail is an append-only table, and the
 * Postgres fixture cannot catch the drift that matters here: it hand-mirrors the
 * DDL with a comment, so a column renamed in the migration or in the writer's
 * `SELECT` would keep that suite green and break only in production.
 *
 * These tests run against the package's own migration on SQLite — the same
 * "package-only install in miniature" the file-registry test uses — and they
 * compare the migration's actual columns with the list `TableAuditLog` selects.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Laravel\Database\TableAuditLog;
use PDO;
use ReflectionClass;

final class DatabaseAuditLedgerTest extends TestCase {

	use RefreshDatabase;

	/**
	 * The migration creates exactly the columns the ledger reads, plus `seq`.
	 *
	 * `seq` is the one deliberate exception: it exists to make "newest first"
	 * exact when two records share a second, so it is ordered by and never
	 * projected. Every other column must be in both places — a column added to
	 * one side without the other is either a reader that will fail at runtime or
	 * a record nobody can see.
	 */
	public function test_the_migration_creates_every_column_the_ledger_reads(): void {
		$this->assertTrue( Schema::hasTable( TableAuditLog::TABLE ) );

		$reflection = new ReflectionClass( TableAuditLog::class );
		$constant   = $reflection->getReflectionConstant( 'COLUMNS' );

		$this->assertNotFalse( $constant, 'TableAuditLog::COLUMNS is the contract this test compares against.' );

		$read   = (array) $constant->getValue();
		$read[] = 'seq';

		$expected = $read;

		$actual = Schema::getColumnListing( TableAuditLog::TABLE );

		sort( $expected );
		sort( $actual );

		$this->assertSame(
			$expected,
			$actual,
			'the oos_database_audit migration and TableAuditLog disagree about the column set',
		);
	}

	/**
	 * The indexes match the only two access paths a ledger has.
	 *
	 * `ts` alone is retention (the operator's prune), and `(connection, ts)` is
	 * the filtered tail `db_audit_tail` serves. `refusal_code` is indexed because
	 * "show me what was refused today" is the query an incident starts with.
	 */
	public function test_the_ledger_is_indexed_for_retention_and_for_the_two_lookups(): void {
		foreach ( array(
			'oos_database_audit_ts_index',
			'oos_database_audit_connection_ts_index',
			'oos_database_audit_refusal_code_index',
			'oos_database_audit_id_unique',
		) as $index ) {
			$this->assertTrue(
				Schema::hasIndex( TableAuditLog::TABLE, $index ),
				"Missing index {$index}.",
			);
		}
	}

	/**
	 * A success and a refusal round-trip with their types intact.
	 *
	 * The WordPress log returns integers for the numeric fields; a client that
	 * reads both hosts must not have to care which driver stringified them.
	 */
	public function test_a_success_and_a_refusal_round_trip_in_newest_first_order(): void {
		$ledger = $this->ledger();

		$success = $ledger->append(
			array(
				'connection'    => 'warehouse',
				'scope'         => 'analyst',
				'scope_version' => 3,
				'principal'     => 'user:7',
				'action'        => 'select',
				'tool'          => 'db_select',
				'relation'      => 'public.orders',
				'sql_template'  => 'SELECT "id" FROM "public"."orders" WHERE "status" = ?',
				'fingerprint'   => 'sha256:9f2c',
				'row_count'     => 12,
				'elapsed_ms'    => 4,
				'detail'        => '',
			)
		);

		$refusal = $ledger->append(
			array(
				'connection'   => 'warehouse',
				'scope'        => 'analyst',
				'principal'    => 'user:7',
				'action'       => 'select',
				'tool'         => 'db_select',
				'refusal_code' => 'db_scope_table_not_allowed',
				'detail'       => 'relation outside the allowlist',
			)
		);

		$this->assertMatchesRegularExpression( '/^aud_[0-9a-z]+_[0-9a-f]{12}$/', $success );
		$this->assertNotSame( $success, $refusal );

		$rows = $ledger->tail( 10 );

		$this->assertCount( 2, $rows );

		// Newest first: the refusal was appended second.
		$this->assertSame( $refusal, $rows[0]['id'] );
		$this->assertSame( 'db_scope_table_not_allowed', $rows[0]['refusal_code'] );
		$this->assertSame( $success, $rows[1]['id'] );
		$this->assertNull( $rows[1]['refusal_code'] );

		$this->assertIsInt( $rows[1]['ts'] );
		$this->assertIsInt( $rows[1]['scope_version'] );
		$this->assertIsInt( $rows[1]['row_count'] );
		$this->assertIsInt( $rows[1]['elapsed_ms'] );
		$this->assertSame( 3, $rows[1]['scope_version'] );
		$this->assertSame( 12, $rows[1]['row_count'] );
		$this->assertSame( 'SELECT "id" FROM "public"."orders" WHERE "status" = ?', $rows[1]['sql_template'] );
	}

	/**
	 * `db_audit_tail`'s filters reach the ledger, not a post-filtered page.
	 */
	public function test_the_tail_filters_narrow_the_query(): void {
		$ledger = $this->ledger();

		$ledger->append( array( 'connection' => 'warehouse', 'action' => 'select', 'tool' => 'db_select' ) );
		$ledger->append(
			array(
				'connection'   => 'warehouse',
				'action'       => 'select',
				'tool'         => 'db_select',
				'refusal_code' => 'db_timeout',
			)
		);
		$ledger->append( array( 'connection' => 'billing', 'action' => 'describe', 'tool' => 'db_describe' ) );

		$this->assertCount( 1, $ledger->tail( 50, array( 'connection' => 'billing' ) ) );
		$this->assertCount( 1, $ledger->tail( 50, array( 'refusal_code' => 'db_timeout' ) ) );
		$this->assertCount( 2, $ledger->tail( 50, array( 'connection' => 'warehouse' ) ) );
		$this->assertCount( 2, $ledger->tail( 50, array( 'action' => 'select' ) ) );
		$this->assertCount( 3, $ledger->tail( 50 ) );
	}

	/**
	 * The summary counts by refusal over the window, matching `db_audit_tail`.
	 */
	public function test_the_summary_counts_refusals_over_the_window(): void {
		$ledger = $this->ledger();

		$ledger->append( array( 'action' => 'select', 'tool' => 'db_select' ) );
		$ledger->append( array( 'action' => 'select', 'tool' => 'db_select', 'refusal_code' => 'db_timeout' ) );
		$ledger->append( array( 'action' => 'select', 'tool' => 'db_select', 'refusal_code' => 'db_timeout' ) );
		$ledger->append( array( 'action' => 'select', 'tool' => 'db_select', 'refusal_code' => 'db_tier_required' ) );

		$summary = $ledger->summary( 3600 );

		$this->assertSame( 4, $summary['total'] );
		$this->assertSame( 2, $summary['db_timeout'] );
		$this->assertSame( 1, $summary['db_tier_required'] );
	}

	/**
	 * This host never prunes: an audit trail that deletes itself is not one.
	 */
	public function test_the_ledger_declares_no_record_ceiling(): void {
		$this->assertSame( 0, $this->ledger()->retained() );
	}

	/**
	 * No column can hold a bound value.
	 *
	 * The rule is "the statement template and a fingerprint, never a value".
	 * `sql_template` is asserted verbatim above; this is the structural half —
	 * there is nowhere else a value could land, so a future "let us keep the
	 * params for debugging" change has to break this test first.
	 */
	public function test_the_table_has_no_column_for_bound_values(): void {
		foreach ( array( 'bindings', 'params', 'parameters', 'values', 'args', 'arguments', 'payload' ) as $forbidden ) {
			$this->assertFalse(
				Schema::hasColumn( TableAuditLog::TABLE, $forbidden ),
				"oos_database_audit.{$forbidden} would be a place a bound value could be stored.",
			);
		}
	}

	/**
	 * A broken ledger must not turn a successful read into an error.
	 *
	 * `append()` swallows failures and reports through `onError`; this is the
	 * contract the executor relies on, so it is asserted rather than assumed.
	 */
	public function test_a_write_failure_is_reported_without_throwing(): void {
		$seen = array();

		$ledger = new TableAuditLog(
			static fn (): PDO => DB::connection()->getPdo(),
			'a_table_that_does_not_exist',
			static function ( \Throwable $error ) use ( &$seen ): void {
				$seen[] = $error->getMessage();
			},
		);

		$id = $ledger->append( array( 'action' => 'select' ) );

		$this->assertMatchesRegularExpression( '/^aud_/', $id );
		$this->assertNotEmpty( $seen, 'The failure should have been reported to the sink callback.' );
		$this->assertSame( array(), $ledger->tail( 5 ) );
	}

	/**
	 * The ledger the provider binds, on the application's own connection.
	 */
	private function ledger(): TableAuditLog {
		return new TableAuditLog( static fn (): PDO => DB::connection()->getPdo() );
	}
}
