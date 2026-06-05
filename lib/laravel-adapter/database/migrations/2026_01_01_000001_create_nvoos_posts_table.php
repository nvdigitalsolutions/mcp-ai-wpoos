<?php
/**
 * Create the oOS posts table.
 *
 * This is the default content table used by Laravel's ContentStore adapter
 * when no custom model is configured. It mirrors the WordPress posts table
 * structure with Laravel conventions (timestamps, soft deletes optional).
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
		Schema::create( 'nvoos_posts', function ( Blueprint $table ) {
			$table->id();
			$table->string( 'title' );
			$table->longText( 'content' )->nullable();
			$table->string( 'status', 20 )->default( 'draft' );
			$table->string( 'type', 50 )->default( 'post' );
			$table->unsignedBigInteger( 'author_id' )->nullable();
			$table->text( 'excerpt' )->nullable();
			$table->string( 'slug' )->nullable();
			$table->json( 'meta' )->nullable();
			$table->timestamps();
			$table->softDeletes();

			$table->index( 'status' );
			$table->index( 'type' );
			$table->index( 'author_id' );
			$table->index( 'slug' );
			$table->unique( 'slug' );
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_posts' );
	}
};
