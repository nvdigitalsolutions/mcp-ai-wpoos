<?php
/**
 * Add the preset install key to schedules.
 *
 * Schedule Anything presets install idempotently: replaying the same preset
 * with the same overrides returns the schedule created the first time. The
 * WordPress product keeps that map in an option; a runtime with a real
 * database keeps it on the row, where it survives restarts and can be
 * queried. Nullable + unique is deliberate — ordinary schedules have no key,
 * and both MySQL and SQLite allow many NULLs in a unique index.
 *
 * @package Nvoos\Laravel
 * @since   1.3.0
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

	public function up(): void {
		Schema::table( 'nvoos_schedules', function ( Blueprint $table ) {
			$table->string( 'preset_key', 191 )->nullable()->after( 'id' );
			$table->unique( 'preset_key' );
		} );
	}

	public function down(): void {
		Schema::table( 'nvoos_schedules', function ( Blueprint $table ) {
			$table->dropUnique( 'nvoos_schedules_preset_key_unique' );
			$table->dropColumn( 'preset_key' );
		} );
	}
};
