<?php
/**
 * Estate ownership for the approval queue — Proposal 149 §41.
 *
 * `nvoos_approvals` carried no tenant flag at all. `ApprovalsController` named the
 * table directly in `index`, `store` and `resolve`, and all three routes sit
 * inside `ResolveApiTenant` — so an estate was resolved on every request and then
 * ignored:
 *
 *   - `index` listed every estate's queue;
 *   - `resolve` loaded any id and then wrote `approved`/`denied` to it.
 *
 * `resolve` is the sharp edge, exactly as `fire` was in §29: an approval is the
 * human gate in front of a destructive tool call, so a cross-estate resolve let
 * one estate's administrator **authorise another estate's** gated action.
 *
 * The default is `'default'` for the same reason as §27, §29, §39 and §40:
 * existing rows predate the column and no signal can attribute them to another
 * estate.
 *
 * The table can be created by either this package's migration or the app's
 * `create_nvoos_runtime_tables` (both guarded by `hasTable`); the column list is
 * identical, so one guarded ALTER covers both paths.
 *
 * @package Nvoos\Laravel
 * @since   1.2.24
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	private const TABLE = 'nvoos_approvals';

	public function up(): void {
		if ( ! Schema::hasTable( self::TABLE ) ) {
			return;
		}

		if ( ! Schema::hasColumn( self::TABLE, 'tenant_id' ) ) {
			Schema::table( self::TABLE, function ( Blueprint $table ) {
				$table->string( 'tenant_id', 64 )->default( 'default' );
			} );
		}

		$indexes = array_column( Schema::getIndexes( self::TABLE ), 'name' );

		// The queue read is "pending for this estate, newest first".
		if ( ! in_array( 'nvoos_approvals_tenant_queue_index', $indexes, true ) ) {
			Schema::table( self::TABLE, function ( Blueprint $table ) {
				$table->index(
					array( 'tenant_id', 'status', 'created_at' ),
					'nvoos_approvals_tenant_queue_index'
				);
			} );
		}
	}

	public function down(): void {
		// Approval rows are a compliance artifact, and dropping `tenant_id` would
		// silently re-expose every estate's queue. Removed by hand if it ever
		// should be.
	}
};
