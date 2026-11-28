<?php
/**
 * Eloquent model for an order.
 *
 * Used by {@see \Nvoos\Laravel\Adapter\OrderStore}. The line items are a relation
 * rather than JSON because they are queried, counted and printed as a list, and
 * because a line carries its own identity that an invoice may reference.
 *
 * `invoiced_at` is cast to a datetime so the marker round-trips as a moment, not
 * a string: the core model answers "is it invoiced" from its nullness and "when"
 * from its value.
 *
 * @package Nvoos\Laravel
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NvoosOrder extends Model {

	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table = 'nvoos_orders';

	/**
	 * Mass-assignable attributes.
	 *
	 * `created_at` is not fillable on purpose: the adapter assigns it explicitly
	 * so an imported historical order keeps its original date.
	 *
	 * @var array<int, string>
	 */
	protected $fillable = array(
		'number',
		'status',
		'total_minor',
		'total_currency',
		'subtotal_minor',
		'subtotal_currency',
		'discount_total_minor',
		'discount_total_currency',
		'shipping_total_minor',
		'shipping_total_currency',
		'tax_total_minor',
		'tax_total_currency',
		'customer_id',
		'billing_name',
		'billing_email',
		'invoiced_at',
		'external_ref',
	);

	/**
	 * Attribute casting.
	 *
	 * @var array<string, string>
	 */
	protected $casts = array(
		'invoiced_at' => 'datetime',
		'created_at'  => 'datetime',
		'updated_at'  => 'datetime',
	);

	/**
	 * The order's line items, in the order they were listed.
	 *
	 * @return HasMany
	 */
	public function lines(): HasMany {
		return $this->hasMany( NvoosOrderLine::class, 'order_id' )
			->orderBy( 'position' )
			->orderBy( 'id' );
	}
}
