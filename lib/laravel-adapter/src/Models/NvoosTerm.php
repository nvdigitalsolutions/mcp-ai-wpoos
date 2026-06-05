<?php
/**
 * Term model for oOS taxonomy support.
 *
 * Terms belong to a taxonomy ('category', 'tag', or custom) and can be
 * attached to content items via the nvoos_post_term pivot table.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

class NvoosTerm extends Model {

	/**
	 * The table associated with the model.
	 */
	protected $table = 'nvoos_terms';

	/**
	 * Mass-assignable attributes.
	 *
	 * @var array<int, string>
	 */
	protected $fillable = array(
		'name',
		'taxonomy',
		'slug',
		'description',
	);

	/**
	 * Many-to-many relationship with posts.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function posts(): \Illuminate\Database\Eloquent\Relations\BelongsToMany {
		return $this->belongsToMany(
			NvoosPost::class,
			'nvoos_post_term',
			'term_id',
			'post_id',
		);
	}
}
