<?php
/**
 * Create the durable oOS job status table.
 *
 * QueueClient records every enqueued job here, and NvoosToolJob writes
 * the lifecycle transitions. This is driver-agnostic: Redis and SQS do
 * not expose a `jobs` table.
 *
 * @package Nvoos\Laravel
 * @since   1.1.0
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

	public function up(): void {
		Schema::create( 'nvoos_jobs', function ( Blueprint $table ) {
			$table->string( 'id', 64 )->primary();
			$table->string( 'handler', 255 )->index();
			$table->text( 'payload' )->nullable();
			$table->string( 'status', 20 )->default( 'queued' )->index();
			$table->unsignedInteger( 'attempts' )->default( 0 );
			$table->text( 'result' )->nullable();
			$table->text( 'error' )->nullable();
			$table->timestamp( 'queued_at' )->nullable();
			$table->timestamp( 'started_at' )->nullable();
			$table->timestamp( 'completed_at' )->nullable();
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_jobs' );
	}
};
