<?php
/**
 * Create the generic toolkit record table.
 *
 * One table backs the entity workbench for every manifest-driven toolkit.
 * `data` holds the manifest's column payload as JSON; `external_id` mirrors
 * the manifest's `id_field` so records can be addressed by business key.
 *
 * @package Nvoos\Laravel
 * @since   1.4.0
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

	public function up(): void {
		Schema::create( 'nvoos_toolkit_records', function ( Blueprint $table ) {
			$table->id();
			$table->string( 'toolkit', 64 );
			$table->string( 'entity', 64 );
			$table->string( 'external_id', 128 )->nullable();
			$table->json( 'data' );
			$table->timestamps();

			$table->index( array( 'toolkit', 'entity' ) );
			$table->unique( array( 'toolkit', 'entity', 'external_id' ) );
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_toolkit_records' );
	}
};
