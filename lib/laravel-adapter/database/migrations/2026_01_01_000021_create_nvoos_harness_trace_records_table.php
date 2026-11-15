<?php
/**
 * Create the harness trace record table.
 *
 * The Laravel runtime has no post meta and no uploads tree to mirror
 * WordPress's `mcp-ai-harness-traces/` layout, so trace records live in a
 * table: one row per appended record, grouped into runs for retention.
 *
 * @package Nvoos\Laravel
 * @since   1.6.0
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

	public function up(): void {
		Schema::create( 'nvoos_harness_trace_records', function ( Blueprint $table ) {
			$table->bigIncrements( 'id' );
			$table->unsignedBigInteger( 'assistant_id' );
			$table->string( 'run_id', 64 );
			$table->string( 'artifact', 64 );
			$table->json( 'payload' );
			$table->timestamp( 'created_at' )->nullable();

			// Pruning walks runs newest-first per assistant.
			$table->index( array( 'assistant_id', 'run_id' ) );
			$table->index( array( 'assistant_id', 'created_at' ) );
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_harness_trace_records' );
	}
};
