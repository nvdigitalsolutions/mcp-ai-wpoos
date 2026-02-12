<?php
/**
 * Product Research Tool
 *
 * Provides AI-powered product research capabilities for WooCommerce products.
 * Helps gather and structure product information before creating products.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for researching product information.
 *
 * This tool assists in gathering structured product data including:
 * - Product name, brand, and reference/SKU
 * - Pricing information
 * - Descriptions and specifications
 * - Images and media
 * - Categories and tags
 * - Attributes and variations
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Research_Product implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if WooCommerce is active.
	 */
	public static function is_available() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.0.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		return __( 'Product research tool requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'research_product';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Research Product', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Research and gather structured information about a product before creating it in WooCommerce. Returns product data that can be used with the Create WooCommerce Product Draft tool.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'query'               => array(
					'type'        => 'string',
					'description' => __( 'The product to research (e.g., "Nike Air Max 270", "Apple MacBook Pro 16-inch M3").', 'mcp-ai-wpoos-pro' ),
				),
				'include_pricing'     => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include pricing information in the research.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_images'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include image URLs in the research.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_specs'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include product specifications and attributes.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'suggested_reference' => array(
					'type'        => 'string',
					'description' => __( 'Optional SKU or reference identifier to use for the product.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'query' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',              // Pro tier tool.
			'read-only',        // Research only, doesn't modify data.
			'requires-plugin',  // Requires WooCommerce.
			'local-only',       // No external API calls.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return mixed|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				__( 'WooCommerce is not installed or activated.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate query parameter.
		if ( empty( $arguments['query'] ) ) {
			return new WP_Error(
				'missing_query',
				__( 'Product query is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$query = sanitize_text_field( $arguments['query'] );

		// Build research context.
		$include_pricing = isset( $arguments['include_pricing'] ) ? (bool) $arguments['include_pricing'] : true;
		$include_images  = isset( $arguments['include_images'] ) ? (bool) $arguments['include_images'] : true;
		$include_specs   = isset( $arguments['include_specs'] ) ? (bool) $arguments['include_specs'] : true;

		// Generate suggested reference if not provided.
		$reference = isset( $arguments['suggested_reference'] ) && ! empty( $arguments['suggested_reference'] )
			? sanitize_text_field( $arguments['suggested_reference'] )
			: $this->generate_reference( $query );

		// Build research guidance.
		$guidance = $this->build_research_guidance( $query, $include_pricing, $include_images, $include_specs );

		// Return research structure and guidance.
		return array(
			'status'         => 'complete',
			'query'          => $query,
			'reference'      => $reference,
			'guidance'       => $guidance,
			'structure'      => $this->get_product_structure(),
			'next_steps'     => array(
				__( 'Use available tools like web_search to gather the product information listed in the guidance.', 'mcp-ai-wpoos-pro' ),
				__( 'Structure the gathered information according to the provided schema.', 'mcp-ai-wpoos-pro' ),
				__( 'Once you have complete product data, use the "Create WooCommerce Product Draft" tool.', 'mcp-ai-wpoos-pro' ),
			),
			'create_tool'    => 'create_woo_product',
			'tool_arguments' => array(
				'reference' => $reference,
				'title'     => sprintf( __( 'Replace with actual product name for: %s', 'mcp-ai-wpoos-pro' ), $query ),
				'brand'     => __( 'Replace with actual brand name', 'mcp-ai-wpoos-pro' ),
				// Additional fields to be filled based on research.
			),
		);
	}

	/**
	 * Generate a reference/SKU from the query.
	 *
	 * @param string $query Product query.
	 * @return string Generated reference.
	 */
	protected function generate_reference( $query ) {
		// Create a simple reference from the query.
		$reference = strtoupper( preg_replace( '/[^a-zA-Z0-9]/', '-', $query ) );
		$reference = preg_replace( '/-+/', '-', $reference ); // Remove multiple dashes.
		$reference = trim( $reference, '-' );

		// Limit length.
		if ( strlen( $reference ) > 50 ) {
			$reference = substr( $reference, 0, 50 );
		}

		return $reference;
	}

	/**
	 * Build research guidance for the AI.
	 *
	 * @param string $query          Product query.
	 * @param bool   $include_pricing Include pricing guidance.
	 * @param bool   $include_images  Include image guidance.
	 * @param bool   $include_specs   Include specifications guidance.
	 * @return string Research guidance.
	 */
	protected function build_research_guidance( $query, $include_pricing, $include_images, $include_specs ) {
		$guidance = sprintf(
			__( 'RESEARCH REQUIREMENTS: You need to gather information about: %s', 'mcp-ai-wpoos-pro' ),
			$query
		) . "\n\n";

		$guidance .= __( 'ACTION: Use the web_search tool to find the following information:', 'mcp-ai-wpoos-pro' ) . "\n";
		$guidance .= __( '1. Product name and brand', 'mcp-ai-wpoos-pro' ) . "\n";
		$guidance .= __( '2. Full product description (features, benefits, use cases)', 'mcp-ai-wpoos-pro' ) . "\n";
		$guidance .= __( '3. Short description for product summary', 'mcp-ai-wpoos-pro' ) . "\n";

		if ( $include_pricing ) {
			$guidance .= __( '4. Regular price in local currency', 'mcp-ai-wpoos-pro' ) . "\n";
			$guidance .= __( '5. Sale price if available', 'mcp-ai-wpoos-pro' ) . "\n";
		}

		if ( $include_images ) {
			$guidance .= __( '6. Product image URLs (at least 2-3 high-quality images)', 'mcp-ai-wpoos-pro' ) . "\n";
		}

		if ( $include_specs ) {
			$guidance .= __( '7. Product specifications and attributes (size, color, material, etc.)', 'mcp-ai-wpoos-pro' ) . "\n";
			$guidance .= __( '8. Technical specifications if applicable', 'mcp-ai-wpoos-pro' ) . "\n";
		}

		$guidance .= __( '9. Suggested product categories and tags', 'mcp-ai-wpoos-pro' ) . "\n";
		$guidance .= __( '10. Product type (simple, variable, grouped, external)', 'mcp-ai-wpoos-pro' ) . "\n\n";
		
		$guidance .= __( 'IMPORTANT: Start researching NOW by calling the web_search tool with relevant queries to gather this information.', 'mcp-ai-wpoos-pro' );

		return $guidance;
	}

	/**
	 * Get the expected product data structure.
	 *
	 * @return array Product structure schema.
	 */
	protected function get_product_structure() {
		return array(
			'reference'             => __( 'Product SKU/reference identifier (required)', 'mcp-ai-wpoos-pro' ),
			'title'                 => __( 'Product name (required)', 'mcp-ai-wpoos-pro' ),
			'brand'                 => __( 'Brand name', 'mcp-ai-wpoos-pro' ),
			'product_type'          => __( 'Product type: simple or variable (default: simple)', 'mcp-ai-wpoos-pro' ),
			'description'           => __( 'Full product description with HTML formatting', 'mcp-ai-wpoos-pro' ),
			'description_secondary' => __( 'Short description or excerpt', 'mcp-ai-wpoos-pro' ),
			'local_price'           => __( 'Regular price (number or string)', 'mcp-ai-wpoos-pro' ),
			'sale_price'            => __( 'Sale price if on sale (number or string)', 'mcp-ai-wpoos-pro' ),
			'image_urls'            => __( 'Array of product image URLs (2-10 images)', 'mcp-ai-wpoos-pro' ),
			'categories'            => __( 'Array of category names or IDs', 'mcp-ai-wpoos-pro' ),
			'tags'                  => __( 'Array of tag names or IDs', 'mcp-ai-wpoos-pro' ),
			'attributes'            => __( 'Array of product attributes with name and options', 'mcp-ai-wpoos-pro' ),
			'stock_status'          => __( 'Stock status: instock, outofstock, or onbackorder', 'mcp-ai-wpoos-pro' ),
			'manage_stock'          => __( 'Enable stock management (boolean)', 'mcp-ai-wpoos-pro' ),
			'stock_quantity'        => __( 'Stock quantity if manage_stock is true', 'mcp-ai-wpoos-pro' ),
			'weight'                => __( 'Product weight for shipping', 'mcp-ai-wpoos-pro' ),
			'dimensions'            => __( 'Product dimensions (length, width, height)', 'mcp-ai-wpoos-pro' ),
			'virtual'               => __( 'Is virtual product (boolean)', 'mcp-ai-wpoos-pro' ),
			'downloadable'          => __( 'Is downloadable product (boolean)', 'mcp-ai-wpoos-pro' ),
		);
	}
}
