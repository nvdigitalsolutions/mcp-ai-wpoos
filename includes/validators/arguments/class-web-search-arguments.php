<?php
/**
 * Web Search Tool Arguments Validation
 *
 * Validation class for web_search tool arguments using Symfony Validator.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class WebSearchArguments
 *
 * Defines validation rules for web_search tool arguments.
 */
class WebSearchArguments {

	/**
	 * The search query to look up.
	 *
	 * @var string
	 */
	#[Assert\NotBlank( message: 'Search query is required.' )]
	#[Assert\Type( type: 'string', message: 'Query must be a string.' )]
	#[Assert\Length(
		max: 500,
		maxMessage: 'Query cannot exceed {{ limit }} characters.'
	)]
	public $query;

	/**
	 * Maximum number of results to return (1-10).
	 *
	 * @var int|null
	 */
	#[Assert\Type( type: 'integer', message: 'Max results must be an integer.' )]
	#[Assert\Range(
		min: 1,
		max: 10,
		notInRangeMessage: 'Max results must be between {{ min }} and {{ max }}.'
	)]
	public $max_results = null;
}
