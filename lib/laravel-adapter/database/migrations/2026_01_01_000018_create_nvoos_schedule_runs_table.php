<?php
/**
 * Create the schedule run-history table.
 *
 * One row per execution. `history` reads the most recent rows,
 * `results` surfaces the captured tool result, and `fleet-stats`
 * aggregates success/failure counts from the same table.
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
		Schema::create( 'nvoos_schedule_runs', function ( Blueprint $table ) {
			$table->id();
			$table->string( 'schedule_id', 64 )->index();
			$table->string( 'status', 20 )->default( 'running' )->index();
			$table->timestamp( 'started_at' )->nullable();
			$table->timestamp( 'finished_at' )->nullable();
			$table->float( 'duration' )->nullable();
			$table->text( 'error' )->nullable();
			$table->json( 'log' )->nullable();
			$table->timestamps();
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_schedule_runs' );
	}
};
