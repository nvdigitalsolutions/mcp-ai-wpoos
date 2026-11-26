<?php
/**
 * Create the daily token usage rollup table.
 *
 * `nvoos_token_usage` is pruned at the retention window (149 §76), which would
 * take the cost history with it. This table is the aggregate that outlives the
 * raw rows: one row per day, provider and model, written by
 * `nvoos:rollup-token-usage` before retention runs.
 *
 * @package Nvoos\Laravel
 * @since   1.3.0
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

	public function up(): void {
		Schema::create( 'nvoos_token_usage_daily', function ( Blueprint $table ) {
			$table->id();
			$table->date( 'day' );
			$table->string( 'provider', 64 )->default( 'unknown' );
			$table->string( 'model_id', 120 );
			$table->unsignedInteger( 'events' )->default( 0 );
			$table->unsignedBigInteger( 'prompt_tokens' )->default( 0 );
			$table->unsignedBigInteger( 'completion_tokens' )->default( 0 );
			$table->unsignedBigInteger( 'total_tokens' )->default( 0 );
			$table->decimal( 'cost', 12, 6 )->default( 0 );
			$table->timestamp( 'updated_at' )->nullable();

			// The key the rollup upserts on, so re-running a day recomputes it
			// instead of appending a second copy.
			$table->unique( array( 'day', 'provider', 'model_id' ), 'nvoos_token_usage_daily_key' );
			$table->index( 'day' );
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_token_usage_daily' );
	}
};
