<?php
/**
 * Upsell Recommendations Tool
 *
 * AI-powered product recommendation engine based on purchase history,
 * cart contents, related products, and cross-sells.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for product upsell recommendations.
 *
 * Supports:
 * - Purchase history analysis
 * - Cart-based recommendations
 * - Related products
 * - Cross-sell suggestions
 * - Custom recommendation rules
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Upsell_Recommendations implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if WooCommerce is active and toolkit is enabled.
	 */
	public static function is_available() {
		// Check if WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if e-commerce toolkit is enabled.
		return function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && wp_mcp_ai_is_ecommerce_toolkit_enabled();
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return __( 'Upsell recommendations require WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Upsell recommendations tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'upsell_recommendations';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Upsell Recommendations', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'AI-powered product recommendation engine. Generates personalized upsell and cross-sell suggestions based on purchase history, cart contents, product relationships, and customer behavior patterns.', 'mcp-ai-wpoos-pro' );
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
				'recommendation_type'  => array(
					'type'        => 'string',
					'description' => __( 'Type of recommendations to generate', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'product_based', 'customer_based', 'cart_based', 'frequently_bought' ),
					'default'     => 'product_based',
				),
				'product_id'           => array(
					'type'        => 'integer',
					'description' => __( 'Product ID for product-based recommendations', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'customer_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Customer ID for customer-based recommendations', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'cart_items'           => array(
					'type'        => 'array',
					'description' => __( 'Cart items for cart-based recommendations', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'limit'                => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of recommendations', 'mcp-ai-wpoos-pro' ),
					'default'     => 5,
					'minimum'     => 1,
					'maximum'     => 20,
				),
				'min_price'            => array(
					'type'        => 'number',
					'description' => __( 'Minimum product price filter', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'max_price'            => array(
					'type'        => 'number',
					'description' => __( 'Maximum product price filter', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'exclude_out_of_stock' => array(
					'type'        => 'boolean',
					'description' => __( 'Exclude out of stock products', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
			'requires-plugin',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_woocommerce' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate recommendations.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		$recommendation_type = isset( $arguments['recommendation_type'] ) ? sanitize_text_field( $arguments['recommendation_type'] ) : 'product_based';

		switch ( $recommendation_type ) {
			case 'product_based':
				return $this->get_product_based_recommendations( $arguments );
			case 'customer_based':
				return $this->get_customer_based_recommendations( $arguments );
			case 'cart_based':
				return $this->get_cart_based_recommendations( $arguments );
			case 'frequently_bought':
				return $this->get_frequently_bought_together( $arguments );
			default:
				return new WP_Error(
					'invalid_recommendation_type',
					__( 'Invalid recommendation type specified.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * Get product-based recommendations.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function get_product_based_recommendations( $arguments ) {
		$product_id = isset( $arguments['product_id'] ) ? absint( $arguments['product_id'] ) : 0;

		if ( ! $product_id ) {
			return new WP_Error(
				'missing_product_id',
				__( 'Product ID is required for product-based recommendations.', 'mcp-ai-wpoos-pro' )
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error(
				'product_not_found',
				__( 'Product not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$recommendations = array();

		// Get upsells.
		$upsell_ids = $product->get_upsell_ids();
		foreach ( $upsell_ids as $upsell_id ) {
			$rec_product = wc_get_product( $upsell_id );
			if ( $rec_product && $this->filter_product( $rec_product, $arguments ) ) {
				$recommendations[] = $this->format_recommendation( $rec_product, 'upsell' );
			}
		}

		// Get cross-sells.
		$cross_sell_ids = $product->get_cross_sell_ids();
		foreach ( $cross_sell_ids as $cross_sell_id ) {
			$rec_product = wc_get_product( $cross_sell_id );
			if ( $rec_product && $this->filter_product( $rec_product, $arguments ) ) {
				$recommendations[] = $this->format_recommendation( $rec_product, 'cross_sell' );
			}
		}

		// Get related products by category.
		$category_ids = $product->get_category_ids();
		if ( ! empty( $category_ids ) ) {
			$related_args = array(
				'post_type'      => 'product',
				'posts_per_page' => 10,
				'post__not_in'   => array( $product_id ),
				'tax_query'      => array(
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => $category_ids,
					),
				),
			);

			$related_products = get_posts( $related_args );
			foreach ( $related_products as $related_post ) {
				$rec_product = wc_get_product( $related_post->ID );
				if ( $rec_product && $this->filter_product( $rec_product, $arguments ) ) {
					$recommendations[] = $this->format_recommendation( $rec_product, 'related' );
				}
			}
		}

		// Limit results.
		$limit           = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 5;
		$recommendations = array_slice( $recommendations, 0, $limit );

		return array(
			'success'             => true,
			'recommendation_type' => 'product_based',
			'base_product'        => array(
				'id'    => $product->get_id(),
				'name'  => $product->get_name(),
				'price' => $product->get_price(),
			),
			'recommendations'     => $recommendations,
			'count'               => count( $recommendations ),
			'message'             => sprintf(
				/* translators: %d: Number of recommendations */
				__( 'Generated %d product recommendations.', 'mcp-ai-wpoos-pro' ),
				count( $recommendations )
			),
		);
	}

	/**
	 * Get customer-based recommendations.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function get_customer_based_recommendations( $arguments ) {
		$customer_id = isset( $arguments['customer_id'] ) ? absint( $arguments['customer_id'] ) : 0;

		if ( ! $customer_id ) {
			return new WP_Error(
				'missing_customer_id',
				__( 'Customer ID is required for customer-based recommendations.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get customer's purchase history.
		$orders = wc_get_orders(
			array(
				'customer_id' => $customer_id,
				'status'      => array( 'completed', 'processing' ),
				'limit'       => 10,
			)
		);

		$purchased_product_ids = array();
		$category_counts       = array();

		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				if ( ! $product ) {
					continue;
				}

				$purchased_product_ids[] = $product->get_id();

				// Track categories.
				$category_ids = $product->get_category_ids();
				foreach ( $category_ids as $cat_id ) {
					if ( ! isset( $category_counts[ $cat_id ] ) ) {
						$category_counts[ $cat_id ] = 0;
					}
					++$category_counts[ $cat_id ];
				}
			}
		}

		// Get top categories.
		arsort( $category_counts );
		$top_categories = array_slice( array_keys( $category_counts ), 0, 3 );

		// Find recommendations from top categories.
		$recommendations = array();
		if ( ! empty( $top_categories ) ) {
			$rec_args = array(
				'post_type'      => 'product',
				'posts_per_page' => isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 5,
				'post__not_in'   => $purchased_product_ids,
				'tax_query'      => array(
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => $top_categories,
					),
				),
			);

			$rec_products = get_posts( $rec_args );
			foreach ( $rec_products as $rec_post ) {
				$rec_product = wc_get_product( $rec_post->ID );
				if ( $rec_product && $this->filter_product( $rec_product, $arguments ) ) {
					$recommendations[] = $this->format_recommendation( $rec_product, 'customer_preference' );
				}
			}
		}

		return array(
			'success'             => true,
			'recommendation_type' => 'customer_based',
			'customer_id'         => $customer_id,
			'recommendations'     => $recommendations,
			'count'               => count( $recommendations ),
			'message'             => sprintf(
				/* translators: %d: Number of recommendations */
				__( 'Generated %d personalized recommendations.', 'mcp-ai-wpoos-pro' ),
				count( $recommendations )
			),
		);
	}

	/**
	 * Get cart-based recommendations.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function get_cart_based_recommendations( $arguments ) {
		$cart_items = isset( $arguments['cart_items'] ) && is_array( $arguments['cart_items'] ) ? array_map( 'absint', $arguments['cart_items'] ) : array();

		if ( empty( $cart_items ) ) {
			return new WP_Error(
				'missing_cart_items',
				__( 'Cart items are required for cart-based recommendations.', 'mcp-ai-wpoos-pro' )
			);
		}

		$recommendations = array();
		$category_ids    = array();

		// Collect categories from cart items.
		foreach ( $cart_items as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$category_ids = array_merge( $category_ids, $product->get_category_ids() );
			}
		}

		$category_ids = array_unique( $category_ids );

		// Find complementary products.
		if ( ! empty( $category_ids ) ) {
			$rec_args = array(
				'post_type'      => 'product',
				'posts_per_page' => isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 5,
				'post__not_in'   => $cart_items,
				'tax_query'      => array(
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => $category_ids,
					),
				),
			);

			$rec_products = get_posts( $rec_args );
			foreach ( $rec_products as $rec_post ) {
				$rec_product = wc_get_product( $rec_post->ID );
				if ( $rec_product && $this->filter_product( $rec_product, $arguments ) ) {
					$recommendations[] = $this->format_recommendation( $rec_product, 'cart_complement' );
				}
			}
		}

		return array(
			'success'             => true,
			'recommendation_type' => 'cart_based',
			'cart_items'          => $cart_items,
			'recommendations'     => $recommendations,
			'count'               => count( $recommendations ),
			'message'             => sprintf(
				/* translators: %d: Number of recommendations */
				__( 'Generated %d cart-based recommendations.', 'mcp-ai-wpoos-pro' ),
				count( $recommendations )
			),
		);
	}

	/**
	 * Get frequently bought together products.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function get_frequently_bought_together( $arguments ) {
		$product_id = isset( $arguments['product_id'] ) ? absint( $arguments['product_id'] ) : 0;

		if ( ! $product_id ) {
			return new WP_Error(
				'missing_product_id',
				__( 'Product ID is required for frequently bought together recommendations.', 'mcp-ai-wpoos-pro' )
			);
		}

		global $wpdb;

		// Find products frequently bought with this product.
		$frequently_bought = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					oim2.meta_value as product_id,
					COUNT(*) as frequency
				FROM {$wpdb->prefix}woocommerce_order_items oi1
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim1 ON oi1.order_item_id = oim1.order_item_id
				INNER JOIN {$wpdb->prefix}woocommerce_order_items oi2 ON oi1.order_id = oi2.order_id
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 ON oi2.order_item_id = oim2.order_item_id
				WHERE oim1.meta_key = '_product_id'
				AND oim1.meta_value = %d
				AND oim2.meta_key = '_product_id'
				AND oim2.meta_value != %d
				AND oi1.order_item_id != oi2.order_item_id
				GROUP BY oim2.meta_value
				ORDER BY frequency DESC
				LIMIT 10",
				$product_id,
				$product_id
			)
		);

		$recommendations = array();
		$limit           = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 5;

		foreach ( $frequently_bought as $row ) {
			$rec_product = wc_get_product( absint( $row->product_id ) );
			if ( $rec_product && $this->filter_product( $rec_product, $arguments ) ) {
				$rec               = $this->format_recommendation( $rec_product, 'frequently_bought' );
				$rec['buy_count']  = absint( $row->frequency );
				$recommendations[] = $rec;

				if ( count( $recommendations ) >= $limit ) {
					break;
				}
			}
		}

		return array(
			'success'             => true,
			'recommendation_type' => 'frequently_bought',
			'base_product_id'     => $product_id,
			'recommendations'     => $recommendations,
			'count'               => count( $recommendations ),
			'message'             => sprintf(
				/* translators: %d: Number of recommendations */
				__( 'Found %d frequently bought together products.', 'mcp-ai-wpoos-pro' ),
				count( $recommendations )
			),
		);
	}

	/**
	 * Filter product based on criteria.
	 *
	 * @param WC_Product $product   Product object.
	 * @param array      $arguments Filter arguments.
	 * @return bool True if product passes filters.
	 */
	protected function filter_product( $product, $arguments ) {
		// Check stock status.
		$exclude_out_of_stock = isset( $arguments['exclude_out_of_stock'] ) ? (bool) $arguments['exclude_out_of_stock'] : true;
		if ( $exclude_out_of_stock && ! $product->is_in_stock() ) {
			return false;
		}

		// Check price range.
		$price     = (float) $product->get_price();
		$min_price = isset( $arguments['min_price'] ) ? floatval( $arguments['min_price'] ) : 0;
		$max_price = isset( $arguments['max_price'] ) ? floatval( $arguments['max_price'] ) : 0;

		if ( $min_price > 0 && $price < $min_price ) {
			return false;
		}

		if ( $max_price > 0 && $price > $max_price ) {
			return false;
		}

		return true;
	}

	/**
	 * Format recommendation data.
	 *
	 * @param WC_Product $product Product object.
	 * @param string     $type    Recommendation type.
	 * @return array Formatted recommendation.
	 */
	protected function format_recommendation( $product, $type ) {
		return array(
			'product_id'    => $product->get_id(),
			'product_name'  => $product->get_name(),
			'price'         => $product->get_price(),
			'regular_price' => $product->get_regular_price(),
			'sale_price'    => $product->get_sale_price(),
			'on_sale'       => $product->is_on_sale(),
			'stock_status'  => $product->get_stock_status(),
			'image_url'     => wp_get_attachment_url( $product->get_image_id() ),
			'permalink'     => $product->get_permalink(),
			'type'          => $type,
		);
	}
}
