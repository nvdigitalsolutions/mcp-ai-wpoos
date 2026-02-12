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
	 * Maximum number of search queries to perform.
	 *
	 * @var int
	 */
	const MAX_SEARCH_QUERIES = 3;

	/**
	 * Maximum results per search query.
	 *
	 * @var int
	 */
	const MAX_RESULTS_PER_QUERY = 5;

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
		return __( 'Research and gather structured information about a product before creating it in WooCommerce. Performs comprehensive multi-step research using web search and AI analysis. Supports configurable research depth (basic/standard/comprehensive) and focus areas. Returns product data that can be used with the Create WooCommerce Product Draft tool.', 'mcp-ai-wpoos-pro' );
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
				'depth'               => array(
					'type'        => 'string',
					'description' => __( 'Research depth level.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'basic', 'standard', 'comprehensive' ),
					'default'     => 'standard',
				),
				'focus_areas'         => array(
					'type'        => 'array',
					'description' => __( 'Optional specific aspects to focus on (e.g., "specifications", "reviews", "alternatives", "pricing").', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
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
		$depth           = isset( $arguments['depth'] ) ? sanitize_text_field( $arguments['depth'] ) : 'standard';
		$focus_areas     = isset( $arguments['focus_areas'] ) && is_array( $arguments['focus_areas'] )
			? array_map( 'sanitize_text_field', $arguments['focus_areas'] )
			: array();
		$include_pricing = isset( $arguments['include_pricing'] ) ? (bool) $arguments['include_pricing'] : true;
		$include_images  = isset( $arguments['include_images'] ) ? (bool) $arguments['include_images'] : true;
		$include_specs   = isset( $arguments['include_specs'] ) ? (bool) $arguments['include_specs'] : true;

		// Validate depth parameter.
		if ( ! in_array( $depth, array( 'basic', 'standard', 'comprehensive' ), true ) ) {
			$depth = 'standard';
		}

		// Generate suggested reference if not provided.
		$reference = isset( $arguments['suggested_reference'] ) && ! empty( $arguments['suggested_reference'] )
			? sanitize_text_field( $arguments['suggested_reference'] )
			: $this->generate_reference( $query );

		// Check cache first.
		$cache_key = 'product_research_' . md5( $query . '_' . $depth . '_' . implode( '_', $focus_areas ) . '_' . $include_pricing . '_' . $include_images . '_' . $include_specs );
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
				'depth'           => $depth,
				'focus_areas'     => $focus_areas,
				'include_pricing' => $include_pricing,
				'include_images'  => $include_images,
				'include_specs'   => $include_specs,
			)
		);

		// Step 1: Gather information through web searches.
		$search_results = $this->gather_product_information( $query, $depth, $focus_areas, $context );

		if ( is_wp_error( $search_results ) ) {
			WP_MCP_AI_Logger::log_error(
				'Product research web search failed: ' . $search_results->get_error_message(),
				array(
					'query' => $query,
					'depth' => $depth,
					'error' => $search_results->get_error_code(),
				)
			);
			// Fall back to AI-only research if web search fails.
			$search_results = array(
				'results' => array(),
				'sources' => array(),
				'queries' => array( $query ),
			);
		}

		// Step 2: Build research prompt with gathered information.
		$prompt = $this->build_research_prompt( $query, $depth, $focus_areas, $search_results, $include_pricing, $include_images, $include_specs );

		// Step 3: Use AI to research the product.
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
				'query'         => $query,
				'depth'         => $depth,
				'focus_areas'   => $focus_areas,
				'sources_count' => count( $search_results['sources'] ?? array() ),
				'has_data'      => ! empty( $product_data['product_data'] ),
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
	 * Gather product information through web searches.
	 *
	 * @param string $query       Product query.
	 * @param string $depth       Research depth.
	 * @param array  $focus_areas Focus areas.
	 * @param array  $context     Execution context.
	 * @return array|WP_Error Search results or error.
	 */
	protected function gather_product_information( $query, $depth, $focus_areas, $context ) {
		// Check if web search tool is available.
		$registry        = WP_MCP_AI_Tool_Registry::get_instance();
		$web_search_tool = $registry->get_tool( 'web_search' );

		if ( ! $web_search_tool ) {
			// Return empty results if web search is not available.
			WP_MCP_AI_Logger::log_event(
				'product_research_no_web_search',
				'Web search tool not available, using AI-only mode',
				array( 'query' => $query )
			);
			return array(
				'results' => array(),
				'sources' => array(),
				'queries' => array( $query ),
			);
		}

		// Generate search queries based on depth and focus areas.
		$search_queries = $this->generate_product_search_queries( $query, $depth, $focus_areas );

		$all_results = array();
		$all_sources = array();

		foreach ( $search_queries as $search_query ) {
			// Execute web search.
			$search_result = $web_search_tool->execute(
				array(
					'query'       => $search_query,
					'max_results' => self::MAX_RESULTS_PER_QUERY,
				),
				$context
			);

			if ( is_wp_error( $search_result ) ) {
				// Log the error but continue with other searches.
				WP_MCP_AI_Logger::log_error(
					'Product research web search failed: ' . $search_result->get_error_message(),
					array(
						'query'        => $search_query,
						'product'      => $query,
						'error_code'   => $search_result->get_error_code(),
					)
				);
				continue;
			}

			// Collect results.
			if ( ! empty( $search_result['results'] ) && is_array( $search_result['results'] ) ) {
				foreach ( $search_result['results'] as $result ) {
					$all_results[] = $result;
					if ( ! empty( $result['url'] ) ) {
						$all_sources[] = array(
							'url'     => $result['url'],
							'title'   => isset( $result['title'] ) ? $result['title'] : '',
							'snippet' => isset( $result['snippet'] ) ? $result['snippet'] : '',
						);
					}
				}
			}
		}

		// Deduplicate sources by URL.
		$all_sources = $this->deduplicate_sources( $all_sources );

		WP_MCP_AI_Logger::log_event(
			'product_research_web_search_complete',
			'Web search completed for product research',
			array(
				'query'         => $query,
				'queries_count' => count( $search_queries ),
				'results_count' => count( $all_results ),
				'sources_count' => count( $all_sources ),
			)
		);

		return array(
			'results' => $all_results,
			'sources' => $all_sources,
			'queries' => $search_queries,
		);
	}

	/**
	 * Generate search queries for product research.
	 *
	 * @param string $query       Product query.
	 * @param string $depth       Research depth.
	 * @param array  $focus_areas Focus areas.
	 * @return array Search queries.
	 */
	protected function generate_product_search_queries( $query, $depth, $focus_areas ) {
		$queries = array();

		// Main query - always included.
		$queries[] = $query;

		// Determine total number of queries based on depth.
		// Note: num_queries is the TOTAL including the main query above.
		if ( 'basic' === $depth ) {
			$num_queries = 1; // Just the main query.
		} elseif ( 'comprehensive' === $depth ) {
			$num_queries = 3; // Main query + 2 additional.
		} else {
			$num_queries = 2; // Main query + 1 additional (standard).
		}

		// Add focus area queries.
		if ( ! empty( $focus_areas ) ) {
			foreach ( $focus_areas as $area ) {
				if ( count( $queries ) >= $num_queries ) {
					break;
				}
				$queries[] = $query . ' ' . $area;
			}
		}

		// Add depth-specific queries.
		if ( count( $queries ) < $num_queries ) {
			if ( 'comprehensive' === $depth ) {
				$queries[] = $query . ' specifications reviews';
				if ( count( $queries ) < $num_queries ) {
					$queries[] = $query . ' price comparison features';
				}
			} elseif ( 'standard' === $depth ) {
				$queries[] = $query . ' features price';
			}
		}

		// Limit to the calculated number of queries (already <= MAX_SEARCH_QUERIES).
		return array_slice( $queries, 0, $num_queries );
	}

	/**
	 * Deduplicate sources by URL.
	 *
	 * @param array $sources Sources array.
	 * @return array Deduplicated sources.
	 */
	protected function deduplicate_sources( $sources ) {
		$unique_sources = array();
		$seen_urls      = array();

		foreach ( $sources as $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}

			$url = $source['url'];

			if ( ! in_array( $url, $seen_urls, true ) ) {
				$unique_sources[] = $source;
				$seen_urls[]      = $url;
			}
		}

		return $unique_sources;
	}

	/**
	 * Build the research prompt for AI.
	 *
	 * @param string $query          Product query.
	 * @param string $depth          Research depth.
	 * @param array  $focus_areas    Focus areas.
	 * @param array  $search_results Search results from web search.
	 * @param bool   $include_pricing Include pricing information.
	 * @param bool   $include_images  Include image URLs.
	 * @param bool   $include_specs   Include specifications.
	 * @return string Research prompt.
	 */
	protected function build_research_prompt( $query, $depth, $focus_areas, $search_results, $include_pricing, $include_images, $include_specs ) {
		$prompt = sprintf(
			"Research the following product and gather comprehensive, accurate information:\n\n**Product:** %s\n\n",
			$query
		);

		// Add context from web search if available.
		if ( ! empty( $search_results['sources'] ) ) {
			$prompt .= "**Available Research Sources:**\n";
			$source_count = min( 5, count( $search_results['sources'] ) );
			for ( $i = 0; $i < $source_count; $i++ ) {
				$source = $search_results['sources'][ $i ];
				$prompt .= sprintf(
					"[%d] %s - %s\n",
					$i + 1,
					$source['title'],
					$source['snippet']
				);
			}
			$prompt .= "\n";
		}

		// Add depth-specific instructions.
		if ( 'comprehensive' === $depth ) {
			$prompt .= "**Research Depth: COMPREHENSIVE** - Gather extensive details, multiple sources, and thorough analysis.\n\n";
		} elseif ( 'basic' === $depth ) {
			$prompt .= "**Research Depth: BASIC** - Gather essential information only.\n\n";
		} else {
			$prompt .= "**Research Depth: STANDARD** - Gather key information with good detail.\n\n";
		}

		// Add focus areas if specified.
		if ( ! empty( $focus_areas ) ) {
			$prompt .= "**Focus Areas:** " . implode( ', ', $focus_areas ) . "\n\n";
		}

		$prompt .= "Use the provided sources and web search to find current, factually correct information. Gather:\n\n";
		$prompt .= "**CORE PRODUCT DATA:**\n";
		$prompt .= "1. **Product Name**: Full official product name\n";
		$prompt .= "2. **Brand**: Brand/manufacturer name\n";
		$prompt .= "3. **Product Identifiers** (if available):\n";
		$prompt .= "   - GTIN (Global Trade Item Number / Barcode)\n";
		$prompt .= "   - MPN (Manufacturer Part Number)\n";
		$prompt .= "   - SKU (Stock Keeping Unit)\n";
		$prompt .= "   - ISBN (if book product)\n";
		$prompt .= "4. **Product Condition**: new, refurbished, used, or damaged\n";
		$prompt .= "5. **Description**: Comprehensive product description (200-500 words) covering:\n";
		$prompt .= "   - Key features and benefits\n";
		$prompt .= "   - Use cases and target audience\n";
		$prompt .= "   - What makes it unique/competitive advantage\n";
		$prompt .= "6. **Short Description**: Brief summary (50-100 words) for product preview\n";

		if ( $include_pricing ) {
			$prompt .= "\n**PRICING:**\n";
			$prompt .= "7. **Regular Price**: Current market price in USD (numeric value only, e.g., 149.99)\n";
			$prompt .= "8. **Sale Price**: Current sale/promotional price if available (numeric value)\n";
			$prompt .= "9. **Price Currency**: Currency code (USD, EUR, GBP, etc.)\n";
			$prompt .= "10. **Price Valid Until**: Date if limited-time pricing (YYYY-MM-DD format)\n";
		}

		if ( $include_images ) {
			$prompt .= "\n**IMAGES:**\n";
			$prompt .= "11. **Image URLs**: 2-5 high-quality product images\n";
			$prompt .= "    - Use official product images from manufacturer/authorized retailers\n";
			$prompt .= "    - Include different angles/views if available\n";
			$prompt .= "    - Ensure URLs are accessible and not temporary\n";
		}

		if ( $include_specs ) {
			$prompt .= "\n**SPECIFICATIONS & ATTRIBUTES:**\n";
			$prompt .= "12. **Dimensions**: Product dimensions (length x width x height with units)\n";
			$prompt .= "13. **Weight**: Product weight (with units)\n";
			$prompt .= "14. **Materials**: Primary materials used\n";
			$prompt .= "15. **Color Options**: Available color variations\n";
			$prompt .= "16. **Size Options**: Available size variations (if applicable)\n";
			$prompt .= "17. **Technical Specifications**: Key technical specs relevant to product\n";
			$prompt .= "18. **Attributes**: Product variations/options with their available values\n";
		}

		$prompt .= "\n**CATEGORIZATION & SEO:**\n";
		$prompt .= "19. **Product Categories**: Primary and secondary WooCommerce categories\n";
		$prompt .= "20. **Tags**: 5-10 relevant product tags for search/filtering\n";
		$prompt .= "21. **Target Audience**: Who is this product for?\n";
		$prompt .= "22. **SEO Keywords**: 3-5 main keywords for SEO\n";

		$prompt .= "\n**AVAILABILITY & STOCK:**\n";
		$prompt .= "23. **Product Type**: Determine the appropriate WooCommerce product type:\n";
		$prompt .= "    - **simple**: Single item with no variations (default)\n";
		$prompt .= "    - **variable**: Has multiple variations (size, color, etc.) - each variation has own price/SKU/stock\n";
		$prompt .= "    - **grouped**: Collection of related simple products sold together or separately\n";
		$prompt .= "    - **external**: Links to another website for purchase (affiliate/dropship)\n";
		$prompt .= "24. **Virtual**: true if intangible (service, consultation, membership) - no shipping needed\n";
		$prompt .= "25. **Downloadable**: true if customer downloads file after purchase (ebook, software, music)\n";
		$prompt .= "26. **Stock Status**: instock, outofstock, or onbackorder\n";
		$prompt .= "27. **Availability Region**: Geographic availability if limited\n";
		$prompt .= "28. **External URL**: If product type is external, the URL where product is sold\n\n";

		$prompt .= "**IMPORTANT**: Return the information in the following JSON format:\n\n";
		$prompt .= "```json\n";
		$prompt .= "{\n";
		$prompt .= '  "title": "Official Product Name",';
		$prompt .= "\n";
		$prompt .= '  "brand": {';
		$prompt .= "\n";
		$prompt .= '    "name": "Brand Name",';
		$prompt .= "\n";
		$prompt .= '    "website": "https://brand.com" (if found)';
		$prompt .= "\n";
		$prompt .= '  },';
		$prompt .= "\n";
		$prompt .= '  "identifiers": {';
		$prompt .= "\n";
		$prompt .= '    "gtin": "012345678901",';
		$prompt .= "\n";
		$prompt .= '    "mpn": "MODEL-XYZ",';
		$prompt .= "\n";
		$prompt .= '    "sku": "SKU-123",';
		$prompt .= "\n";
		$prompt .= '    "isbn": "978-1234567890" (if book)';
		$prompt .= "\n";
		$prompt .= '  },';
		$prompt .= "\n";
		$prompt .= '  "condition": "new",';
		$prompt .= "\n";
		$prompt .= '  "description": "<p>HTML formatted comprehensive description...</p>",';
		$prompt .= "\n";
		$prompt .= '  "description_secondary": "Short description text",';
		$prompt .= "\n";
		$prompt .= '  "target_audience": "Who is this for?",';
		$prompt .= "\n";

		if ( $include_pricing ) {
			$prompt .= '  "pricing": {';
			$prompt .= "\n";
			$prompt .= '    "regular_price": "149.99",';
			$prompt .= "\n";
			$prompt .= '    "sale_price": "129.99",';
			$prompt .= "\n";
			$prompt .= '    "currency": "USD",';
			$prompt .= "\n";
			$prompt .= '    "price_valid_until": "2026-12-31"';
			$prompt .= "\n";
			$prompt .= '  },';
			$prompt .= "\n";
		}

		if ( $include_images ) {
			$prompt .= '  "images": [';
			$prompt .= "\n";
			$prompt .= '    {';
			$prompt .= "\n";
			$prompt .= '      "url": "https://example.com/image1.jpg",';
			$prompt .= "\n";
			$prompt .= '      "alt": "Product front view"';
			$prompt .= "\n";
			$prompt .= '    },';
			$prompt .= "\n";
			$prompt .= '    {';
			$prompt .= "\n";
			$prompt .= '      "url": "https://example.com/image2.jpg",';
			$prompt .= "\n";
			$prompt .= '      "alt": "Product side view"';
			$prompt .= "\n";
			$prompt .= '    }';
			$prompt .= "\n";
			$prompt .= '  ],';
			$prompt .= "\n";
		}

		if ( $include_specs ) {
			$prompt .= '  "dimensions": {';
			$prompt .= "\n";
			$prompt .= '    "length": "10",';
			$prompt .= "\n";
			$prompt .= '    "width": "5",';
			$prompt .= "\n";
			$prompt .= '    "height": "3",';
			$prompt .= "\n";
			$prompt .= '    "unit": "inches"';
			$prompt .= "\n";
			$prompt .= '  },';
			$prompt .= "\n";
			$prompt .= '  "weight": {';
			$prompt .= "\n";
			$prompt .= '    "value": "2.5",';
			$prompt .= "\n";
			$prompt .= '    "unit": "lbs"';
			$prompt .= "\n";
			$prompt .= '  },';
			$prompt .= "\n";
			$prompt .= '  "specifications": {';
			$prompt .= "\n";
			$prompt .= '    "material": "Leather",';
			$prompt .= "\n";
			$prompt .= '    "color": "Black",';
			$prompt .= "\n";
			$prompt .= '    "warranty": "1 year"';
			$prompt .= "\n";
			$prompt .= '  },';
			$prompt .= "\n";
			$prompt .= '  "attributes": [';
			$prompt .= "\n";
			$prompt .= '    {"name": "Color", "options": ["Black", "Brown", "Red"], "visible": true, "variation": true},';
			$prompt .= "\n";
			$prompt .= '    {"name": "Size", "options": ["S", "M", "L", "XL"], "visible": true, "variation": true}';
			$prompt .= "\n";
			$prompt .= '  ],';
			$prompt .= "\n";
		}

		$prompt .= '  "categories": ["Primary Category", "Secondary Category"],';
		$prompt .= "\n";
		$prompt .= '  "tags": ["tag1", "tag2", "tag3", "tag4", "tag5"],';
		$prompt .= "\n";
		$prompt .= '  "seo_keywords": ["keyword1", "keyword2", "keyword3"],';
		$prompt .= "\n";
		$prompt .= '  "product_type": "simple",';
		$prompt .= "\n";
		$prompt .= '  "virtual": false,';
		$prompt .= "\n";
		$prompt .= '  "downloadable": false,';
		$prompt .= "\n";
		$prompt .= '  "stock_status": "instock",';
		$prompt .= "\n";
		$prompt .= '  "availability_region": "US" or "Worldwide",';
		$prompt .= "\n";
		$prompt .= '  "external_url": "https://external-site.com/product" (if external type),';
		$prompt .= "\n";
		$prompt .= '  "sources": [';
		$prompt .= "\n";
		$prompt .= '    {"url": "https://source1.com", "type": "manufacturer"},';
		$prompt .= "\n";
		$prompt .= '    {"url": "https://source2.com", "type": "retailer"}';
		$prompt .= "\n";
		$prompt .= '  ],';
		$prompt .= "\n";
		$prompt .= '  "data_quality": {';
		$prompt .= "\n";
		$prompt .= '    "completeness_score": 95,';
		$prompt .= "\n";
		$prompt .= '    "confidence_level": "high",';
		$prompt .= "\n";
		$prompt .= '    "last_verified": "2026-02-12"';
		$prompt .= "\n";
		$prompt .= '  }';
		$prompt .= "\n";
		$prompt .= "}\n";
		$prompt .= "```\n\n";

		$prompt .= "**CRITICAL REQUIREMENTS:**\n";
		$prompt .= "- Use web search to find CURRENT, ACCURATE information\n";
		$prompt .= "- Verify all data from multiple reliable sources\n";
		$prompt .= "- Include source URLs with source type (manufacturer/retailer/review site)\n";
		$prompt .= "- Ensure product identifiers (GTIN, MPN) are correct if found\n";
		$prompt .= "- Choose correct product_type based on whether product has variations:\n";
		$prompt .= "  * simple: No variations (single size/color/option)\n";
		$prompt .= "  * variable: Multiple variations (different sizes/colors with own pricing)\n";
		$prompt .= "  * grouped: Bundle of related products\n";
		$prompt .= "  * external: Sold on another website\n";
		$prompt .= "- Mark virtual=true for services/intangibles (no shipping)\n";
		$prompt .= "- Mark downloadable=true for digital products with file downloads\n";
		$prompt .= "- Aim for >95% data completeness\n";
		$prompt .= "- Only include data you're confident is accurate\n";
		$prompt .= "- Mark confidence level (high/medium/low) based on source quality\n";

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

		// Extract brand information (handle both string and object formats).
		$brand_name    = '';
		$brand_website = '';
		if ( isset( $data['brand'] ) ) {
			if ( is_array( $data['brand'] ) ) {
				$brand_name    = isset( $data['brand']['name'] ) ? sanitize_text_field( $data['brand']['name'] ) : '';
				$brand_website = isset( $data['brand']['website'] ) ? esc_url_raw( $data['brand']['website'] ) : '';
			} else {
				$brand_name = sanitize_text_field( $data['brand'] );
			}
		}

		// Extract product identifiers for Schema.org compliance.
		$identifiers = array();
		if ( isset( $data['identifiers'] ) && is_array( $data['identifiers'] ) ) {
			if ( ! empty( $data['identifiers']['gtin'] ) ) {
				$identifiers['gtin'] = sanitize_text_field( $data['identifiers']['gtin'] );
			}
			if ( ! empty( $data['identifiers']['mpn'] ) ) {
				$identifiers['mpn'] = sanitize_text_field( $data['identifiers']['mpn'] );
			}
			if ( ! empty( $data['identifiers']['sku'] ) ) {
				$identifiers['sku'] = sanitize_text_field( $data['identifiers']['sku'] );
			}
			if ( ! empty( $data['identifiers']['isbn'] ) ) {
				$identifiers['isbn'] = sanitize_text_field( $data['identifiers']['isbn'] );
			}
		}

		// Extract pricing information (handle both old and new formats).
		$regular_price       = '';
		$sale_price          = '';
		$currency            = 'USD';
		$price_valid_until   = '';
		
		if ( isset( $data['pricing'] ) && is_array( $data['pricing'] ) ) {
			$regular_price     = isset( $data['pricing']['regular_price'] ) ? sanitize_text_field( $data['pricing']['regular_price'] ) : '';
			$sale_price        = isset( $data['pricing']['sale_price'] ) ? sanitize_text_field( $data['pricing']['sale_price'] ) : '';
			$currency          = isset( $data['pricing']['currency'] ) ? sanitize_text_field( $data['pricing']['currency'] ) : 'USD';
			$price_valid_until = isset( $data['pricing']['price_valid_until'] ) ? sanitize_text_field( $data['pricing']['price_valid_until'] ) : '';
		} elseif ( isset( $data['local_price'] ) ) {
			// Backwards compatibility with old format.
			$regular_price = sanitize_text_field( $data['local_price'] );
			$sale_price    = isset( $data['sale_price'] ) ? sanitize_text_field( $data['sale_price'] ) : '';
		}

		// Extract images (handle both old and new formats).
		$images = array();
		if ( isset( $data['images'] ) && is_array( $data['images'] ) ) {
			foreach ( $data['images'] as $image ) {
				if ( is_array( $image ) && isset( $image['url'] ) ) {
					$images[] = array(
						'url' => esc_url_raw( $image['url'] ),
						'alt' => isset( $image['alt'] ) ? sanitize_text_field( $image['alt'] ) : '',
					);
				} elseif ( is_string( $image ) ) {
					$images[] = array(
						'url' => esc_url_raw( $image ),
						'alt' => '',
					);
				}
			}
		} elseif ( isset( $data['image_urls'] ) && is_array( $data['image_urls'] ) ) {
			// Backwards compatibility.
			foreach ( $data['image_urls'] as $url ) {
				$images[] = array(
					'url' => esc_url_raw( $url ),
					'alt' => '',
				);
			}
		}

		// Extract dimensions (handle structured format).
		$dimensions = array();
		if ( isset( $data['dimensions'] ) && is_array( $data['dimensions'] ) ) {
			$dimensions = array(
				'length' => isset( $data['dimensions']['length'] ) ? sanitize_text_field( $data['dimensions']['length'] ) : '',
				'width'  => isset( $data['dimensions']['width'] ) ? sanitize_text_field( $data['dimensions']['width'] ) : '',
				'height' => isset( $data['dimensions']['height'] ) ? sanitize_text_field( $data['dimensions']['height'] ) : '',
				'unit'   => isset( $data['dimensions']['unit'] ) ? sanitize_text_field( $data['dimensions']['unit'] ) : 'inches',
			);
		}

		// Extract weight (handle structured format).
		$weight = array();
		if ( isset( $data['weight'] ) && is_array( $data['weight'] ) ) {
			$weight = array(
				'value' => isset( $data['weight']['value'] ) ? sanitize_text_field( $data['weight']['value'] ) : '',
				'unit'  => isset( $data['weight']['unit'] ) ? sanitize_text_field( $data['weight']['unit'] ) : 'lbs',
			);
		}

		// Extract and validate sources.
		$sources = array();
		if ( isset( $data['sources'] ) && is_array( $data['sources'] ) ) {
			foreach ( $data['sources'] as $source ) {
				if ( is_array( $source ) && isset( $source['url'] ) ) {
					$sources[] = array(
						'url'  => esc_url_raw( $source['url'] ),
						'type' => isset( $source['type'] ) ? sanitize_key( $source['type'] ) : 'unknown',
					);
				} elseif ( is_string( $source ) ) {
					$sources[] = array(
						'url'  => esc_url_raw( $source ),
						'type' => 'unknown',
					);
				}
			}
		}

		// Extract data quality metrics.
		$data_quality = array(
			'completeness_score' => 0,
			'confidence_level'   => 'medium',
			'last_verified'      => current_time( 'mysql' ),
		);
		if ( isset( $data['data_quality'] ) && is_array( $data['data_quality'] ) ) {
			$data_quality = array(
				'completeness_score' => isset( $data['data_quality']['completeness_score'] ) ? absint( $data['data_quality']['completeness_score'] ) : 0,
				'confidence_level'   => isset( $data['data_quality']['confidence_level'] ) ? sanitize_key( $data['data_quality']['confidence_level'] ) : 'medium',
				'last_verified'      => isset( $data['data_quality']['last_verified'] ) ? sanitize_text_field( $data['data_quality']['last_verified'] ) : current_time( 'mysql' ),
			);
		} else {
			// Calculate completeness score if not provided.
			$data_quality['completeness_score'] = $this->calculate_completeness_score( $data );
		}

		// Build Schema.org compliant product data structure.
		$product_data = array(
			'success'        => true,
			'query'          => $query,
			'reference'      => ! empty( $identifiers['sku'] ) ? $identifiers['sku'] : $reference,
			'product_data'   => array(
				// Core WooCommerce fields.
				'title'                 => sanitize_text_field( $data['title'] ),
				'brand'                 => $brand_name,
				'brand_website'         => $brand_website,
				'product_type'          => $this->determine_product_type( $data ),
				'description'           => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
				'description_secondary' => isset( $data['description_secondary'] ) ? sanitize_textarea_field( $data['description_secondary'] ) : '',
				
				// Pricing.
				'local_price'           => $regular_price,
				'sale_price'            => $sale_price,
				'currency'              => $currency,
				'price_valid_until'     => $price_valid_until,
				
				// Images.
				'images'                => $images,
				
				// Categorization.
				'categories'            => isset( $data['categories'] ) && is_array( $data['categories'] ) ? array_map( 'sanitize_text_field', $data['categories'] ) : array(),
				'tags'                  => isset( $data['tags'] ) && is_array( $data['tags'] ) ? array_map( 'sanitize_text_field', $data['tags'] ) : array(),
				
				// Stock & Availability.
				'stock_status'          => isset( $data['stock_status'] ) ? sanitize_key( $data['stock_status'] ) : 'instock',
				'virtual'               => isset( $data['virtual'] ) ? (bool) $data['virtual'] : false,
				'downloadable'          => isset( $data['downloadable'] ) ? (bool) $data['downloadable'] : false,
				'external_url'          => isset( $data['external_url'] ) ? esc_url_raw( $data['external_url'] ) : '',
				
				// Dimensions and weight.
				'dimensions'            => $dimensions,
				'weight'                => $weight,
				
				// Specifications and attributes.
				'specifications'        => isset( $data['specifications'] ) && is_array( $data['specifications'] ) ? $this->sanitize_specifications( $data['specifications'] ) : array(),
				'attributes'            => isset( $data['attributes'] ) && is_array( $data['attributes'] ) ? $this->sanitize_attributes_enhanced( $data['attributes'] ) : array(),
				
				// Schema.org fields.
				'identifiers'           => $identifiers,
				'condition'             => isset( $data['condition'] ) ? sanitize_key( $data['condition'] ) : 'new',
				'target_audience'       => isset( $data['target_audience'] ) ? sanitize_text_field( $data['target_audience'] ) : '',
				'availability_region'   => isset( $data['availability_region'] ) ? sanitize_text_field( $data['availability_region'] ) : '',
				
				// SEO fields.
				'seo_keywords'          => isset( $data['seo_keywords'] ) && is_array( $data['seo_keywords'] ) ? array_map( 'sanitize_text_field', $data['seo_keywords'] ) : array(),
			),
			'research_metadata' => array(
				'sources'            => $sources,
				'researched_at'      => current_time( 'mysql' ),
				'provider'           => $research_result['provider'],
				'model'              => $research_result['model'],
				'data_quality'       => $data_quality,
			),
			'create_tool'    => 'create_woo_product',
		);

		return $product_data;
	}

	/**
	 * Calculate data completeness score.
	 *
	 * @param array $data Raw product data.
	 * @return int Completeness score (0-100).
	 */
	protected function calculate_completeness_score( $data ) {
		$required_fields = array(
			'title',
			'brand',
			'description',
			'description_secondary',
		);

		$optional_fields = array(
			'identifiers',
			'pricing',
			'images',
			'dimensions',
			'weight',
			'specifications',
			'attributes',
			'categories',
			'tags',
			'seo_keywords',
			'target_audience',
		);

		$score        = 0;
		$total_fields = count( $required_fields ) + count( $optional_fields );

		// Required fields (40% weight).
		$required_present = 0;
		foreach ( $required_fields as $field ) {
			if ( ! empty( $data[ $field ] ) ) {
				$required_present++;
			}
		}
		$score += ( $required_present / count( $required_fields ) ) * 40;

		// Optional fields (60% weight).
		$optional_present = 0;
		foreach ( $optional_fields as $field ) {
			if ( ! empty( $data[ $field ] ) ) {
				$optional_present++;
			}
		}
		$score += ( $optional_present / count( $optional_fields ) ) * 60;

		return round( $score );
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
	 * Sanitize product attributes with enhanced WooCommerce structure.
	 *
	 * @param array $attributes Raw attributes data.
	 * @return array Sanitized attributes with WooCommerce-compatible structure.
	 */
	protected function sanitize_attributes_enhanced( $attributes ) {
		$sanitized = array();
		foreach ( $attributes as $attribute ) {
			if ( ! isset( $attribute['name'] ) || ! isset( $attribute['options'] ) ) {
				continue;
			}

			$sanitized[] = array(
				'name'      => sanitize_text_field( $attribute['name'] ),
				'options'   => is_array( $attribute['options'] ) ? array_map( 'sanitize_text_field', $attribute['options'] ) : array(),
				'visible'   => isset( $attribute['visible'] ) ? (bool) $attribute['visible'] : true,
				'variation' => isset( $attribute['variation'] ) ? (bool) $attribute['variation'] : false,
			);
		}
		return $sanitized;
	}

	/**
	 * Determine appropriate WooCommerce product type based on product characteristics.
	 *
	 * @param array $data Raw product data.
	 * @return string WooCommerce product type.
	 */
	protected function determine_product_type( $data ) {
		// Check if explicitly set and valid.
		if ( ! empty( $data['product_type'] ) ) {
			$type = sanitize_key( $data['product_type'] );
			if ( in_array( $type, array( 'simple', 'variable', 'grouped', 'external' ), true ) ) {
				return $type;
			}
		}

		// Determine based on characteristics.
		// Has variations with multiple options? → Variable.
		if ( ! empty( $data['attributes'] ) && is_array( $data['attributes'] ) ) {
			foreach ( $data['attributes'] as $attribute ) {
				if ( isset( $attribute['variation'] ) && $attribute['variation'] && ! empty( $attribute['options'] ) && count( $attribute['options'] ) > 1 ) {
					return 'variable';
				}
			}
		}

		// Has external URL? → External.
		if ( ! empty( $data['external_url'] ) ) {
			return 'external';
		}

		// Default to simple.
		return 'simple';
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
