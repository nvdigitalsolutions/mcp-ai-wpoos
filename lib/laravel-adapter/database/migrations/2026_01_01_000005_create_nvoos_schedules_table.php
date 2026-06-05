<?php
/**
 * Create the oOS recurring schedules table.
 *
 * Stores cron schedule definitions that a console command
 * or scheduler task reads to dispatch recurring jobs.
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
		Schema::create( 'nvoos_schedules', function ( Blueprint $table ) {
			$table->string( 'id', 64 )->primary();
			$table->string( 'handler', 255 );
			$table->text( 'payload' )->nullable();
			$table->string( 'cron_expression', 64 );
			$table->timestamps();
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_schedules' );
	}
};
