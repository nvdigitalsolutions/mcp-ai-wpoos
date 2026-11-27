<?php
/**
 * Eloquent model for a catalog product.
 *
 * Used by {@see \Nvoos\Laravel\Adapter\ProductCatalog}. The option definitions
 * live in `options` as JSON: they are product-scoped, never queried across
 * products, and a normalised option table would add two joins for a value that
 * only ever travels with its product.
 *
 * @package Nvoos\Laravel
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NvoosProduct extends Model {

	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table = 'nvoos_products';

	/**
	 * Mass-assignable attributes.
	 *
	 * @var array<int, string>
	 */
	protected $fillable = array(
		'name',
		'slug',
		'description',
		'status',
		'vendor',
		'product_type',
		'tags',
		'options',
		'external_ref',
	);

	/**
	 * Attribute casting.
	 *
	 * @var array<string, string>
	 */
	protected $casts = array(
		'tags'       => 'array',
		'options'    => 'array',
		'created_at' => 'datetime',
		'updated_at' => 'datetime',
	);

	/**
	 * The product's variants, in display order.
	 *
	 * @return HasMany
	 */
	public function variants(): HasMany {
		return $this->hasMany( NvoosProductVariant::class, 'product_id' )
			->orderBy( 'position' )
			->orderBy( 'id' );
	}
}
