<?php
/**
 * Create the orders table behind OrderStoreInterface.
 *
 * Proposal 164 section 164.174. This is the table the deferred ecommerce tools
 * were waiting for: an order with a total, a status, a customer and — the part
 * `get_uninvoiced_orders` actually needs — an invoice marker.
 *
 * Money is an integer in the currency's minor unit plus its ISO code, never a
 * float and never a decimal string. That is the same decision the product tables
 * carry (section 164.114) and the core's `Money` makes: `total_minor = 2499` is
 * exact, and the currency travels with it so a row cannot be read as the wrong
 * unit.
 *
 * `invoiced_at` is indexed because "orders with no invoice" is the query this
 * table exists to answer, and a nullable indexed column answers it with one
 * range scan instead of the meta-table join WooCommerce needs.
 *
 * `number` is unique: it is the customer-facing identity, and two orders sharing
 * one would make every invoice reference ambiguous.
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
		Schema::create( 'nvoos_orders', function ( Blueprint $table ) {
			$table->charset   = 'utf8mb4';
			$table->collation = 'utf8mb4_unicode_ci';

			$table->id();
			$table->string( 'number', 191 )->unique();
			$table->string( 'status', 20 )->default( 'pending' );

			// Every amount is a checkout-time snapshot, not a derived value.
			$table->bigInteger( 'total_minor' );
			$table->char( 'total_currency', 3 );

			$table->bigInteger( 'subtotal_minor' )->nullable();
			$table->char( 'subtotal_currency', 3 )->nullable();

			$table->bigInteger( 'discount_total_minor' )->nullable();
			$table->char( 'discount_total_currency', 3 )->nullable();

			$table->bigInteger( 'shipping_total_minor' )->nullable();
			$table->char( 'shipping_total_currency', 3 )->nullable();

			$table->bigInteger( 'tax_total_minor' )->nullable();
			$table->char( 'tax_total_currency', 3 )->nullable();

			$table->unsignedBigInteger( 'customer_id' )->nullable()->index();
			$table->string( 'billing_name', 191 )->nullable();
			$table->string( 'billing_email', 191 )->nullable();

			// The invoice marker. Null means no invoice has been raised.
			$table->timestamp( 'invoiced_at' )->nullable()->index();

			$table->string( 'external_ref', 191 )->nullable()->unique();

			$table->timestamps();

			$table->index( array( 'created_at', 'id' ) );
		} );
	}

	public function down(): void {
		Schema::dropIfExists( 'nvoos_orders' );
	}
};
