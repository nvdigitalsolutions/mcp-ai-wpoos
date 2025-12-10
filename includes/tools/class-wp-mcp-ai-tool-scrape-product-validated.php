<?php
/**
 * Tool for scraping product information (Validated version).
 *
 * This is the Symfony Validator version of the scrape_product tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../validators/class-wp-mcp-ai-validated-tool.php';
require_once __DIR__ . '/../validators/arguments/class-scrape-product-arguments.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-scrape-product.php';

/**
 * Scrapes product information with Symfony Validator.
 *
 * This class extends the original scrape_product tool to use
 * Symfony Validator for argument validation.
 */
class WP_MCP_AI_Tool_Scrape_Product_Validated extends WP_MCP_AI_Validated_Tool implements WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * The original scrape_product tool instance for delegation.
	 *
	 * @var WP_MCP_AI_Tool_Scrape_Product
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->original_tool = new WP_MCP_AI_Tool_Scrape_Product();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'scrape_product_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Scrape Product (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Scrapes product information (title, subtitle, description, images, price, availability) from a product URL or saved HTML file with Symfony Validator for argument validation.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		// Use the same schema as the original tool.
		return $this->original_tool->get_parameters_schema();
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_validation_class() {
		return \WP_MCP_AI\Tools\Arguments\ScrapeProductArguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param \WP_MCP_AI\Tools\Arguments\ScrapeProductArguments $validated_args Validated arguments object.
	 * @param array                                             $context        Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_validated( $validated_args, $context ) {
		// Convert validated arguments object back to array format.
		$arguments = array();

		// Add optional arguments if provided.
		if ( null !== $validated_args->url ) {
			$arguments['url'] = $validated_args->url;
		}

		if ( null !== $validated_args->html_file ) {
			$arguments['html_file'] = $validated_args->html_file;
		}

		if ( null !== $validated_args->title_selector ) {
			$arguments['title_selector'] = $validated_args->title_selector;
		}

		if ( null !== $validated_args->subtitle_selector ) {
			$arguments['subtitle_selector'] = $validated_args->subtitle_selector;
		}

		if ( null !== $validated_args->description_selector ) {
			$arguments['description_selector'] = $validated_args->description_selector;
		}

		if ( null !== $validated_args->images_selector ) {
			$arguments['images_selector'] = $validated_args->images_selector;
		}

		if ( null !== $validated_args->download_images ) {
			$arguments['download_images'] = $validated_args->download_images;
		}

		if ( null !== $validated_args->price_selector ) {
			$arguments['price_selector'] = $validated_args->price_selector;
		}

		if ( null !== $validated_args->extract_structured_data ) {
			$arguments['extract_structured_data'] = $validated_args->extract_structured_data;
		}

		// Delegate to the original tool's execute method.
		return $this->original_tool->execute( $arguments, $context );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		// Delegate to the original tool.
		return $this->original_tool->get_capability_flags();
	}
}
