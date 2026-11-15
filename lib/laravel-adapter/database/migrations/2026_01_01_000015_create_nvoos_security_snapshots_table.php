<?php
/**
 * Create the security settings snapshot table.
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
		Schema::create( 'nvoos_security_snapshots', function ( Blueprint $table ) {
			$table->string( 'id', 64 )->primary();
			$table->string( 'label' )->nullable();
			$table->json( 'payload' );
			$table->timestamps();
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_security_snapshots' );
	}
};
