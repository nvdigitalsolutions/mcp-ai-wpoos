<?php
/**
 * Scrape Product Tool Arguments Validation
 *
 * Validation class for scrape_product tool arguments using Symfony Validator.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class ScrapeProductArguments
 *
 * Defines validation rules for scrape_product tool arguments.
 */
class ScrapeProductArguments {

	/**
	 * The product page URL to scrape.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'URL must be a string.' )]
	#[Assert\Url( message: 'URL must be a valid URL.' )]
	public $url = null;

	/**
	 * Path to a saved HTML file to parse.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'HTML file must be a string.' )]
	#[Assert\Length(
		max: 500,
		maxMessage: 'HTML file path cannot exceed {{ limit }} characters.'
	)]
	public $html_file = null;

	/**
	 * CSS selector for product title.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Title selector must be a string.' )]
	#[Assert\Length(
		max: 500,
		maxMessage: 'Title selector cannot exceed {{ limit }} characters.'
	)]
	public $title_selector = null;

	/**
	 * CSS selector for product subtitle.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Subtitle selector must be a string.' )]
	#[Assert\Length(
		max: 500,
		maxMessage: 'Subtitle selector cannot exceed {{ limit }} characters.'
	)]
	public $subtitle_selector = null;

	/**
	 * CSS selector for product description.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Description selector must be a string.' )]
	#[Assert\Length(
		max: 500,
		maxMessage: 'Description selector cannot exceed {{ limit }} characters.'
	)]
	public $description_selector = null;

	/**
	 * CSS selector or pattern for product images containers.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Images selector must be a string.' )]
	#[Assert\Length(
		max: 500,
		maxMessage: 'Images selector cannot exceed {{ limit }} characters.'
	)]
	public $images_selector = null;

	/**
	 * Whether to download images to WordPress media library.
	 *
	 * @var bool|null
	 */
	#[Assert\Type( type: 'bool', message: 'Download images must be a boolean.' )]
	public $download_images = null;

	/**
	 * CSS selector for product price.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Price selector must be a string.' )]
	#[Assert\Length(
		max: 500,
		maxMessage: 'Price selector cannot exceed {{ limit }} characters.'
	)]
	public $price_selector = null;

	/**
	 * Whether to extract Schema.org JSON-LD structured data.
	 *
	 * @var bool|null
	 */
	#[Assert\Type( type: 'bool', message: 'Extract structured data must be a boolean.' )]
	public $extract_structured_data = null;
}
