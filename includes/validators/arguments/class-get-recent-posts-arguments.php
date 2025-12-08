<?php
/**
 * Validation arguments for Get Recent Posts tool.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation class for get_recent_posts tool arguments.
 *
 * Defines validation rules for querying recent posts.
 */
class GetRecentPostsArguments {

	/**
	 * Maximum number of posts to return.
	 *
	 * @var int
	 */
	#[Assert\Type(type: 'int')]
	#[Assert\Range(
		min: 1,
		max: 50,
		notInRangeMessage: 'Limit must be between {{ min }} and {{ max }}.'
	)]
	public $limit = 5;

	/**
	 * The post type to query.
	 *
	 * @var string
	 */
	#[Assert\Type(type: 'string')]
	#[Assert\NotBlank(message: 'Post type is required.')]
	public $post_type = 'post';
}
