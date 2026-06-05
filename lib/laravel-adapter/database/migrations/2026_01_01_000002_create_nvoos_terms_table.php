<?php
/**
 * Create the oOS terms table (taxonomy).
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
		Schema::create( 'nvoos_terms', function ( Blueprint $table ) {
			$table->id();
			$table->string( 'name' );
			$table->string( 'taxonomy', 50 )->default( 'category' );
			$table->string( 'slug' )->nullable();
			$table->text( 'description' )->nullable();
			$table->timestamps();

			$table->index( 'taxonomy' );
			$table->index( 'slug' );
			$table->unique( array( 'name', 'taxonomy' ) );
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_terms' );
	}
};
