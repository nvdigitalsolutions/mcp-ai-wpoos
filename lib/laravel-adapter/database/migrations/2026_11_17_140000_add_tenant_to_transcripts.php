<?php
/**
 * Estate ownership for chat transcripts — Proposal 149 §39.
 *
 * `nvoos_transcripts` shipped with no tenant column, and `TranscriptStore` read
 * and wrote it unscoped: `save()` upserted on `session_id` alone, and `list()`,
 * `get()`, `counts()` and `delete()` had no estate filter. Two defects followed:
 *
 *   1. any authenticated console user listing `/api/v1/chat-transcripts` saw
 *      every estate's sessions, and `show` returned another estate's full
 *      conversation (BOLA / API1 rather than a display bug);
 *   2. `session_id` was **globally unique**, so two estates that generated the
 *      same session key silently overwrote each other's transcript on the next
 *      save.
 *
 * The key therefore has to become per estate, not the column alone: adding
 * `tenant_id` while leaving the global unique would turn a silent overwrite into
 * a unique-constraint failure on the first collision, which is worse.
 *
 * The default is `'default'` for the same reason as §27 and §29: existing rows
 * predate the column and no signal can attribute them to another estate.
 *
 * Guarded, so a dev database that grew the column or the index out of band keeps
 * working (repo rule).
 *
 * @package Nvoos\Laravel
 * @since   1.2.24
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	private const TABLE = 'nvoos_transcripts';

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

		if ( in_array( 'nvoos_transcripts_session_id_unique', $indexes, true ) ) {
			Schema::table( self::TABLE, function ( Blueprint $table ) {
				$table->dropUnique( 'nvoos_transcripts_session_id_unique' );
			} );
		}

		if ( ! in_array( 'nvoos_transcripts_tenant_session_unique', $indexes, true ) ) {
			Schema::table( self::TABLE, function ( Blueprint $table ) {
				$table->unique(
					array( 'tenant_id', 'session_id' ),
					'nvoos_transcripts_tenant_session_unique'
				);
			} );
		}

		// The list read is "this estate's sessions, most recently used first", so
		// the ordering column joins the scope in one composite index.
		if ( ! in_array( 'nvoos_transcripts_tenant_updated_index', $indexes, true ) ) {
			Schema::table( self::TABLE, function ( Blueprint $table ) {
				$table->index(
					array( 'tenant_id', 'updated_at' ),
					'nvoos_transcripts_tenant_updated_index'
				);
			} );
		}
	}

	public function down(): void {
		// Transcripts are user data, and dropping `tenant_id` would silently
		// re-expose every estate's conversations — and restoring a global unique
		// on `session_id` would fail on the first legitimate duplicate. Removed by
		// hand if it ever should be.
	}
};
