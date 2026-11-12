<?php
/**
 * Extend the schedules table with the console-facing definition columns.
 *
 * The original table only carried what the scheduler needed (handler,
 * payload, cron_expression). The Schedule Manager console also needs a
 * human label, an enabled flag, a priority, tags and run counters, so those
 * live alongside the scheduler fields rather than in a parallel table.
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
		Schema::table( 'nvoos_schedules', function ( Blueprint $table ) {
			$table->string( 'name' )->nullable()->after( 'id' );
			$table->text( 'description' )->nullable()->after( 'name' );
			$table->string( 'schedule_type', 32 )->default( 'cron' )->after( 'description' );
			$table->boolean( 'enabled' )->default( true )->after( 'schedule_type' );
			$table->timestamp( 'next_run_at' )->nullable()->after( 'enabled' );
			$table->timestamp( 'last_run_at' )->nullable()->after( 'next_run_at' );
			$table->unsignedTinyInteger( 'priority' )->default( 10 )->after( 'last_run_at' );
			$table->json( 'tags' )->nullable()->after( 'priority' );
			$table->unsignedInteger( 'run_count' )->default( 0 )->after( 'tags' );

			$table->index( 'enabled' );
			$table->index( 'next_run_at' );
		} );
	}

	public function down(): void {
		Schema::table( 'nvoos_schedules', function ( Blueprint $table ) {
			$table->dropIndex( 'nvoos_schedules_enabled_index' );
			$table->dropIndex( 'nvoos_schedules_next_run_at_index' );

			$table->dropColumn( array(
				'name',
				'description',
				'schedule_type',
				'enabled',
				'next_run_at',
				'last_run_at',
				'priority',
				'tags',
				'run_count',
			) );
		} );
	}
};
