<?php
/**
 * Validation arguments for Create WooCommerce Product tool.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation class for create_woo_product tool arguments.
 *
 * Defines validation rules for creating WooCommerce product drafts.
 */
class CreateWooProductArguments {

	/**
	 * Reference identifier for the product (used as SKU).
	 *
	 * @var string
	 */
	#[Assert\Type( type: 'string' )]
	#[Assert\NotBlank( message: 'Product reference is required.' )]
	#[Assert\Length(
		min: 1,
		minMessage: 'Product reference cannot be empty.'
	)]
	public $reference = '';

	/**
	 * Product type to create.
	 *
	 * @var string
	 */
	#[Assert\Type( type: 'string' )]
	#[Assert\Choice(
		choices: array( 'simple', 'variable' ),
		message: 'Product type must be either "simple" or "variable".'
	)]
	public $product_type = 'simple';

	/**
	 * Brand name associated with the product.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string' )]
	public $brand = null;

	/**
	 * Product title.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string' )]
	public $title = null;

	/**
	 * Local price for the product.
	 *
	 * @var string|float|null
	 */
	public $local_price = null;

	/**
	 * Full product description.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string' )]
	public $description = null;

	/**
	 * Secondary description or marketing copy.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string' )]
	public $description_secondary = null;

	/**
	 * URL for the brand page to inspect for imagery.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string' )]
	#[Assert\Url( message: 'Brand page URL must be a valid URL.' )]
	public $brand_page_url = null;

	/**
	 * Product or lifestyle image URLs to sideload.
	 *
	 * @var array|null
	 */
	#[Assert\Type( type: 'array' )]
	#[Assert\Count(
		min: 2,
		max: 10,
		minMessage: 'At least {{ limit }} image URLs are required.',
		maxMessage: 'Cannot provide more than {{ limit }} image URLs.'
	)]
	#[Assert\All(
		array(
			new Assert\Type( type: 'string' ),
			new Assert\Url( message: 'Each image URL must be a valid URL.' ),
		)
	)]
	public $image_urls = null;
}
