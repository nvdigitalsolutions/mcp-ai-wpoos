<?php
/**
 * Tool for creating WooCommerce products (Validated version).
 *
 * This is the Symfony Validator version of the create_woo_product tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../validators/class-wp-mcp-ai-validated-tool.php';
require_once __DIR__ . '/../validators/arguments/class-create-woo-product-arguments.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-create-woo-product.php';

/**
 * Creates WooCommerce products using Symfony Validator.
 *
 * This class extends the original create_woo_product tool to use
 * Symfony Validator for argument validation.
 */
class WP_MCP_AI_Tool_Create_Woo_Product_Validated extends WP_MCP_AI_Validated_Tool implements WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * The original create_woo_product tool instance for delegation.
	 *
	 * @var WP_MCP_AI_Tool_Create_Woo_Product
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->original_tool = new WP_MCP_AI_Tool_Create_Woo_Product();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_woo_product_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create WooCommerce Product Draft (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a WooCommerce product draft using merchandising data with Symfony Validator for argument validation.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return $this->original_tool->get_parameters_schema();
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_validation_class() {
		return \WP_MCP_AI\Tools\Arguments\CreateWooProductArguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param \WP_MCP_AI\Tools\Arguments\CreateWooProductArguments $validated_args Validated arguments object.
	 * @param array                                                 $context        Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_validated( $validated_args, $context ) {
		// Convert validated args object to array format expected by original tool.
		$arguments = array(
			'reference'    => $validated_args->reference,
			'product_type' => $validated_args->product_type,
		);

		if ( null !== $validated_args->brand ) {
			$arguments['brand'] = $validated_args->brand;
		}

		if ( null !== $validated_args->title ) {
			$arguments['title'] = $validated_args->title;
		}

		if ( null !== $validated_args->local_price ) {
			$arguments['local_price'] = $validated_args->local_price;
		}

		if ( null !== $validated_args->description ) {
			$arguments['description'] = $validated_args->description;
		}

		if ( null !== $validated_args->description_secondary ) {
			$arguments['description_secondary'] = $validated_args->description_secondary;
		}

		if ( null !== $validated_args->brand_page_url ) {
			$arguments['brand_page_url'] = $validated_args->brand_page_url;
		}

		if ( null !== $validated_args->image_urls ) {
			$arguments['image_urls'] = $validated_args->image_urls;
		}

		// Delegate to the original tool.
		return $this->original_tool->execute( $arguments, $context );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return $this->original_tool->get_capability_flags();
	}
}
