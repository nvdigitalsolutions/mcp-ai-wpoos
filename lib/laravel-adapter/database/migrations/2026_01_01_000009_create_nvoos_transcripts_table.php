<?php
/**
 * Create the transcript store table.
 *
 * One row per chat session — `save()` upserts by session_id so the console's
 * session list maps directly to rows.
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

	public function up(): void {
		Schema::create( 'nvoos_transcripts', function ( Blueprint $table ) {
			$table->string( 'id', 64 )->primary();
			$table->string( 'session_id', 128 )->unique();
			$table->string( 'assistant_id', 64 )->nullable()->index();
			$table->json( 'messages' );
			$table->json( 'metadata' )->nullable();
			$table->timestamps();
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_transcripts' );
	}
};
