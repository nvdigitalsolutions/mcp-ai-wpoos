<?php
/**
 * Validation arguments for Search Content tool.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation class for search_content tool arguments.
 *
 * Defines validation rules for searching WordPress content.
 */
class SearchContentArguments {

	/**
	 * Search term (keyword or phrase).
	 *
	 * @var string
	 */
	#[Assert\Type( type: 'string' )]
	public $search_term = '';

	/**
	 * Post type to search.
	 *
	 * @var string
	 */
	#[Assert\Type( type: 'string' )]
	public $post_type = 'any';

	/**
	 * Maximum number of results.
	 *
	 * @var int
	 */
	#[Assert\Type( type: 'int' )]
	#[Assert\Range(
		min: 1,
		max: 50,
		notInRangeMessage: 'Limit must be between {{ min }} and {{ max }}.'
	)]
	public $limit = 10;

	/**
	 * Taxonomy filters.
	 *
	 * @var array
	 */
	#[Assert\Type( type: 'array' )]
	#[Assert\All(
		array(
			new Assert\Collection(
				fields: array(
					'taxonomy' => array(
						new Assert\NotBlank( message: 'Taxonomy name is required.' ),
						new Assert\Type( 'string' ),
					),
					'terms'    => array(
						new Assert\NotBlank( message: 'Terms array is required.' ),
						new Assert\Type( 'array' ),
						new Assert\Count( min: 1, minMessage: 'At least one term is required.' ),
					),
					'operator' => array(
						new Assert\Optional(
							array(
								new Assert\Choice(
									choices: array( 'IN', 'NOT IN', 'AND', 'EXISTS', 'NOT EXISTS' ),
									message: 'Invalid operator. Must be one of: {{ choices }}.'
								),
							)
						),
					),
					'field'    => array(
						new Assert\Optional(
							array(
								new Assert\Choice(
									choices: array( 'slug', 'name', 'term_id', 'term_taxonomy_id' ),
									message: 'Invalid field. Must be one of: {{ choices }}.'
								),
							)
						),
					),
				),
				allowExtraFields: false
			),
		)
	)]
	public $taxonomy_filters = array();

	/**
	 * Taxonomy relation (AND/OR).
	 *
	 * @var string
	 */
	#[Assert\Choice(
		choices: array( 'AND', 'OR' ),
		message: 'Taxonomy relation must be either AND or OR.'
	)]
	public $taxonomy_relation = 'AND';

	/**
	 * Meta filters.
	 *
	 * @var array
	 */
	#[Assert\Type( type: 'array' )]
	#[Assert\All(
		array(
			new Assert\Collection(
				fields: array(
					'key'     => array(
						new Assert\NotBlank( message: 'Meta key is required.' ),
						new Assert\Type( 'string' ),
					),
					'value'   => array(
						new Assert\NotBlank( message: 'Meta value is required.' ),
					),
					'compare' => array(
						new Assert\Optional(
							array(
								new Assert\Choice(
									choices: array( '=', '!=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'EXISTS', 'NOT EXISTS' ),
									message: 'Invalid comparison operator.'
								),
							)
						),
					),
					'type'    => array(
						new Assert\Optional(
							array(
								new Assert\Choice(
									choices: array( 'NUMERIC', 'BINARY', 'CHAR', 'DATE', 'DATETIME', 'DECIMAL', 'SIGNED', 'TIME', 'UNSIGNED' ),
									message: 'Invalid meta type.'
								),
							)
						),
					),
				),
				allowExtraFields: false
			),
		)
	)]
	public $meta_filters = array();

	/**
	 * Meta relation (AND/OR).
	 *
	 * @var string
	 */
	#[Assert\Choice(
		choices: array( 'AND', 'OR' ),
		message: 'Meta relation must be either AND or OR.'
	)]
	public $meta_relation = 'AND';
}
