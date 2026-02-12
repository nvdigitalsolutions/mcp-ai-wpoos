<?php
/**
 * Bulk Update Products Tool
 *
 * Updates multiple WooCommerce products at once with various properties
 * including pricing, stock, categories, and other product attributes.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for bulk updating WooCommerce products.
 *
 * Supports updating:
 * - Pricing (regular, sale)
 * - Stock management
 * - Categories and tags
 * - Status
 * - Featured flag
 * - Custom meta fields
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Bulk_Update_Products implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Bulk product update requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Bulk product update tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'bulk_update_products';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Bulk Update Products', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Update multiple WooCommerce products at once. Supports updating pricing, stock, categories, status, and other product attributes. Use product IDs or query filters to select products to update.', 'mcp-ai-wpoos-pro' );
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
				'product_ids' => array(
					'type'        => 'array',
					'description' => __( 'Array of product IDs to update (required if no filter)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'integer',
					),
				),
				'filter'      => array(
					'type'        => 'object',
					'description' => __( 'Filter criteria to select products (alternative to product_ids)', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'category'     => array(
							'type'        => 'string',
							'description' => 'Filter by category slug',
						),
						'status'       => array(
							'type'        => 'string',
							'description' => 'Filter by status: publish, draft, private',
						),
						'stock_status' => array(
							'type'        => 'string',
							'description' => 'Filter by stock status: instock, outofstock, onbackorder',
						),
						'limit'        => array(
							'type'        => 'integer',
							'description' => 'Maximum number of products to update',
							'default'     => 100,
						),
					),
				),
				'updates'     => array(
					'type'        => 'object',
					'description' => __( 'Updates to apply to selected products (at least one required)', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'regular_price'     => array(
							'type'        => 'number',
							'description' => 'New regular price',
						),
						'sale_price'        => array(
							'type'        => 'number',
							'description' => 'New sale price',
						),
						'price_adjustment'  => array(
							'type'        => 'object',
							'description' => 'Adjust price by percentage or amount',
							'properties'  => array(
								'type'   => array(
									'type' => 'string',
									'enum' => array( 'percentage', 'fixed' ),
								),
								'value'  => array( 'type' => 'number' ),
								'action' => array(
									'type' => 'string',
									'enum' => array( 'increase', 'decrease' ),
								),
							),
						),
						'stock_quantity'    => array(
							'type'        => 'integer',
							'description' => 'New stock quantity',
						),
						'stock_status'      => array(
							'type' => 'string',
							'enum' => array( 'instock', 'outofstock', 'onbackorder' ),
						),
						'manage_stock'      => array(
							'type'        => 'boolean',
							'description' => 'Enable/disable stock management',
						),
						'status'            => array(
							'type' => 'string',
							'enum' => array( 'publish', 'draft', 'private' ),
						),
						'featured'          => array(
							'type'        => 'boolean',
							'description' => 'Mark as featured',
						),
						'add_categories'    => array(
							'type'        => 'array',
							'items'       => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'integer' ) ) ),
							'description' => 'Categories to add',
						),
						'remove_categories' => array(
							'type'        => 'array',
							'items'       => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'integer' ) ) ),
							'description' => 'Categories to remove',
						),
						'add_tags'          => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Tags to add',
						),
						'remove_tags'       => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Tags to remove',
						),
					),
				),
				'dry_run'     => array(
					'type'        => 'boolean',
					'description' => __( 'Preview changes without applying them', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'updates' ),
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
			'database-write',
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
				__( 'You do not have permission to update products.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		// Validate updates.
		if ( empty( $arguments['updates'] ) || ! is_array( $arguments['updates'] ) ) {
			return new WP_Error(
				'missing_updates',
				__( 'Updates object is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get products to update.
		$product_ids = $this->get_products_to_update( $arguments );

		if ( is_wp_error( $product_ids ) ) {
			return $product_ids;
		}

		if ( empty( $product_ids ) ) {
			return new WP_Error(
				'no_products_found',
				__( 'No products found matching the criteria.', 'mcp-ai-wpoos-pro' )
			);
		}

		$dry_run = isset( $arguments['dry_run'] ) && $arguments['dry_run'];
		$updates = $arguments['updates'];

		// Apply updates.
		$results = array(
			'success'          => true,
			'dry_run'          => $dry_run,
			'total_found'      => count( $product_ids ),
			'updated'          => 0,
			'failed'           => 0,
			'updated_products' => array(),
			'errors'           => array(),
		);

		foreach ( $product_ids as $product_id ) {
			$result = $this->update_single_product( $product_id, $updates, $dry_run );

			if ( is_wp_error( $result ) ) {
				++$results['failed'];
				$results['errors'][] = array(
					'product_id' => $product_id,
					'error'      => $result->get_error_message(),
				);
			} else {
				++$results['updated'];
				$results['updated_products'][] = $result;
			}
		}

		$results['message'] = sprintf(
			/* translators: 1: Number of updated products, 2: Number of total products, 3: Dry run indicator */
			__( '%1$d of %2$d products %3$s.', 'mcp-ai-wpoos-pro' ),
			$results['updated'],
			$results['total_found'],
			$dry_run ? __( 'would be updated (dry run)', 'mcp-ai-wpoos-pro' ) : __( 'updated successfully', 'mcp-ai-wpoos-pro' )
		);

		return $results;
	}

	/**
	 * Get products to update based on IDs or filter.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Array of product IDs or error.
	 */
	protected function get_products_to_update( $arguments ) {
		// Use product_ids if provided.
		if ( ! empty( $arguments['product_ids'] ) && is_array( $arguments['product_ids'] ) ) {
			return array_map( 'absint', $arguments['product_ids'] );
		}

		// Use filter if provided.
		if ( ! empty( $arguments['filter'] ) && is_array( $arguments['filter'] ) ) {
			return $this->get_products_by_filter( $arguments['filter'] );
		}

		return new WP_Error(
			'missing_products',
			__( 'Either product_ids or filter is required.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Get products by filter criteria.
	 *
	 * @param array $filter Filter criteria.
	 * @return array Array of product IDs.
	 */
	protected function get_products_by_filter( $filter ) {
		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => isset( $filter['limit'] ) ? absint( $filter['limit'] ) : 100,
			'fields'         => 'ids',
			'post_status'    => isset( $filter['status'] ) ? sanitize_text_field( $filter['status'] ) : 'any',
		);

		// Add category filter.
		if ( ! empty( $filter['category'] ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'slug',
					'terms'    => sanitize_text_field( $filter['category'] ),
				),
			);
		}

		// Add stock status filter.
		if ( ! empty( $filter['stock_status'] ) ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_stock_status',
					'value' => sanitize_text_field( $filter['stock_status'] ),
				),
			);
		}

		$query = new WP_Query( $args );
		return $query->posts;
	}

	/**
	 * Update a single product.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $updates    Updates to apply.
	 * @param bool  $dry_run    Whether this is a dry run.
	 * @return array|WP_Error Product data or error.
	 */
	protected function update_single_product( $product_id, $updates, $dry_run = false ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return new WP_Error(
				'invalid_product',
				sprintf(
					/* translators: %d: Product ID */
					__( 'Product %d not found.', 'mcp-ai-wpoos-pro' ),
					$product_id
				)
			);
		}

		$changes = array();

		// Update pricing.
		if ( isset( $updates['regular_price'] ) ) {
			$new_price = floatval( $updates['regular_price'] );
			if ( ! $dry_run ) {
				$product->set_regular_price( $new_price );
			}
			$changes['regular_price'] = $new_price;
		}

		if ( isset( $updates['sale_price'] ) ) {
			$new_price = floatval( $updates['sale_price'] );
			if ( ! $dry_run ) {
				$product->set_sale_price( $new_price );
			}
			$changes['sale_price'] = $new_price;
		}

		// Apply price adjustment.
		if ( ! empty( $updates['price_adjustment'] ) ) {
			$adjustment    = $updates['price_adjustment'];
			$current_price = $product->get_regular_price();

			if ( $current_price > 0 ) {
				$new_price = $current_price;

				if ( 'percentage' === $adjustment['type'] ) {
					$amount = $current_price * ( floatval( $adjustment['value'] ) / 100 );
				} else {
					$amount = floatval( $adjustment['value'] );
				}

				if ( 'increase' === $adjustment['action'] ) {
					$new_price += $amount;
				} else {
					$new_price -= $amount;
				}

				$new_price = max( 0, $new_price );

				if ( ! $dry_run ) {
					$product->set_regular_price( $new_price );
				}
				$changes['regular_price'] = $new_price;
			}
		}

		// Update stock.
		if ( isset( $updates['stock_quantity'] ) ) {
			if ( ! $dry_run ) {
				$product->set_stock_quantity( absint( $updates['stock_quantity'] ) );
			}
			$changes['stock_quantity'] = absint( $updates['stock_quantity'] );
		}

		if ( isset( $updates['stock_status'] ) ) {
			if ( ! $dry_run ) {
				$product->set_stock_status( sanitize_text_field( $updates['stock_status'] ) );
			}
			$changes['stock_status'] = sanitize_text_field( $updates['stock_status'] );
		}

		if ( isset( $updates['manage_stock'] ) ) {
			if ( ! $dry_run ) {
				$product->set_manage_stock( (bool) $updates['manage_stock'] );
			}
			$changes['manage_stock'] = (bool) $updates['manage_stock'];
		}

		// Update status.
		if ( isset( $updates['status'] ) ) {
			if ( ! $dry_run ) {
				$product->set_status( sanitize_text_field( $updates['status'] ) );
			}
			$changes['status'] = sanitize_text_field( $updates['status'] );
		}

		// Update featured.
		if ( isset( $updates['featured'] ) ) {
			if ( ! $dry_run ) {
				$product->set_featured( (bool) $updates['featured'] );
			}
			$changes['featured'] = (bool) $updates['featured'];
		}

		// Save the product.
		if ( ! $dry_run ) {
			$product->save();

			// Update taxonomy terms.
			$this->update_product_taxonomies( $product_id, $updates );
		}

		return array(
			'product_id' => $product_id,
			'name'       => $product->get_name(),
			'changes'    => $changes,
		);
	}

	/**
	 * Update product taxonomies (categories and tags).
	 *
	 * @param int   $product_id Product ID.
	 * @param array $updates    Updates to apply.
	 * @return void
	 */
	protected function update_product_taxonomies( $product_id, $updates ) {
		// Add categories.
		if ( ! empty( $updates['add_categories'] ) ) {
			$category_ids = $this->process_category_terms( $updates['add_categories'] );
			if ( ! empty( $category_ids ) ) {
				wp_add_object_terms( $product_id, $category_ids, 'product_cat' );
			}
		}

		// Remove categories.
		if ( ! empty( $updates['remove_categories'] ) ) {
			$category_ids = $this->process_category_terms( $updates['remove_categories'] );
			if ( ! empty( $category_ids ) ) {
				wp_remove_object_terms( $product_id, $category_ids, 'product_cat' );
			}
		}

		// Add tags.
		if ( ! empty( $updates['add_tags'] ) ) {
			$tag_names = array_map( 'sanitize_text_field', (array) $updates['add_tags'] );
			wp_add_object_terms( $product_id, $tag_names, 'product_tag' );
		}

		// Remove tags.
		if ( ! empty( $updates['remove_tags'] ) ) {
			$tag_ids = $this->get_tag_ids( $updates['remove_tags'] );
			if ( ! empty( $tag_ids ) ) {
				wp_remove_object_terms( $product_id, $tag_ids, 'product_tag' );
			}
		}
	}

	/**
	 * Process category names/IDs to term IDs.
	 *
	 * @param array $categories Category names or IDs.
	 * @return array Term IDs.
	 */
	protected function process_category_terms( $categories ) {
		$term_ids = array();

		foreach ( $categories as $category ) {
			if ( is_numeric( $category ) ) {
				$term_ids[] = absint( $category );
			} else {
				$term = get_term_by( 'name', $category, 'product_cat' );
				if ( $term ) {
					$term_ids[] = $term->term_id;
				}
			}
		}

		return $term_ids;
	}

	/**
	 * Get tag IDs from tag names.
	 *
	 * @param array $tag_names Tag names.
	 * @return array Tag IDs.
	 */
	protected function get_tag_ids( $tag_names ) {
		$tag_ids = array();

		foreach ( $tag_names as $tag_name ) {
			$term = get_term_by( 'name', sanitize_text_field( $tag_name ), 'product_tag' );
			if ( $term ) {
				$tag_ids[] = $term->term_id;
			}
		}

		return $tag_ids;
	}
}
