<?php
/**
 * Create the products table behind ProductCatalogInterface.
 *
 * Proposal 164 section 164.114. A product row carries its own fields and its
 * option definitions; the sellable rows live in nvoos_product_variants.
 *
 * Two decisions are encoded here rather than left to the connection:
 *
 * - **utf8mb4 is pinned per table.** A Cloudways PHP server ships
 *   `character_set_server = latin1` (verified on server 1686836 on 2026-11-27),
 *   so inheriting the database default would mangle every non-ASCII product name
 *   and description. Pinning it makes the table correct on a server whose default
 *   is not, and PostgreSQL ignores the property.
 * - **`external_ref` is 191 characters.** A unique index over utf8mb4 must fit
 *   MySQL's 767-byte key limit, which is 191 characters at four bytes each.
 *   255 would silently truncate the index rather than the column.
 *
 * `description` is nullable rather than `default('')` because MySQL does not
 * accept a default on TEXT; the adapter coalesces null to the empty string.
 *
 * @package Nvoos\Laravel
 * @since   1.5.0
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

	public function up(): void {
		Schema::create( 'nvoos_products', function ( Blueprint $table ) {
			$table->charset   = 'utf8mb4';
			$table->collation = 'utf8mb4_unicode_ci';

			$table->id();
			$table->string( 'name', 255 );
			$table->string( 'slug', 255 )->nullable();
			$table->text( 'description' )->nullable();
			$table->string( 'status', 20 )->default( 'draft' );
			$table->string( 'vendor', 255 )->nullable();
			$table->string( 'product_type', 255 )->nullable();
			$table->json( 'tags' )->nullable();

			// The product's option definitions: [{name, values: [..]}].
			$table->json( 'options' )->nullable();

			// The identity a migration matches on. Opaque to the core.
			$table->string( 'external_ref', 191 )->nullable()->unique();

			$table->timestamps();

			$table->index( 'status' );
			$table->index( 'vendor' );
			$table->index( 'created_at' );
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_products' );
	}
};
