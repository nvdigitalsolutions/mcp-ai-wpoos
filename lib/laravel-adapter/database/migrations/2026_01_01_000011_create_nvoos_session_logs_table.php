<?php
/**
 * Create the session log table.
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
		Schema::create( 'nvoos_session_logs', function ( Blueprint $table ) {
			$table->id();
			$table->string( 'session_id', 128 )->index();
			$table->json( 'entry' );
			$table->timestamps();
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_session_logs' );
	}
};
