<?php
/**
 * Create the oOS post-term pivot table.
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
		Schema::create( 'nvoos_post_term', function ( Blueprint $table ) {
			$table->unsignedBigInteger( 'post_id' );
			$table->unsignedBigInteger( 'term_id' );
			$table->primary( array( 'post_id', 'term_id' ) );

			$table->foreign( 'post_id' )->references( 'id' )->on( 'nvoos_posts' )->onDelete( 'cascade' );
			$table->foreign( 'term_id' )->references( 'id' )->on( 'nvoos_terms' )->onDelete( 'cascade' );
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_post_term' );
	}
};
