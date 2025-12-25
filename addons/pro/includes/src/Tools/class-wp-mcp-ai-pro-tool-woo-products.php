<?php
/**
 * WooCommerce Products Tool - Pro add-on tool for WooCommerce product operations.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for WooCommerce product operations.
 *
 * Provides CRUD operations for WooCommerce products including:
 * - Listing products
 * - Getting product details
 * - Creating products
 * - Updating products
 *
 * Requires WooCommerce plugin to be active.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Woo_Products implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'WooCommerce Products tool requires WooCommerce to be installed and activated.', 'wp-mcp-ai-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'woo_products';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'WooCommerce Products', 'wp-mcp-ai-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Comprehensive WooCommerce product management with full CRUD operations. Supports products, variations, categories, tags, attributes, images, and inventory management.', 'wp-mcp-ai-pro' );
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
				'action'            => array(
					'type'        => 'string',
					'description' => __( 'The action to perform: get, list, create, update, search, delete, manage_categories, manage_tags, manage_attributes.', 'wp-mcp-ai-pro' ),
					'enum'        => array( 'get', 'list', 'create', 'update', 'search', 'delete', 'manage_categories', 'manage_tags', 'manage_attributes' ),
					'default'     => 'list',
				),
				'product_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Product ID for get or update actions.', 'wp-mcp-ai-pro' ),
				),
				'per_page'          => array(
					'type'        => 'integer',
					'description' => __( 'Number of products to return. Default: 10. Max: 100.', 'wp-mcp-ai-pro' ),
					'default'     => 10,
					'maximum'     => 100,
				),
				'page'              => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default: 1.', 'wp-mcp-ai-pro' ),
					'default'     => 1,
				),
				'search'            => array(
					'type'        => 'string',
					'description' => __( 'Search term to filter products.', 'wp-mcp-ai-pro' ),
				),
				'category'          => array(
					'type'        => 'string',
					'description' => __( 'Filter by product category slug.', 'wp-mcp-ai-pro' ),
				),
				'status'            => array(
					'type'        => 'string',
					'description' => __( 'Filter by product status. Default: publish.', 'wp-mcp-ai-pro' ),
					'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
					'default'     => 'publish',
				),
				'type'              => array(
					'type'        => 'string',
					'description' => __( 'Filter by product type.', 'wp-mcp-ai-pro' ),
					'enum'        => array( 'simple', 'variable', 'grouped', 'external' ),
				),
				'name'              => array(
					'type'        => 'string',
					'description' => __( 'Product name for create/update.', 'wp-mcp-ai-pro' ),
				),
				'sku'               => array(
					'type'        => 'string',
					'description' => __( 'Product SKU.', 'wp-mcp-ai-pro' ),
				),
				'price'             => array(
					'type'        => 'string',
					'description' => __( 'Product regular price.', 'wp-mcp-ai-pro' ),
				),
				'sale_price'        => array(
					'type'        => 'string',
					'description' => __( 'Product sale price.', 'wp-mcp-ai-pro' ),
				),
				'description'       => array(
					'type'        => 'string',
					'description' => __( 'Product description.', 'wp-mcp-ai-pro' ),
				),
				'short_description' => array(
					'type'        => 'string',
					'description' => __( 'Product short description.', 'wp-mcp-ai-pro' ),
				),
				'stock_quantity'    => array(
					'type'        => 'integer',
					'description' => __( 'Stock quantity.', 'wp-mcp-ai-pro' ),
				),
				'stock_status'      => array(
					'type'        => 'string',
					'description' => __( 'Stock status.', 'wp-mcp-ai-pro' ),
					'enum'        => array( 'instock', 'outofstock', 'onbackorder' ),
				),
				'categories'        => array(
					'type'        => 'array',
					'description' => __( 'Array of category IDs or slugs to assign to the product.', 'wp-mcp-ai-pro' ),
					'items'       => array(
						'oneOf' => array(
							array( 'type' => 'integer' ),
							array( 'type' => 'string' ),
						),
					),
				),
				'tags'              => array(
					'type'        => 'array',
					'description' => __( 'Array of tag IDs or names to assign to the product.', 'wp-mcp-ai-pro' ),
					'items'       => array(
						'oneOf' => array(
							array( 'type' => 'integer' ),
							array( 'type' => 'string' ),
						),
					),
				),
				'images'            => array(
					'type'        => 'array',
					'description' => __( 'Array of image URLs or attachment IDs for product gallery.', 'wp-mcp-ai-pro' ),
					'items'       => array(
						'oneOf' => array(
							array( 'type' => 'integer' ),
							array( 'type' => 'string' ),
						),
					),
				),
				'featured_image'    => array(
					'oneOf'       => array(
						array( 'type' => 'integer' ),
						array( 'type' => 'string' ),
					),
					'description' => __( 'Featured image URL or attachment ID.', 'wp-mcp-ai-pro' ),
				),
				'attributes'        => array(
					'type'        => 'array',
					'description' => __( 'Array of product attributes. Each attribute should have name, visible, variation, and options.', 'wp-mcp-ai-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'      => array( 'type' => 'string' ),
							'visible'   => array( 'type' => 'boolean' ),
							'variation' => array( 'type' => 'boolean' ),
							'options'   => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
				),
				'weight'            => array(
					'type'        => 'string',
					'description' => __( 'Product weight.', 'wp-mcp-ai-pro' ),
				),
				'dimensions'        => array(
					'type'        => 'object',
					'description' => __( 'Product dimensions (length, width, height).', 'wp-mcp-ai-pro' ),
					'properties'  => array(
						'length' => array( 'type' => 'string' ),
						'width'  => array( 'type' => 'string' ),
						'height' => array( 'type' => 'string' ),
					),
				),
				'virtual'           => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the product is virtual.', 'wp-mcp-ai-pro' ),
				),
				'downloadable'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the product is downloadable.', 'wp-mcp-ai-pro' ),
				),
				'manage_stock'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to enable stock management.', 'wp-mcp-ai-pro' ),
				),
				'backorders'        => array(
					'type'        => 'string',
					'description' => __( 'Backorders setting.', 'wp-mcp-ai-pro' ),
					'enum'        => array( 'no', 'notify', 'yes' ),
				),
			),
			'required'   => array(),
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
			'read-only',        // list/get/search operations.
			'write',            // create/update operations.
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
				__( 'WooCommerce is not installed or activated.', 'wp-mcp-ai-pro' )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list';

		switch ( $action ) {
			case 'get':
				return $this->get_product( $arguments );
			case 'list':
				return $this->list_products( $arguments );
			case 'create':
				return $this->create_product( $arguments, $context );
			case 'update':
				return $this->update_product( $arguments, $context );
			case 'search':
				return $this->search_products( $arguments );
			case 'delete':
				return $this->delete_product( $arguments, $context );
			case 'manage_categories':
				return $this->manage_categories( $arguments, $context );
			case 'manage_tags':
				return $this->manage_tags( $arguments, $context );
			case 'manage_attributes':
				return $this->manage_attributes( $arguments, $context );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'wp-mcp-ai-pro' )
				);
		}
	}

	/**
	 * Get a single product by ID.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function get_product( $arguments ) {
		if ( empty( $arguments['product_id'] ) ) {
			return new WP_Error(
				'missing_product_id',
				__( 'Product ID is required for get action.', 'wp-mcp-ai-pro' )
			);
		}

		$product = wc_get_product( absint( $arguments['product_id'] ) );

		if ( ! $product ) {
			return new WP_Error(
				'product_not_found',
				__( 'Product not found.', 'wp-mcp-ai-pro' )
			);
		}

		return $this->format_product( $product );
	}

	/**
	 * List products.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function list_products( $arguments ) {
		$per_page = isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 10;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$query_args = array(
			'limit'    => $per_page,
			'page'     => $page,
			'status'   => isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'publish',
			'orderby'  => 'date',
			'order'    => 'DESC',
			'paginate' => true,
		);

		if ( ! empty( $arguments['type'] ) ) {
			$query_args['type'] = sanitize_key( $arguments['type'] );
		}

		if ( ! empty( $arguments['category'] ) ) {
			$query_args['category'] = array( sanitize_text_field( $arguments['category'] ) );
		}

		if ( ! empty( $arguments['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $arguments['search'] );
		}

		$results = wc_get_products( $query_args );

		$products = array();
		foreach ( $results->products as $product ) {
			$products[] = $this->format_product( $product );
		}

		return array(
			'products'    => $products,
			'total'       => $results->total,
			'total_pages' => $results->max_num_pages,
			'page'        => $page,
		);
	}

	/**
	 * Search products.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function search_products( $arguments ) {
		if ( empty( $arguments['search'] ) ) {
			return new WP_Error(
				'missing_search_term',
				__( 'Search term is required for search action.', 'wp-mcp-ai-pro' )
			);
		}

		return $this->list_products( $arguments );
	}

	/**
	 * Create a new product.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function create_product( $arguments, $context ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'publish_products' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to create products.', 'wp-mcp-ai-pro' )
			);
		}

		$product = new WC_Product_Simple();

		if ( ! empty( $arguments['name'] ) ) {
			$product->set_name( sanitize_text_field( $arguments['name'] ) );
		}

		if ( ! empty( $arguments['sku'] ) ) {
			$product->set_sku( sanitize_text_field( $arguments['sku'] ) );
		}

		if ( ! empty( $arguments['price'] ) ) {
			$product->set_regular_price( wc_format_decimal( $arguments['price'] ) );
		}

		if ( ! empty( $arguments['sale_price'] ) ) {
			$product->set_sale_price( wc_format_decimal( $arguments['sale_price'] ) );
		}

		if ( ! empty( $arguments['description'] ) ) {
			$product->set_description( wp_kses_post( $arguments['description'] ) );
		}

		if ( ! empty( $arguments['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $arguments['short_description'] ) );
		}

		if ( isset( $arguments['stock_quantity'] ) ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( absint( $arguments['stock_quantity'] ) );
		}

		if ( ! empty( $arguments['stock_status'] ) ) {
			$product->set_stock_status( sanitize_key( $arguments['stock_status'] ) );
		}

		if ( isset( $arguments['weight'] ) ) {
			$product->set_weight( wc_format_decimal( $arguments['weight'] ) );
		}

		if ( ! empty( $arguments['dimensions'] ) && is_array( $arguments['dimensions'] ) ) {
			if ( isset( $arguments['dimensions']['length'] ) ) {
				$product->set_length( wc_format_decimal( $arguments['dimensions']['length'] ) );
			}
			if ( isset( $arguments['dimensions']['width'] ) ) {
				$product->set_width( wc_format_decimal( $arguments['dimensions']['width'] ) );
			}
			if ( isset( $arguments['dimensions']['height'] ) ) {
				$product->set_height( wc_format_decimal( $arguments['dimensions']['height'] ) );
			}
		}

		if ( isset( $arguments['virtual'] ) ) {
			$product->set_virtual( (bool) $arguments['virtual'] );
		}

		if ( isset( $arguments['downloadable'] ) ) {
			$product->set_downloadable( (bool) $arguments['downloadable'] );
		}

		if ( isset( $arguments['manage_stock'] ) ) {
			$product->set_manage_stock( (bool) $arguments['manage_stock'] );
		}

		if ( ! empty( $arguments['backorders'] ) ) {
			$product->set_backorders( sanitize_key( $arguments['backorders'] ) );
		}

		$product->set_status( isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'publish' );

		// Apply categories, tags, and attributes before saving.
		if ( ! empty( $arguments['categories'] ) ) {
			$this->apply_categories( $product, $arguments['categories'] );
		}

		if ( ! empty( $arguments['tags'] ) ) {
			$this->apply_tags( $product, $arguments['tags'] );
		}

		if ( ! empty( $arguments['attributes'] ) ) {
			$this->apply_attributes( $product, $arguments['attributes'] );
		}

		$product_id = $product->save();

		if ( ! $product_id ) {
			return new WP_Error(
				'create_failed',
				__( 'Failed to create product.', 'wp-mcp-ai-pro' )
			);
		}

		// Apply images after product is saved (requires product ID).
		$featured_image = isset( $arguments['featured_image'] ) ? $arguments['featured_image'] : null;
		$images         = isset( $arguments['images'] ) ? $arguments['images'] : array();

		if ( ! empty( $featured_image ) || ! empty( $images ) ) {
			$product = wc_get_product( $product_id );
			$this->apply_images( $product, $featured_image, $images );
			$product->save();
		}

		return $this->format_product( wc_get_product( $product_id ) );
	}

	/**
	 * Update an existing product.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function update_product( $arguments, $context ) {
		if ( empty( $arguments['product_id'] ) ) {
			return new WP_Error(
				'missing_product_id',
				__( 'Product ID is required for update action.', 'wp-mcp-ai-pro' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'edit_products' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to edit products.', 'wp-mcp-ai-pro' )
			);
		}

		$product = wc_get_product( absint( $arguments['product_id'] ) );

		if ( ! $product ) {
			return new WP_Error(
				'product_not_found',
				__( 'Product not found.', 'wp-mcp-ai-pro' )
			);
		}

		if ( ! empty( $arguments['name'] ) ) {
			$product->set_name( sanitize_text_field( $arguments['name'] ) );
		}

		if ( ! empty( $arguments['sku'] ) ) {
			$product->set_sku( sanitize_text_field( $arguments['sku'] ) );
		}

		if ( ! empty( $arguments['price'] ) ) {
			$product->set_regular_price( wc_format_decimal( $arguments['price'] ) );
		}

		if ( ! empty( $arguments['sale_price'] ) ) {
			$product->set_sale_price( wc_format_decimal( $arguments['sale_price'] ) );
		}

		if ( ! empty( $arguments['description'] ) ) {
			$product->set_description( wp_kses_post( $arguments['description'] ) );
		}

		if ( ! empty( $arguments['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $arguments['short_description'] ) );
		}

		if ( isset( $arguments['stock_quantity'] ) ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( absint( $arguments['stock_quantity'] ) );
		}

		if ( ! empty( $arguments['stock_status'] ) ) {
			$product->set_stock_status( sanitize_key( $arguments['stock_status'] ) );
		}

		if ( ! empty( $arguments['status'] ) ) {
			$product->set_status( sanitize_key( $arguments['status'] ) );
		}

		if ( isset( $arguments['weight'] ) ) {
			$product->set_weight( wc_format_decimal( $arguments['weight'] ) );
		}

		if ( ! empty( $arguments['dimensions'] ) && is_array( $arguments['dimensions'] ) ) {
			if ( isset( $arguments['dimensions']['length'] ) ) {
				$product->set_length( wc_format_decimal( $arguments['dimensions']['length'] ) );
			}
			if ( isset( $arguments['dimensions']['width'] ) ) {
				$product->set_width( wc_format_decimal( $arguments['dimensions']['width'] ) );
			}
			if ( isset( $arguments['dimensions']['height'] ) ) {
				$product->set_height( wc_format_decimal( $arguments['dimensions']['height'] ) );
			}
		}

		if ( isset( $arguments['virtual'] ) ) {
			$product->set_virtual( (bool) $arguments['virtual'] );
		}

		if ( isset( $arguments['downloadable'] ) ) {
			$product->set_downloadable( (bool) $arguments['downloadable'] );
		}

		if ( isset( $arguments['manage_stock'] ) ) {
			$product->set_manage_stock( (bool) $arguments['manage_stock'] );
		}

		if ( ! empty( $arguments['backorders'] ) ) {
			$product->set_backorders( sanitize_key( $arguments['backorders'] ) );
		}

		// Apply categories, tags, and attributes before saving.
		if ( ! empty( $arguments['categories'] ) ) {
			$this->apply_categories( $product, $arguments['categories'] );
		}

		if ( ! empty( $arguments['tags'] ) ) {
			$this->apply_tags( $product, $arguments['tags'] );
		}

		if ( ! empty( $arguments['attributes'] ) ) {
			$this->apply_attributes( $product, $arguments['attributes'] );
		}

		$product->save();

		// Apply images after product is saved.
		$featured_image = isset( $arguments['featured_image'] ) ? $arguments['featured_image'] : null;
		$images         = isset( $arguments['images'] ) ? $arguments['images'] : array();

		if ( ! empty( $featured_image ) || ! empty( $images ) ) {
			$this->apply_images( $product, $featured_image, $images );
			$product->save();
		}

		return $this->format_product( $product );
	}

	/**
	 * Format a product for output.
	 *
	 * @param WC_Product $product Product object.
	 * @return array
	 */
	protected function format_product( $product ) {
		return array(
			'id'                => $product->get_id(),
			'name'              => $product->get_name(),
			'slug'              => $product->get_slug(),
			'type'              => $product->get_type(),
			'status'            => $product->get_status(),
			'sku'               => $product->get_sku(),
			'price'             => $product->get_price(),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'on_sale'           => $product->is_on_sale(),
			'stock_status'      => $product->get_stock_status(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'manage_stock'      => $product->get_manage_stock(),
			'backorders'        => $product->get_backorders(),
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'categories'        => $product->get_category_ids(),
			'tags'              => $product->get_tag_ids(),
			'image'             => wp_get_attachment_url( $product->get_image_id() ),
			'gallery_images'    => array_map( 'wp_get_attachment_url', $product->get_gallery_image_ids() ),
			'attributes'        => $product->get_attributes(),
			'weight'            => $product->get_weight(),
			'dimensions'        => $product->get_dimensions( false ),
			'virtual'           => $product->get_virtual(),
			'downloadable'      => $product->get_downloadable(),
			'permalink'         => get_permalink( $product->get_id() ),
			'date_created'      => $product->get_date_created() ? $product->get_date_created()->format( 'c' ) : null,
			'date_modified'     => $product->get_date_modified() ? $product->get_date_modified()->format( 'c' ) : null,
		);
	}

	/**
	 * Delete a product.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function delete_product( $arguments, $context ) {
		if ( empty( $arguments['product_id'] ) ) {
			return new WP_Error(
				'missing_product_id',
				__( 'Product ID is required for delete action.', 'wp-mcp-ai-pro' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'delete_products' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to delete products.', 'wp-mcp-ai-pro' )
			);
		}

		$product = wc_get_product( absint( $arguments['product_id'] ) );

		if ( ! $product ) {
			return new WP_Error(
				'product_not_found',
				__( 'Product not found.', 'wp-mcp-ai-pro' )
			);
		}

		$product_name = $product->get_name();
		$product_id   = $product->get_id();

		// Force delete or move to trash based on argument.
		$force   = isset( $arguments['force'] ) && $arguments['force'];
		$deleted = $product->delete( $force );

		if ( ! $deleted ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete product.', 'wp-mcp-ai-pro' )
			);
		}

		return array(
			'success'    => true,
			'product_id' => $product_id,
			'name'       => $product_name,
			'message'    => $force
				? __( 'Product permanently deleted.', 'wp-mcp-ai-pro' )
				: __( 'Product moved to trash.', 'wp-mcp-ai-pro' ),
		);
	}

	/**
	 * Manage product categories (create, update, delete, list).
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function manage_categories( $arguments, $context ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'manage_product_terms' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to manage product categories.', 'wp-mcp-ai-pro' )
			);
		}

		$operation = isset( $arguments['operation'] ) ? sanitize_key( $arguments['operation'] ) : 'list';

		switch ( $operation ) {
			case 'create':
				return $this->create_category( $arguments );
			case 'update':
				return $this->update_category( $arguments );
			case 'delete':
				return $this->delete_category( $arguments );
			case 'list':
			default:
				return $this->list_categories( $arguments );
		}
	}

	/**
	 * Create a product category.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function create_category( $arguments ) {
		if ( empty( $arguments['name'] ) ) {
			return new WP_Error(
				'missing_name',
				__( 'Category name is required.', 'wp-mcp-ai-pro' )
			);
		}

		$args = array(
			'description' => isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '',
			'parent'      => isset( $arguments['parent'] ) ? absint( $arguments['parent'] ) : 0,
			'slug'        => isset( $arguments['slug'] ) ? sanitize_title( $arguments['slug'] ) : '',
		);

		$result = wp_insert_term( sanitize_text_field( $arguments['name'] ), 'product_cat', $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term = get_term( $result['term_id'], 'product_cat' );

		return array(
			'success'  => true,
			'category' => array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent'      => $term->parent,
				'count'       => $term->count,
			),
		);
	}

	/**
	 * Update a product category.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function update_category( $arguments ) {
		if ( empty( $arguments['category_id'] ) ) {
			return new WP_Error(
				'missing_category_id',
				__( 'Category ID is required for update.', 'wp-mcp-ai-pro' )
			);
		}

		$args = array();

		if ( isset( $arguments['name'] ) ) {
			$args['name'] = sanitize_text_field( $arguments['name'] );
		}

		if ( isset( $arguments['description'] ) ) {
			$args['description'] = sanitize_textarea_field( $arguments['description'] );
		}

		if ( isset( $arguments['slug'] ) ) {
			$args['slug'] = sanitize_title( $arguments['slug'] );
		}

		if ( isset( $arguments['parent'] ) ) {
			$args['parent'] = absint( $arguments['parent'] );
		}

		$result = wp_update_term( absint( $arguments['category_id'] ), 'product_cat', $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term = get_term( $result['term_id'], 'product_cat' );

		return array(
			'success'  => true,
			'category' => array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent'      => $term->parent,
				'count'       => $term->count,
			),
		);
	}

	/**
	 * Delete a product category.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function delete_category( $arguments ) {
		if ( empty( $arguments['category_id'] ) ) {
			return new WP_Error(
				'missing_category_id',
				__( 'Category ID is required for delete.', 'wp-mcp-ai-pro' )
			);
		}

		$term = get_term( absint( $arguments['category_id'] ), 'product_cat' );

		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error(
				'category_not_found',
				__( 'Category not found.', 'wp-mcp-ai-pro' )
			);
		}

		$result = wp_delete_term( $term->term_id, 'product_cat' );

		if ( is_wp_error( $result ) || ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete category.', 'wp-mcp-ai-pro' )
			);
		}

		return array(
			'success'     => true,
			'category_id' => $term->term_id,
			'message'     => __( 'Category deleted successfully.', 'wp-mcp-ai-pro' ),
		);
	}

	/**
	 * List product categories.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function list_categories( $arguments ) {
		$args = array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => isset( $arguments['hide_empty'] ) ? (bool) $arguments['hide_empty'] : false,
			'number'     => isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 50,
		);

		if ( isset( $arguments['parent'] ) ) {
			$args['parent'] = absint( $arguments['parent'] );
		}

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$categories = array();
		foreach ( $terms as $term ) {
			$categories[] = array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent'      => $term->parent,
				'count'       => $term->count,
			);
		}

		return array(
			'success'    => true,
			'categories' => $categories,
			'count'      => count( $categories ),
		);
	}

	/**
	 * Manage product tags (create, update, delete, list).
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function manage_tags( $arguments, $context ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'manage_product_terms' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to manage product tags.', 'wp-mcp-ai-pro' )
			);
		}

		$operation = isset( $arguments['operation'] ) ? sanitize_key( $arguments['operation'] ) : 'list';

		switch ( $operation ) {
			case 'create':
				return $this->create_tag( $arguments );
			case 'update':
				return $this->update_tag( $arguments );
			case 'delete':
				return $this->delete_tag( $arguments );
			case 'list':
			default:
				return $this->list_tags( $arguments );
		}
	}

	/**
	 * Create a product tag.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function create_tag( $arguments ) {
		if ( empty( $arguments['name'] ) ) {
			return new WP_Error(
				'missing_name',
				__( 'Tag name is required.', 'wp-mcp-ai-pro' )
			);
		}

		$args = array(
			'description' => isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '',
			'slug'        => isset( $arguments['slug'] ) ? sanitize_title( $arguments['slug'] ) : '',
		);

		$result = wp_insert_term( sanitize_text_field( $arguments['name'] ), 'product_tag', $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term = get_term( $result['term_id'], 'product_tag' );

		return array(
			'success' => true,
			'tag'     => array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'count'       => $term->count,
			),
		);
	}

	/**
	 * Update a product tag.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function update_tag( $arguments ) {
		if ( empty( $arguments['tag_id'] ) ) {
			return new WP_Error(
				'missing_tag_id',
				__( 'Tag ID is required for update.', 'wp-mcp-ai-pro' )
			);
		}

		$args = array();

		if ( isset( $arguments['name'] ) ) {
			$args['name'] = sanitize_text_field( $arguments['name'] );
		}

		if ( isset( $arguments['description'] ) ) {
			$args['description'] = sanitize_textarea_field( $arguments['description'] );
		}

		if ( isset( $arguments['slug'] ) ) {
			$args['slug'] = sanitize_title( $arguments['slug'] );
		}

		$result = wp_update_term( absint( $arguments['tag_id'] ), 'product_tag', $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term = get_term( $result['term_id'], 'product_tag' );

		return array(
			'success' => true,
			'tag'     => array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'count'       => $term->count,
			),
		);
	}

	/**
	 * Delete a product tag.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function delete_tag( $arguments ) {
		if ( empty( $arguments['tag_id'] ) ) {
			return new WP_Error(
				'missing_tag_id',
				__( 'Tag ID is required for delete.', 'wp-mcp-ai-pro' )
			);
		}

		$term = get_term( absint( $arguments['tag_id'] ), 'product_tag' );

		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error(
				'tag_not_found',
				__( 'Tag not found.', 'wp-mcp-ai-pro' )
			);
		}

		$result = wp_delete_term( $term->term_id, 'product_tag' );

		if ( is_wp_error( $result ) || ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete tag.', 'wp-mcp-ai-pro' )
			);
		}

		return array(
			'success' => true,
			'tag_id'  => $term->term_id,
			'message' => __( 'Tag deleted successfully.', 'wp-mcp-ai-pro' ),
		);
	}

	/**
	 * List product tags.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function list_tags( $arguments ) {
		$args = array(
			'taxonomy'   => 'product_tag',
			'hide_empty' => isset( $arguments['hide_empty'] ) ? (bool) $arguments['hide_empty'] : false,
			'number'     => isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 50,
		);

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$tags = array();
		foreach ( $terms as $term ) {
			$tags[] = array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'count'       => $term->count,
			);
		}

		return array(
			'success' => true,
			'tags'    => $tags,
			'count'   => count( $tags ),
		);
	}

	/**
	 * Manage product attributes (create, update, delete, list).
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function manage_attributes( $arguments, $context ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'manage_product_terms' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to manage product attributes.', 'wp-mcp-ai-pro' )
			);
		}

		$operation = isset( $arguments['operation'] ) ? sanitize_key( $arguments['operation'] ) : 'list';

		switch ( $operation ) {
			case 'create':
				return $this->create_attribute( $arguments );
			case 'update':
				return $this->update_attribute( $arguments );
			case 'delete':
				return $this->delete_attribute( $arguments );
			case 'list':
			default:
				return $this->list_attributes( $arguments );
		}
	}

	/**
	 * Create a product attribute.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function create_attribute( $arguments ) {
		if ( empty( $arguments['name'] ) ) {
			return new WP_Error(
				'missing_name',
				__( 'Attribute name is required.', 'wp-mcp-ai-pro' )
			);
		}

		$attribute = array(
			'name'         => sanitize_text_field( $arguments['name'] ),
			'slug'         => isset( $arguments['slug'] ) ? sanitize_title( $arguments['slug'] ) : wc_sanitize_taxonomy_name( $arguments['name'] ),
			'type'         => isset( $arguments['type'] ) ? sanitize_text_field( $arguments['type'] ) : 'select',
			'order_by'     => isset( $arguments['order_by'] ) ? sanitize_text_field( $arguments['order_by'] ) : 'menu_order',
			'has_archives' => isset( $arguments['has_archives'] ) ? (bool) $arguments['has_archives'] : false,
		);

		$id = wc_create_attribute( $attribute );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$created = wc_get_attribute( $id );

		return array(
			'success'   => true,
			'attribute' => array(
				'id'           => $created->id,
				'name'         => $created->name,
				'slug'         => $created->slug,
				'type'         => $created->type,
				'order_by'     => $created->order_by,
				'has_archives' => $created->has_archives,
			),
		);
	}

	/**
	 * Update a product attribute.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function update_attribute( $arguments ) {
		if ( empty( $arguments['attribute_id'] ) ) {
			return new WP_Error(
				'missing_attribute_id',
				__( 'Attribute ID is required for update.', 'wp-mcp-ai-pro' )
			);
		}

		$attribute_id = absint( $arguments['attribute_id'] );
		$attribute    = wc_get_attribute( $attribute_id );

		if ( ! $attribute ) {
			return new WP_Error(
				'attribute_not_found',
				__( 'Attribute not found.', 'wp-mcp-ai-pro' )
			);
		}

		$update_data = array();

		if ( isset( $arguments['name'] ) ) {
			$update_data['name'] = sanitize_text_field( $arguments['name'] );
		}

		if ( isset( $arguments['slug'] ) ) {
			$update_data['slug'] = sanitize_title( $arguments['slug'] );
		}

		if ( isset( $arguments['type'] ) ) {
			$update_data['type'] = sanitize_text_field( $arguments['type'] );
		}

		if ( isset( $arguments['order_by'] ) ) {
			$update_data['order_by'] = sanitize_text_field( $arguments['order_by'] );
		}

		if ( isset( $arguments['has_archives'] ) ) {
			$update_data['has_archives'] = (bool) $arguments['has_archives'];
		}

		$result = wc_update_attribute( $attribute_id, $update_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = wc_get_attribute( $attribute_id );

		return array(
			'success'   => true,
			'attribute' => array(
				'id'           => $updated->id,
				'name'         => $updated->name,
				'slug'         => $updated->slug,
				'type'         => $updated->type,
				'order_by'     => $updated->order_by,
				'has_archives' => $updated->has_archives,
			),
		);
	}

	/**
	 * Delete a product attribute.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function delete_attribute( $arguments ) {
		if ( empty( $arguments['attribute_id'] ) ) {
			return new WP_Error(
				'missing_attribute_id',
				__( 'Attribute ID is required for delete.', 'wp-mcp-ai-pro' )
			);
		}

		$attribute_id = absint( $arguments['attribute_id'] );
		$attribute    = wc_get_attribute( $attribute_id );

		if ( ! $attribute ) {
			return new WP_Error(
				'attribute_not_found',
				__( 'Attribute not found.', 'wp-mcp-ai-pro' )
			);
		}

		$result = wc_delete_attribute( $attribute_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'      => true,
			'attribute_id' => $attribute_id,
			'message'      => __( 'Attribute deleted successfully.', 'wp-mcp-ai-pro' ),
		);
	}

	/**
	 * List product attributes.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function list_attributes( $arguments ) {
		$attributes = wc_get_attribute_taxonomies();

		$result = array();
		foreach ( $attributes as $attribute ) {
			$result[] = array(
				'id'           => $attribute->attribute_id,
				'name'         => $attribute->attribute_label,
				'slug'         => $attribute->attribute_name,
				'type'         => $attribute->attribute_type,
				'order_by'     => $attribute->attribute_orderby,
				'has_archives' => (bool) $attribute->attribute_public,
			);
		}

		return array(
			'success'    => true,
			'attributes' => $result,
			'count'      => count( $result ),
		);
	}

	/**
	 * Apply categories to a product.
	 *
	 * @param WC_Product $product Product object.
	 * @param array      $categories Array of category IDs or slugs.
	 * @return void
	 */
	protected function apply_categories( $product, $categories ) {
		if ( empty( $categories ) || ! is_array( $categories ) ) {
			return;
		}

		$category_ids = array();

		foreach ( $categories as $category ) {
			if ( is_numeric( $category ) ) {
				$category_ids[] = absint( $category );
			} else {
				$term = get_term_by( 'slug', sanitize_text_field( $category ), 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$category_ids[] = $term->term_id;
				}
			}
		}

		if ( ! empty( $category_ids ) ) {
			$product->set_category_ids( $category_ids );
		}
	}

	/**
	 * Apply tags to a product.
	 *
	 * @param WC_Product $product Product object.
	 * @param array      $tags Array of tag IDs or names.
	 * @return void
	 */
	protected function apply_tags( $product, $tags ) {
		if ( empty( $tags ) || ! is_array( $tags ) ) {
			return;
		}

		$tag_ids = array();

		foreach ( $tags as $tag ) {
			if ( is_numeric( $tag ) ) {
				$tag_ids[] = absint( $tag );
			} else {
				$term = get_term_by( 'name', sanitize_text_field( $tag ), 'product_tag' );
				if ( ! $term || is_wp_error( $term ) ) {
					// Create the tag if it doesn't exist.
					$result = wp_insert_term( sanitize_text_field( $tag ), 'product_tag' );
					if ( ! is_wp_error( $result ) ) {
						$tag_ids[] = $result['term_id'];
					}
				} else {
					$tag_ids[] = $term->term_id;
				}
			}
		}

		if ( ! empty( $tag_ids ) ) {
			$product->set_tag_ids( $tag_ids );
		}
	}

	/**
	 * Apply images to a product.
	 *
	 * @param WC_Product $product Product object.
	 * @param mixed      $featured_image Featured image URL or attachment ID.
	 * @param array      $images Array of image URLs or attachment IDs for gallery.
	 * @return void
	 */
	protected function apply_images( $product, $featured_image = null, $images = array() ) {
		// Handle featured image.
		if ( ! empty( $featured_image ) ) {
			if ( is_numeric( $featured_image ) ) {
				$product->set_image_id( absint( $featured_image ) );
			} elseif ( is_string( $featured_image ) && filter_var( $featured_image, FILTER_VALIDATE_URL ) ) {
				$attachment_id = $this->sideload_image( $featured_image, $product->get_id() );
				if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
					$product->set_image_id( $attachment_id );
				}
			}
		}

		// Handle gallery images.
		if ( ! empty( $images ) && is_array( $images ) ) {
			$gallery_ids = array();

			foreach ( $images as $image ) {
				if ( is_numeric( $image ) ) {
					$gallery_ids[] = absint( $image );
				} elseif ( is_string( $image ) && filter_var( $image, FILTER_VALIDATE_URL ) ) {
					$attachment_id = $this->sideload_image( $image, $product->get_id() );
					if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
						$gallery_ids[] = $attachment_id;
					}
				}
			}

			if ( ! empty( $gallery_ids ) ) {
				$product->set_gallery_image_ids( $gallery_ids );
			}
		}
	}

	/**
	 * Sideload an image from a URL.
	 *
	 * @param string $url Image URL.
	 * @param int    $post_id Post ID to attach the image to.
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	protected function sideload_image( $url, $post_id ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $url, $post_id, null, 'id' );

		return $attachment_id;
	}

	/**
	 * Apply attributes to a product.
	 *
	 * @param WC_Product $product Product object.
	 * @param array      $attributes Array of attribute data.
	 * @return void
	 */
	protected function apply_attributes( $product, $attributes ) {
		if ( empty( $attributes ) || ! is_array( $attributes ) ) {
			return;
		}

		$product_attributes = array();

		foreach ( $attributes as $attribute_data ) {
			if ( empty( $attribute_data['name'] ) ) {
				continue;
			}

			$attribute = new WC_Product_Attribute();
			$attribute->set_name( sanitize_text_field( $attribute_data['name'] ) );
			$attribute->set_visible( isset( $attribute_data['visible'] ) ? (bool) $attribute_data['visible'] : true );
			$attribute->set_variation( isset( $attribute_data['variation'] ) ? (bool) $attribute_data['variation'] : false );

			if ( ! empty( $attribute_data['options'] ) && is_array( $attribute_data['options'] ) ) {
				$options = array_map( 'sanitize_text_field', $attribute_data['options'] );
				$attribute->set_options( $options );
			}

			$product_attributes[] = $attribute;
		}

		if ( ! empty( $product_attributes ) ) {
			$product->set_attributes( $product_attributes );
		}
	}
}
