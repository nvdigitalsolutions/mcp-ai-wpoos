<?php
/**
 * Create the agent memory table.
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
		Schema::create( 'nvoos_memories', function ( Blueprint $table ) {
			$table->string( 'id', 64 )->primary();
			$table->string( 'agent_id', 128 )->index();
			$table->string( 'title' )->nullable();
			$table->text( 'content' );
			$table->json( 'tags' )->nullable();
			$table->string( 'tier', 20 )->default( 'recall' )->index();
			$table->float( 'importance' )->default( 0.5 );
			$table->string( 'wing', 128 )->nullable()->index();
			$table->string( 'room', 128 )->nullable()->index();
			$table->unsignedInteger( 'ttl' )->nullable();
			$table->timestamp( 'expires_at' )->nullable()->index();
			$table->timestamps();
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_memories' );
	}
};
