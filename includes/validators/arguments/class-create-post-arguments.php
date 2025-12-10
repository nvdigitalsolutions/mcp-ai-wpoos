<?php
/**
 * Create Post Tool Arguments Validation
 *
 * Validation class for create_post tool arguments using Symfony Validator.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;
use WP_MCP_AI\Validators\Constraints\WPCapability;

/**
 * Class CreatePostArguments
 *
 * Defines validation rules for create_post tool arguments.
 */
class CreatePostArguments {

	/**
	 * Post title.
	 *
	 * @var string
	 */
	#[Assert\NotBlank( message: 'Post title is required.' )]
	#[Assert\Length(
		min: 1,
		max: 200,
		minMessage: 'Post title must be at least {{ limit }} characters long.',
		maxMessage: 'Post title cannot be longer than {{ limit }} characters.'
	)]
	public $title = '';

	/**
	 * Post content.
	 *
	 * @var string
	 */
	#[Assert\NotBlank( message: 'Post content is required.' )]
	public $content = '';

	/**
	 * Post status.
	 *
	 * @var string
	 */
	#[Assert\Choice(
		choices: array( 'publish', 'draft', 'pending', 'private' ),
		message: 'Post status must be one of: {{ choices }}.'
	)]
	public $status = 'draft';

	/**
	 * Post type.
	 *
	 * @var string
	 */
	#[Assert\NotBlank( message: 'Post type is required.' )]
	#[Assert\Regex(
		pattern: '/^[a-z_]+$/',
		message: 'Post type must contain only lowercase letters and underscores.'
	)]
	public $post_type = 'post';

	/**
	 * User ID (author).
	 *
	 * @var int|null
	 */
	#[Assert\Type( type: 'integer', message: 'User ID must be an integer.' )]
	#[Assert\Positive( message: 'User ID must be a positive integer.' )]
	#[WPCapability( capability: 'edit_posts' )]
	public $user_id = null;
}
