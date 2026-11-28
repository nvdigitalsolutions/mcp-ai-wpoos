<?php
/**
 * Create the append-only database audit table.
 *
 * Proposal 167 phase P3/P4. One row per executed database call and per refusal,
 * written by `Nvoos\Laravel\Database\TableAuditLog`, which the service provider
 * binds as both the sink and the reader.
 *
 * ## Why this is not `nvoos_` prefixed … almost
 *
 * It is, actually: `oos_` naming is used here because the table is the operator's
 * audit ledger rather than plugin content, and an operator grepping their own
 * schema should recognise it as the toolkit's. Rename it with
 * `oos.database.audit.table` if the estate has a convention.
 *
 * ## Columns
 *
 *  - `seq` — insertion order. `tail()` orders by it, because two records can
 *    share a second and a random `id` would make "newest first" a lottery.
 *  - `id` — the audit id the executor minted (`aud_<base36 time>_<hex>`), which
 *    is also returned to the caller as `audit_id`.
 *  - `sql_template` / `fingerprint` — what was asked, never what was matched.
 *    Bound values are not stored, so the trail is safe to export and ship.
 *  - `ts` — unix seconds, matching the WordPress record shape so a client reads
 *    both hosts with one code path.
 *
 * `ts` is indexed because retention is the operator's decision: the library never
 * deletes audit rows (see `TableAuditLog::retained()`), so pruning by age is the
 * supported way to bound the table.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

	public function up(): void {
		Schema::create( 'oos_database_audit', function ( Blueprint $table ) {
			$table->bigIncrements( 'seq' );
			$table->string( 'id', 32 )->unique();
			$table->unsignedBigInteger( 'ts' );
			$table->string( 'connection', 64 )->default( '' );
			$table->string( 'scope', 64 )->default( '' );
			$table->unsignedInteger( 'scope_version' )->default( 0 );
			$table->string( 'principal', 64 )->default( '' );
			$table->string( 'action', 32 )->default( '' );
			$table->string( 'tool', 64 )->default( '' );
			$table->string( 'relation', 200 )->default( '' );
			$table->string( 'refusal_code', 64 )->nullable();
			$table->text( 'sql_template' )->nullable();
			$table->string( 'fingerprint', 64 )->default( '' );
			$table->unsignedInteger( 'row_count' )->default( 0 );
			$table->unsignedInteger( 'elapsed_ms' )->default( 0 );
			$table->string( 'truncated', 32 )->default( '' );
			$table->text( 'detail' )->nullable();

			// Retention and the two lookup paths `db_audit_tail` uses.
			$table->index( 'ts' );
			$table->index( array( 'connection', 'ts' ) );
			$table->index( 'refusal_code' );
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'oos_database_audit' );
	}
};
