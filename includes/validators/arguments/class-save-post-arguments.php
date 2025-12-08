<?php
/**
 * Save Post Tool Arguments Validation
 *
 * Validation class for save_post tool arguments using Symfony Validator.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;
use WP_MCP_AI\Validators\Constraints\WPPostExists;

/**
 * Class SavePostArguments
 *
 * Defines validation rules for save_post tool arguments.
 */
class SavePostArguments {

	/**
	 * Post ID (for updates).
	 *
	 * @var int|null
	 */
	#[Assert\Type(type: 'integer', message: 'Post ID must be an integer.')]
	#[Assert\Positive(message: 'Post ID must be a positive integer.')]
	#[WPPostExists]
	public $post_id = null;

	/**
	 * Post type.
	 *
	 * @var string
	 */
	#[Assert\NotBlank(message: 'Post type cannot be empty.')]
	#[Assert\Regex(
		pattern: '/^[a-z0-9_-]+$/',
		message: 'Post type must contain only lowercase letters, numbers, hyphens, and underscores.'
	)]
	public $post_type = 'post';

	/**
	 * Post title.
	 *
	 * @var string|null
	 */
	#[Assert\Length(
		max: 200,
		maxMessage: 'Post title cannot be longer than {{ limit }} characters.'
	)]
	public $title = null;

	/**
	 * Post content.
	 *
	 * @var string
	 */
	#[Assert\NotBlank(message: 'Post content is required.')]
	public $content = '';

	/**
	 * Post status.
	 *
	 * @var string
	 */
	#[Assert\Choice(
		choices: ['publish', 'draft', 'pending', 'private', 'future', 'trash'],
		message: 'Post status must be one of: {{ choices }}.'
	)]
	public $status = 'draft';

	/**
	 * Post excerpt.
	 *
	 * @var string|null
	 */
	public $excerpt = null;

	/**
	 * Post slug.
	 *
	 * @var string|null
	 */
	#[Assert\Regex(
		pattern: '/^[a-z0-9-]+$/',
		message: 'Slug must contain only lowercase letters, numbers, and hyphens.'
	)]
	public $slug = null;
}
