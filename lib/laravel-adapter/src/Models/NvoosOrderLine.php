<?php
/**
 * Eloquent model for one line of an order.
 *
 * Used by {@see \Nvoos\Laravel\Adapter\OrderStore}. Every amount is the price
 * charged at checkout, and `line_total_minor` is stored rather than derived, so a
 * line-level discount survives the round trip.
 *
 * @package Nvoos\Laravel
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

class NvoosOrderLine extends Model {

	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table = 'nvoos_order_lines';

	/**
	 * Mass-assignable attributes.
	 *
	 * @var array<int, string>
	 */
	protected $fillable = array(
		'order_id',
		'name',
		'sku',
		'quantity',
		'unit_price_minor',
		'unit_price_currency',
		'line_total_minor',
		'line_total_currency',
		'product_id',
		'variant_id',
		'position',
	);
}
