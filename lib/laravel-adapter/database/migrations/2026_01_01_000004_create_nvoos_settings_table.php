<?php
/**
 * Create the oOS runtime settings table.
 *
 * Stores admin-modifiable settings that override config file defaults.
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
		Schema::create( 'nvoos_settings', function ( Blueprint $table ) {
			$table->string( 'key' )->primary();
			$table->text( 'value' )->nullable();
			$table->timestamps();
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_settings' );
	}
};
