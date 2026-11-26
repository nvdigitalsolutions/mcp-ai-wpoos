<?php
/**
 * Add a provenance map to the agent memory table.
 *
 * A memory record carries things the columns do not express: the source a claim
 * came from, the window in which it was true, the capture policy a tool applied.
 * `MemoryStore` writes fixed columns, so any extra key a caller sent was dropped
 * without a trace — the accepted-then-ignored defect, in the one place a caller
 * cannot see it.
 *
 * A nullable JSON column gives those keys somewhere to live without adding a
 * column per concept, and without committing the contract to a fixed vocabulary
 * of provenance fields.
 *
 * Nullable and with no default on purpose: a record with no provenance stores
 * NULL rather than `{}`, so "nothing was recorded" is visible in the database
 * instead of looking like an empty answer.
 *
 * @package Nvoos\Laravel
 * @since   1.3.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

	public function up(): void {
		Schema::table( 'nvoos_memories', function ( Blueprint $table ) {
			$table->json( 'metadata' )->nullable()->after( 'room' );
		} );
	}

	public function down(): void {
		Schema::table( 'nvoos_memories', function ( Blueprint $table ) {
			$table->dropColumn( 'metadata' );
		} );
	}
};
