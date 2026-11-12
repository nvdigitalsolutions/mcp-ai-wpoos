<?php
/**
 * Create the Paper Store record table.
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
		Schema::create( 'nvoos_paper_records', function ( Blueprint $table ) {
			$table->string( 'id', 128 )->primary();
			$table->string( 'collection', 128 )->index();
			$table->string( 'title' );
			$table->text( 'description' )->nullable();
			$table->json( 'tags' )->nullable();
			$table->string( 'status', 20 )->default( 'draft' )->index();
			$table->string( 'type', 40 )->nullable();
			$table->json( 'body' )->nullable();
			$table->json( 'meta' )->nullable();
			$table->timestamps();
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_paper_records' );
	}
};
