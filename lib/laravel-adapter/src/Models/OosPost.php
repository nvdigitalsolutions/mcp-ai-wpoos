<?php
/**
 * Default Eloquent model for oOS content items.
 *
 * Used by ContentStore when no custom model is configured via
 * the 'oos.content_model' config key. Apps with their own content
 * models should configure that key to point to their model class.
 *
 * @package Oos\Laravel
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

class OosPost extends Model {

	/**
	 * The table associated with the model.
	 */
	protected $table = 'oos_posts';

	/**
	 * Mass-assignable attributes.
	 *
	 * @var array<int, string>
	 */
	protected $fillable = array(
		'title',
		'content',
		'status',
		'type',
		'author_id',
		'excerpt',
		'slug',
		'meta',
	);

	/**
	 * Attribute casting.
	 *
	 * @var array<string, string>
	 */
	protected $casts = array(
		'meta'       => 'array',
		'author_id'  => 'integer',
		'created_at' => 'datetime',
		'updated_at' => 'datetime',
	);

	/**
	 * Many-to-many relationship with terms (taxonomies).
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function terms(): \Illuminate\Database\Eloquent\Relations\BelongsToMany {
		return $this->belongsToMany(
			OosTerm::class,
			'oos_post_term',
			'post_id',
			'term_id',
		);
	}
}
