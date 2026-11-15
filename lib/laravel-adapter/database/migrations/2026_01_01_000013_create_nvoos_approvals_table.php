<?php
/**
 * Create the human-in-the-loop approval queue.
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
		Schema::create( 'nvoos_approvals', function ( Blueprint $table ) {
			$table->id();
			$table->string( 'status', 20 )->default( 'pending' )->index();
			$table->string( 'tool', 120 );
			$table->json( 'arguments' )->nullable();
			$table->unsignedBigInteger( 'assistant_id' )->nullable();
			$table->unsignedBigInteger( 'requester_id' )->nullable();
			$table->string( 'session_id', 128 )->nullable();
			$table->text( 'reason' )->nullable();
			$table->text( 'resolution_note' )->nullable();
			$table->unsignedBigInteger( 'resolved_by' )->nullable();
			$table->timestamp( 'resolved_at' )->nullable();
			$table->timestamp( 'expires_at' )->nullable()->index();
			$table->timestamps();
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_approvals' );
	}
};
