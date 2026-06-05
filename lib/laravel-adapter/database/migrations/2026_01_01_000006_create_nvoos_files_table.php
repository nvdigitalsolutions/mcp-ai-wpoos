<?php
/**
 * Create the oOS files tracking table.
 *
 * Maps numeric file IDs to storage paths so the FileStore adapter
 * can resolve IDs back to Flysystem paths. Applications that use
 * their own files/media models can skip this table.
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
		Schema::create( 'nvoos_files', function ( Blueprint $table ) {
			$table->id();
			$table->string( 'path' );
			$table->string( 'disk', 50 )->default( 'public' );
			$table->string( 'filename' );
			$table->string( 'mime_type', 100 )->nullable();
			$table->unsignedBigInteger( 'size_bytes' )->default( 0 );
			$table->unsignedBigInteger( 'owner_id' )->nullable();
			$table->timestamps();

			$table->index( 'path' );
			$table->index( 'owner_id' );
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_files' );
	}
};
