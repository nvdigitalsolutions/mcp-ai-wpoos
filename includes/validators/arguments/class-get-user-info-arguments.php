<?php
/**
 * Validation arguments for get_user_info tool.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Arguments class for get_user_info tool.
 *
 * Validates user ID parameter for retrieving user information.
 *
 * @since 1.0.0
 */
class GetUserInfoArguments {

	/**
	 * User ID to retrieve information for.
	 *
	 * Optional - defaults to current user if not specified.
	 *
	 * @var int
	 */
	#[Assert\Type(
		type: 'int',
		message: 'User ID must be an integer.'
	)]
	#[Assert\Positive(
		message: 'User ID must be a positive integer.'
	)]
	public $user_id = 0;
}
