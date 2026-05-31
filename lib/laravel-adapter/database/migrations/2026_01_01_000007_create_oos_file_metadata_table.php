<?php
/**
 * Create the oOS file metadata search table.
 *
 * EAV-style table for arbitrary key-value metadata on stored files,
 * enabling findByMetadata() searches. Each file can have multiple
 * metadata rows.
 *
 * @package Oos\Laravel
 * @since   1.0.0
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

	public function up(): void {
		Schema::create( 'oos_file_metadata', function ( Blueprint $table ) {
			$table->id();
			$table->unsignedBigInteger( 'file_id' );
			$table->string( 'meta_key', 100 );
			$table->text( 'meta_value' )->nullable();
			$table->timestamps();

			$table->index( 'file_id' );
			$table->index( 'meta_key' );
			$table->index( array( 'meta_key', 'meta_value' ) );

			$table->foreign( 'file_id' )->references( 'id' )->on( 'oos_files' )->onDelete( 'cascade' );
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'oos_file_metadata' );
	}
};
