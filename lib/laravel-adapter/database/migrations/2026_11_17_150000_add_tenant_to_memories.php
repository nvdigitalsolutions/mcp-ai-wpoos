<?php
/**
 * Estate ownership for agent memory — Proposal 149 §40.
 *
 * `nvoos_memories` shipped with no tenant column, and `MemoryStore` named the
 * table directly in all six methods — `store`, `get`, `update`, `delete`,
 * `search` and `listByAgent`. The routes sit inside `ResolveApiTenant`, so an
 * estate was resolved on every request and then ignored: any authenticated
 * console user could search across every estate's memory and read, rewrite or
 * delete any row by id.
 *
 * `listByAgent` was no boundary either, because the agent id is a query
 * parameter (`?agent_id=`) — a filter, not an authorisation check.
 *
 * The default is `'default'` for the same reason as §27, §29 and §39: existing
 * rows predate the column and no signal can attribute them to another estate.
 *
 * Guarded, so a dev database that grew the column or an index out of band keeps
 * working (repo rule).
 *
 * @package Nvoos\Laravel
 * @since   1.2.22
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	private const TABLE = 'nvoos_memories';

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

		// "This estate's memory for an agent" is the recall read.
		if ( ! in_array( 'nvoos_memories_tenant_agent_index', $indexes, true ) ) {
			Schema::table( self::TABLE, function ( Blueprint $table ) {
				$table->index(
					array( 'tenant_id', 'agent_id' ),
					'nvoos_memories_tenant_agent_index'
				);
			} );
		}

		// The browser lists an estate's memory newest-first.
		if ( ! in_array( 'nvoos_memories_tenant_updated_index', $indexes, true ) ) {
			Schema::table( self::TABLE, function ( Blueprint $table ) {
				$table->index(
					array( 'tenant_id', 'updated_at' ),
					'nvoos_memories_tenant_updated_index'
				);
			} );
		}
	}

	public function down(): void {
		// Memory rows are user data, and dropping `tenant_id` would silently
		// re-expose every estate's memories. Removed by hand if it ever should be.
	}
};
