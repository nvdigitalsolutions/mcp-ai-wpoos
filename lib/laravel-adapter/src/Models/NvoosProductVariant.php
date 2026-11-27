<?php
/**
 * Eloquent model for a product variant.
 *
 * Money is two columns — an integer in the currency's minor unit and the ISO
 * 4217 code — so a row is exact and self-describing. `options` is the chosen
 * value map (`{"Size": "M"}`), which is empty for a variant with no options.
 *
 * @package Nvoos\Laravel
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NvoosProductVariant extends Model {

	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table = 'nvoos_product_variants';

	/**
	 * Mass-assignable attributes.
	 *
	 * @var array<int, string>
	 */
	protected $fillable = array(
		'product_id',
		'sku',
		'price_minor',
		'price_currency',
		'compare_at_price_minor',
		'compare_at_price_currency',
		'cost_minor',
		'cost_currency',
		'barcode',
		'stock',
		'track_inventory',
		'position',
		'image_url',
		'options',
		'external_ref',
	);

	/**
	 * Attribute casting.
	 *
	 * @var array<string, string>
	 */
	protected $casts = array(
		'price_minor'                  => 'integer',
		'compare_at_price_minor'       => 'integer',
		'cost_minor'                   => 'integer',
		'stock'                        => 'integer',
		'track_inventory'              => 'boolean',
		'position'                     => 'integer',
		'options'                      => 'array',
		'created_at'                   => 'datetime',
		'updated_at'                   => 'datetime',
	);

	/**
	 * The owning product.
	 *
	 * @return BelongsTo
	 */
	public function product(): BelongsTo {
		return $this->belongsTo( NvoosProduct::class, 'product_id' );
	}
}
