<?php
/**
 * Create the order lines table behind OrderStoreInterface.
 *
 * Proposal 164 section 164.174. One row per line item, snapshotting the price the
 * customer was charged rather than pointing at a current catalogue price: the
 * catalogue moves on, and an invoice for last month's order has to keep saying
 * what last month's order cost.
 *
 * `line_total_minor` is stored rather than derived from `quantity * unit_price`
 * because real orders carry line-level discounts and tax. Deriving it would
 * quietly discard an adjustment the store applied.
 *
 * `position` keeps the order the lines were listed in, which is the order a
 * printed invoice shows.
 *
 * @package Nvoos\Laravel
 * @since   1.6.0
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

	public function up(): void {
		Schema::create( 'nvoos_order_lines', function ( Blueprint $table ) {
			$table->charset   = 'utf8mb4';
			$table->collation = 'utf8mb4_unicode_ci';

			$table->id();
			$table->foreignId( 'order_id' )
				->constrained( 'nvoos_orders' )
				->cascadeOnDelete();

			$table->string( 'name', 191 );
			$table->string( 'sku', 191 )->nullable();
			$table->integer( 'quantity' );

			$table->bigInteger( 'unit_price_minor' );
			$table->char( 'unit_price_currency', 3 );

			$table->bigInteger( 'line_total_minor' );
			$table->char( 'line_total_currency', 3 );

			$table->unsignedBigInteger( 'product_id' )->nullable();
			$table->unsignedBigInteger( 'variant_id' )->nullable();
			$table->integer( 'position' )->default( 0 );

			$table->timestamps();

			$table->index( array( 'order_id', 'position' ) );
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_order_lines' );
	}
};
