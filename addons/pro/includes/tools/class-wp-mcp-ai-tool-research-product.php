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
			'pro',                   // Pro tier tool.
			'read-only',             // Research only, doesn't modify data.
			'requires-plugin',       // Requires WooCommerce.
			'requires-credentials',  // Needs AI API keys.
			'consumes-tokens',       // Uses AI API tokens.
			'external-api',          // Makes external API calls.
			'network-dependent',     // Requires internet connection.
			'may-timeout',           // May take longer to complete.
			'cacheable',             // Results can be cached.
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

		// Check cache first.
		$cache_key = 'product_research_' . md5( $query . '_' . $include_pricing . '_' . $include_images . '_' . $include_specs );
		$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai_product_research' );

		if ( false !== $cached && is_array( $cached ) ) {
			$cached['_from_cache'] = true;
			WP_MCP_AI_Logger::log_event(
				'product_research_cache_hit',
				'Product research served from cache',
				array(
					'query'     => $query,
					'cache_key' => $cache_key,
				)
			);
			return $cached;
		}

		// Log research start.
		WP_MCP_AI_Logger::log_event(
			'product_research_started',
			'Starting product research',
			array(
				'query'           => $query,
				'include_pricing' => $include_pricing,
				'include_images'  => $include_images,
				'include_specs'   => $include_specs,
			)
		);

		// Build research prompt.
		$prompt = $this->build_research_prompt( $query, $include_pricing, $include_images, $include_specs );

		// Use AI to research the product.
		$research_result = $this->perform_ai_research( $prompt, $context );

		if ( is_wp_error( $research_result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Product research failed: ' . $research_result->get_error_message(),
				array(
					'query' => $query,
					'error' => $research_result->get_error_code(),
				)
			);
			return $research_result;
		}

		// Parse and validate the research results.
		$product_data = $this->parse_research_results( $research_result, $query, $reference );

		if ( is_wp_error( $product_data ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to parse product research results: ' . $product_data->get_error_message(),
				array(
					'query' => $query,
				)
			);
			return $product_data;
		}

		// Cache the results for 24 hours.
		wp_cache_set( $cache_key, $product_data, 'wp_mcp_ai_product_research', DAY_IN_SECONDS );

		// Log success.
		WP_MCP_AI_Logger::log_event(
			'product_research_completed',
			'Product research completed successfully',
			array(
				'query'    => $query,
				'has_data' => ! empty( $product_data['product_data'] ),
			)
		);

		return $product_data;
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
	 * Build the research prompt for AI.
	 *
	 * @param string $query          Product query.
	 * @param bool   $include_pricing Include pricing information.
	 * @param bool   $include_images  Include image URLs.
	 * @param bool   $include_specs   Include specifications.
	 * @return string Research prompt.
	 */
	protected function build_research_prompt( $query, $include_pricing, $include_images, $include_specs ) {
		$prompt = sprintf(
			"Research the following product and gather comprehensive information:\n\n**Product:** %s\n\n",
			$query
		);

		$prompt .= "Use web search to find accurate, current information about this product. Gather:\n\n";
		$prompt .= "1. **Product Name**: Full official product name\n";
		$prompt .= "2. **Brand**: Brand/manufacturer name\n";
		$prompt .= "3. **Description**: Comprehensive product description (200-500 words) covering:\n";
		$prompt .= "   - Key features and benefits\n";
		$prompt .= "   - Use cases and target audience\n";
		$prompt .= "   - What makes it unique\n";
		$prompt .= "4. **Short Description**: Brief summary (50-100 words) for product card/preview\n";

		if ( $include_pricing ) {
			$prompt .= "5. **Regular Price**: Current market price in USD\n";
			$prompt .= "6. **Sale Price**: Current sale/promotional price if available\n";
		}

		if ( $include_images ) {
			$prompt .= "7. **Image URLs**: 2-4 high-quality product image URLs\n";
		}

		if ( $include_specs ) {
			$prompt .= "8. **Specifications**: Technical specs and attributes (size, weight, dimensions, materials, etc.)\n";
			$prompt .= "9. **Attributes**: Product variations (colors, sizes, models available)\n";
		}

		$prompt .= "10. **Categories**: Suggested WooCommerce product categories\n";
		$prompt .= "11. **Tags**: Relevant product tags for search/filtering\n";
		$prompt .= "12. **Product Type**: simple, variable, grouped, or external\n\n";

		$prompt .= "**IMPORTANT**: Return the information in the following JSON format:\n\n";
		$prompt .= "```json\n";
		$prompt .= "{\n";
		$prompt .= '  "title": "Product Name",';
		$prompt .= "\n";
		$prompt .= '  "brand": "Brand Name",';
		$prompt .= "\n";
		$prompt .= '  "description": "<p>HTML formatted description...</p>",';
		$prompt .= "\n";
		$prompt .= '  "description_secondary": "Short description text",';
		$prompt .= "\n";

		if ( $include_pricing ) {
			$prompt .= '  "local_price": "149.99",';
			$prompt .= "\n";
			$prompt .= '  "sale_price": "129.99",';
			$prompt .= "\n";
		}

		if ( $include_images ) {
			$prompt .= '  "image_urls": ["https://example.com/image1.jpg", "https://example.com/image2.jpg"],';
			$prompt .= "\n";
		}

		if ( $include_specs ) {
			$prompt .= '  "specifications": {';
			$prompt .= "\n";
			$prompt .= '    "weight": "2.5 lbs",';
			$prompt .= "\n";
			$prompt .= '    "dimensions": "10 x 5 x 3 inches",';
			$prompt .= "\n";
			$prompt .= '    "material": "Leather"';
			$prompt .= "\n";
			$prompt .= '  },';
			$prompt .= "\n";
			$prompt .= '  "attributes": [';
			$prompt .= "\n";
			$prompt .= '    {"name": "Color", "options": ["Black", "Brown", "Red"]},';
			$prompt .= "\n";
			$prompt .= '    {"name": "Size", "options": ["S", "M", "L", "XL"]}';
			$prompt .= "\n";
			$prompt .= '  ],';
			$prompt .= "\n";
		}

		$prompt .= '  "categories": ["Category1", "Category2"],';
		$prompt .= "\n";
		$prompt .= '  "tags": ["tag1", "tag2", "tag3"],';
		$prompt .= "\n";
		$prompt .= '  "product_type": "simple",';
		$prompt .= "\n";
		$prompt .= '  "stock_status": "instock",';
		$prompt .= "\n";
		$prompt .= '  "sources": ["https://source1.com", "https://source2.com"]';
		$prompt .= "\n";
		$prompt .= "}\n";
		$prompt .= "```\n\n";

		$prompt .= "Use web search to find the most accurate and up-to-date information. ";
		$prompt .= "Include source URLs in the 'sources' array. ";
		$prompt .= "Ensure product information is factually correct and matches current market offerings.\n";

		return $prompt;
	}

	/**
	 * Perform AI research using the plugin's AI capabilities.
	 *
	 * @param string $prompt  Research prompt.
	 * @param array  $context Execution context.
	 * @return array|WP_Error Research results or error.
	 */
	protected function perform_ai_research( $prompt, $context ) {
		// Get a suitable AI model for research.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$provider = $this->get_research_provider( $settings );
		$model    = $this->get_research_model( $provider, $settings );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		if ( is_wp_error( $model ) ) {
			return $model;
		}

		// Build messages array.
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a helpful AI assistant and e-commerce product researcher. You research products and gather comprehensive, accurate product information. Always respond with valid JSON matching the requested format. Use web search when available to ensure accuracy and get current pricing.',
			),
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		);

		// Call the appropriate AI client.
		$client = $this->get_ai_client( $provider, $settings );

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		// Make the API call.
		$result = $client->create_chat_completion(
			$messages,
			array(
				'model'       => $model,
				'temperature' => 0.2, // Low temperature for factual, accurate content.
				'max_tokens'  => 3000, // Allow for detailed product information.
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Extract the content from the response.
		if ( ! isset( $result['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from AI provider.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'content'  => $result['choices'][0]['message']['content'],
			'provider' => $provider,
			'model'    => $model,
		);
	}

	/**
	 * Get the best available provider for research.
	 *
	 * @param array $settings Plugin settings.
	 * @return string|WP_Error Provider name or error.
	 */
	protected function get_research_provider( $settings ) {
		// Prefer OpenAI or Gemini for research tasks.
		if ( ! empty( $settings['openai_api_key'] ) ) {
			return 'openai';
		}

		if ( ! empty( $settings['gemini_api_key'] ) ) {
			return 'gemini';
		}

		if ( ! empty( $settings['anthropic_api_key'] ) ) {
			return 'anthropic';
		}

		return new WP_Error(
			'wp_mcp_ai_no_provider',
			__( 'No AI provider configured. Please configure OpenAI, Gemini, or Anthropic API keys in plugin settings.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Get the best model for research from a provider.
	 *
	 * @param string $provider Provider name.
	 * @param array  $settings Plugin settings.
	 * @return string|WP_Error Model identifier or error.
	 */
	protected function get_research_model( $provider, $settings ) {
		switch ( $provider ) {
			case 'openai':
				return ! empty( $settings['openai_default_model'] ) ? $settings['openai_default_model'] : 'gpt-4o';

			case 'gemini':
				return ! empty( $settings['gemini_default_model'] ) ? $settings['gemini_default_model'] : 'gemini-2.0-flash-exp';

			case 'anthropic':
				return 'claude-sonnet-4-5-20250929';

			default:
				return new WP_Error(
					'wp_mcp_ai_unsupported_provider',
					sprintf(
						/* translators: %s: provider name */
						__( 'Provider not supported for research: %s', 'mcp-ai-wpoos-pro' ),
						$provider
					)
				);
		}
	}

	/**
	 * Get the appropriate AI client for a provider.
	 *
	 * @param string $provider Provider name.
	 * @param array  $settings Plugin settings.
	 * @return object|WP_Error AI client instance or error.
	 */
	protected function get_ai_client( $provider, $settings ) {
		switch ( $provider ) {
			case 'openai':
				if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'OpenAI client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_OpenAI_Client();

			case 'gemini':
				if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Gemini client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_Gemini_Client();

			case 'anthropic':
				if ( ! class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Anthropic client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_Anthropic_Client();

			default:
				return new WP_Error(
					'wp_mcp_ai_unsupported_provider',
					sprintf(
						/* translators: %s: provider name */
						__( 'AI client not available for provider: %s', 'mcp-ai-wpoos-pro' ),
						$provider
					)
				);
		}
	}

	/**
	 * Parse the AI research results into product data format.
	 *
	 * @param array  $research_result AI research results.
	 * @param string $query           Original product query.
	 * @param string $reference       Generated reference/SKU.
	 * @return array|WP_Error Parsed product data or error.
	 */
	protected function parse_research_results( $research_result, $query, $reference ) {
		$content = $research_result['content'];

		// Extract JSON from markdown code blocks if present.
		if ( preg_match( '/```json\s*(.*?)\s*```/s', $content, $matches ) ) {
			$json = $matches[1];
		} elseif ( preg_match( '/```\s*(.*?)\s*```/s', $content, $matches ) ) {
			$json = $matches[1];
		} else {
			$json = $content;
		}

		// Parse JSON.
		$data = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'wp_mcp_ai_parse_error',
				sprintf(
					/* translators: %s: JSON error message */
					__( 'Failed to parse AI response as JSON: %s', 'mcp-ai-wpoos-pro' ),
					json_last_error_msg()
				)
			);
		}

		// Ensure minimum required fields.
		if ( empty( $data['title'] ) ) {
			$data['title'] = $query;
		}

		// Build product data structure for WooCommerce.
		$product_data = array(
			'success'        => true,
			'query'          => $query,
			'reference'      => $reference,
			'product_data'   => array(
				'title'                 => sanitize_text_field( $data['title'] ),
				'brand'                 => isset( $data['brand'] ) ? sanitize_text_field( $data['brand'] ) : '',
				'product_type'          => isset( $data['product_type'] ) ? sanitize_key( $data['product_type'] ) : 'simple',
				'description'           => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
				'description_secondary' => isset( $data['description_secondary'] ) ? sanitize_textarea_field( $data['description_secondary'] ) : '',
				'local_price'           => isset( $data['local_price'] ) ? sanitize_text_field( $data['local_price'] ) : '',
				'sale_price'            => isset( $data['sale_price'] ) ? sanitize_text_field( $data['sale_price'] ) : '',
				'image_urls'            => isset( $data['image_urls'] ) && is_array( $data['image_urls'] ) ? array_map( 'esc_url_raw', $data['image_urls'] ) : array(),
				'categories'            => isset( $data['categories'] ) && is_array( $data['categories'] ) ? array_map( 'sanitize_text_field', $data['categories'] ) : array(),
				'tags'                  => isset( $data['tags'] ) && is_array( $data['tags'] ) ? array_map( 'sanitize_text_field', $data['tags'] ) : array(),
				'stock_status'          => isset( $data['stock_status'] ) ? sanitize_key( $data['stock_status'] ) : 'instock',
				'specifications'        => isset( $data['specifications'] ) && is_array( $data['specifications'] ) ? $this->sanitize_specifications( $data['specifications'] ) : array(),
				'attributes'            => isset( $data['attributes'] ) && is_array( $data['attributes'] ) ? $this->sanitize_attributes( $data['attributes'] ) : array(),
			),
			'research_metadata' => array(
				'sources'       => isset( $data['sources'] ) && is_array( $data['sources'] ) ? array_map( 'esc_url_raw', $data['sources'] ) : array(),
				'researched_at' => current_time( 'mysql' ),
				'provider'      => $research_result['provider'],
				'model'         => $research_result['model'],
			),
			'create_tool'    => 'create_woo_product',
		);

		return $product_data;
	}

	/**
	 * Sanitize product specifications.
	 *
	 * @param array $specifications Raw specifications data.
	 * @return array Sanitized specifications.
	 */
	protected function sanitize_specifications( $specifications ) {
		$sanitized = array();
		foreach ( $specifications as $key => $value ) {
			$sanitized[ sanitize_key( $key ) ] = sanitize_text_field( $value );
		}
		return $sanitized;
	}

	/**
	 * Sanitize product attributes.
	 *
	 * @param array $attributes Raw attributes data.
	 * @return array Sanitized attributes.
	 */
	protected function sanitize_attributes( $attributes ) {
		$sanitized = array();
		foreach ( $attributes as $attribute ) {
			if ( ! isset( $attribute['name'] ) || ! isset( $attribute['options'] ) ) {
				continue;
			}

			$sanitized[] = array(
				'name'    => sanitize_text_field( $attribute['name'] ),
				'options' => is_array( $attribute['options'] ) ? array_map( 'sanitize_text_field', $attribute['options'] ) : array(),
			);
		}
		return $sanitized;
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
