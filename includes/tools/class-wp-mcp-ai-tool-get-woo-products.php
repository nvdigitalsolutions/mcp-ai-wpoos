<?php
/**
 * Tool returning WooCommerce products.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

/**
 * Provides WooCommerce product listings with core merchandising metadata.
 */
class WP_MCP_AI_Tool_Get_Woo_Products implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Determine whether WooCommerce is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_products' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The WooCommerce Products tool is disabled because WooCommerce is not active.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_woo_products';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get WooCommerce Products', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns WooCommerce catalog products with pricing and stock details. When include_variations is enabled or stock_status filter is used, variable products are automatically expanded to show their variations with accurate stock quantities. Requires WooCommerce.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit'              => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of products to retrieve.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 20,
					'default'     => 5,
				),
				'sku'                => array(
					'type'        => 'string',
					'description' => __( 'Optional product SKU to filter by.', 'mcp-ai-wpoos' ),
				),
				'status'             => array(
					'type'        => 'string',
					'description' => __( 'Optional product status to filter by (e.g. publish, draft).', 'mcp-ai-wpoos' ),
				),
				'stock_status'       => array(
					'type'        => 'string',
					'description' => __( 'Optional stock status to filter by (e.g. instock, outofstock). When used, variable products automatically include their variations with accurate stock quantities.', 'mcp-ai-wpoos' ),
				),
				'include_variations' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include product variations for variable products. When true (default), variable products are represented by their variations with individual stock quantities for accurate stock reporting.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_woo_missing', __( 'WooCommerce is not active on this site.', 'mcp-ai-wpoos' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to view WooCommerce products.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'view_woocommerce_reports' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view WooCommerce products.', 'mcp-ai-wpoos' ) );
		}

		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 5;
		$limit = $limit > 0 ? min( $limit, 20 ) : 5;

		$args = array(
			'limit'   => $limit,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		);

		if ( ! empty( $arguments['status'] ) ) {
			$args['status'] = sanitize_key( $arguments['status'] );
		}

		if ( ! empty( $arguments['stock_status'] ) ) {
			$args['stock_status'] = sanitize_key( $arguments['stock_status'] );
		}

		if ( ! empty( $arguments['sku'] ) ) {
			$sku = is_string( $arguments['sku'] ) ? $arguments['sku'] : '';

			if ( function_exists( 'wc_clean' ) ) {
				$args['sku'] = wc_clean( $sku );
			} else {
				$args['sku'] = sanitize_text_field( $sku );
			}
		}

		$products = wc_get_products( $args );

		// Determine if we should include variations.
		// Defaults to true for accurate stock information (especially important for stock_status filters).
		$include_variations = true;
		if ( isset( $arguments['include_variations'] ) ) {
			$include_variations = (bool) $arguments['include_variations'];
		}

		$results         = array();
		$variation_count = 0;
		$parent_count    = 0;

		if ( $include_variations ) {
			// Process products and expand variable products to their variations.
			foreach ( $products as $product ) {
				if ( ! $product || ! is_object( $product ) ) {
					continue;
				}

				/** @var WC_Product $product */
				// Check if this is a variable product.
				$is_variable = method_exists( $product, 'is_type' ) && $product->is_type( 'variable' );

				if ( $is_variable && method_exists( $product, 'get_children' ) ) {
					// Get variation IDs for this variable product.
					$variation_ids = $product->get_children();

					if ( ! empty( $variation_ids ) ) {
						// Fetch each variation and add it to results.
						foreach ( $variation_ids as $variation_id ) {
							$variation = wc_get_product( $variation_id );

							if ( ! $variation || ! is_object( $variation ) ) {
								continue;
							}

							// Build variation data with parent context.
							$variation_data                = $this->format_product_data( $variation );
							$variation_data['parent_id']   = $product->get_id();
							$variation_data['parent_name'] = $product->get_name();

							// Get variation attributes (e.g., Size: Large, Color: Red).
							if ( method_exists( $variation, 'get_attributes' ) ) {
								$attributes = $variation->get_attributes();
								if ( ! empty( $attributes ) ) {
									$variation_data['attributes'] = $attributes;
								}
							}

							$results[] = $variation_data;
							++$variation_count;
						}
						++$parent_count;
					} else {
						// Variable product with no variations - include the parent.
						$results[] = $this->format_product_data( $product );
						++$parent_count;
					}
				} else {
					// Non-variable product (simple, grouped, external, etc.).
					$results[] = $this->format_product_data( $product );
					++$parent_count;
				}
			}
		} else {
			// Standard mode: just return products as-is.
			foreach ( $products as $product ) {
				if ( ! $product || ! is_object( $product ) ) {
					continue;
				}

				$results[] = $this->format_product_data( $product );
			}
		}

		// Build summary message.
		if ( $variation_count > 0 ) {
			$summary = sprintf(
				/* translators: 1: number of parent products, 2: number of variations */
				__( 'Found %1$d product(s) with %2$d variation(s). Variable products are represented by their variations with individual stock quantities.', 'mcp-ai-wpoos' ),
				$parent_count,
				$variation_count
			);
		} else {
			$summary = sprintf(
				/* translators: %d: number of products */
				__( 'Found %d product(s)', 'mcp-ai-wpoos' ),
				count( $results )
			);
		}

		return array(
			'message'  => $summary, // Chat client display
			'summary'  => $summary, // Backward compatibility
			'products' => $results,
			'count'    => count( $results ),
		);
	}

	/**
	 * Format product data into standardized array structure.
	 *
	 * @param WC_Product $product Product object.
	 * @return array Formatted product data.
	 */
	protected function format_product_data( $product ) {
		return array(
			'id'             => $product->get_id(),
			'name'           => $product->get_name(),
			'sku'            => $product->get_sku(),
			'type'           => $product->get_type(),
			'status'         => $product->get_status(),
			'price'          => $product->get_price(),
			'regular_price'  => $product->get_regular_price(),
			'sale_price'     => $product->get_sale_price(),
			'stock_status'   => $product->get_stock_status(),
			'stock_quantity' => $product->get_stock_quantity(),
			'manage_stock'   => method_exists( $product, 'get_manage_stock' ) ? $product->get_manage_stock() : null,
			'permalink'      => method_exists( $product, 'get_permalink' ) ? $product->get_permalink() : '',
			'date_created'   => method_exists( $product, 'get_date_created' ) && $product->get_date_created() ? gmdate( DATE_W3C, $product->get_date_created()->getTimestamp() ) : null,
			'date_modified'  => method_exists( $product, 'get_date_modified' ) && $product->get_date_modified() ? gmdate( DATE_W3C, $product->get_date_modified()->getTimestamp() ) : null,
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'ecommerce_business',

			'pattern_compatibility' => array( 'orchestrator' ),

			'profession_tags'       => array( 'ecommerce_manager', 'product_manager' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
