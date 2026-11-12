<?php
/**
 * Create the token usage table.
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
		Schema::create( 'nvoos_token_usage', function ( Blueprint $table ) {
			$table->id();
			$table->unsignedBigInteger( 'user_id' )->default( 0 )->index();
			$table->string( 'model_id', 120 )->index();
			$table->unsignedInteger( 'prompt_tokens' )->default( 0 );
			$table->unsignedInteger( 'completion_tokens' )->default( 0 );
			$table->unsignedInteger( 'total_tokens' )->default( 0 );
			$table->decimal( 'cost', 12, 6 )->default( 0 );
			$table->json( 'metadata' )->nullable();
			$table->timestamp( 'created_at' )->nullable()->index();
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_token_usage' );
	}
};
