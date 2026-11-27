<?php
/**
 * Create the product variants table behind ProductCatalogInterface.
 *
 * Proposal 164 section 164.114. The sellable rows: SKU, price, stock and the
 * option values this variant chose.
 *
 * Money is stored as an integer in the currency's minor unit plus its ISO code,
 * never as a float or a decimal string. That is the core's `Money` decision
 * (section 164.111) carried into storage: `price_minor = 1999` is exact, and the
 * currency travels with it so a row can never be read as the wrong unit.
 *
 * `sku` is unique because `ProductCatalogInterface::findBySku()` returns one
 * variant and a duplicate makes it arbitrary. Shopify tolerates a duplicate SKU
 * across products; a migration that imports one gets a validation error naming
 * the SKU rather than a silently ambiguous lookup.
 *
 * `options` is the per-variant value map (`{Size: "M"}`) and is nullable-free:
 * an empty object is a variant with no options, which is a simple product.
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
		Schema::create( 'nvoos_product_variants', function ( Blueprint $table ) {
			$table->charset   = 'utf8mb4';
			$table->collation = 'utf8mb4_unicode_ci';

			$table->id();
			$table->foreignId( 'product_id' )
				->constrained( 'nvoos_products' )
				->cascadeOnDelete();

			$table->string( 'sku', 191 )->nullable()->unique();

			$table->bigInteger( 'price_minor' )->default( 0 );
			$table->char( 'price_currency', 3 )->default( 'USD' );

			$table->bigInteger( 'compare_at_price_minor' )->nullable();
			$table->char( 'compare_at_price_currency', 3 )->nullable();

			$table->bigInteger( 'cost_minor' )->nullable();
			$table->char( 'cost_currency', 3 )->nullable();

			$table->string( 'barcode', 191 )->nullable();
			$table->integer( 'stock' )->nullable();
			$table->boolean( 'track_inventory' )->default( true );
			$table->integer( 'position' )->default( 0 );
			$table->string( 'image_url', 2048 )->nullable();

			// The chosen option values: {OptionName: "value"}.
			$table->json( 'options' )->nullable();

			// The variant's identity in the source system, when it had one.
			$table->string( 'external_ref', 191 )->nullable()->unique();

			$table->timestamps();

			$table->index( array( 'product_id', 'position' ) );
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_product_variants' );
	}
};
