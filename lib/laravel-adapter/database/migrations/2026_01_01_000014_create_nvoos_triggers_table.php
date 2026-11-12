<?php
/**
 * Create the workflow trigger table.
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
		Schema::create( 'nvoos_triggers', function ( Blueprint $table ) {
			$table->id();
			$table->string( 'name' );
			$table->string( 'type', 40 )->index();
			$table->json( 'config' )->nullable();
			$table->unsignedBigInteger( 'workflow_id' )->nullable();
			$table->boolean( 'enabled' )->default( true )->index();
			$table->timestamp( 'last_fired_at' )->nullable();
			$table->string( 'webhook_url' )->nullable();
			$table->timestamps();
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_triggers' );
	}
};
