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
class WP_MCP_AI_Pro_Tool_Woo_Products implements WP_MCP_AI_Core_Tool_Interface, WP_MCP_AI_Core_Tool_Capability_Flags_Interface {

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
		return __( 'Query, create, and manage WooCommerce products. Supports product variations, categories, tags, and attributes.', 'wp-mcp-ai-pro' );
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
					'description' => __( 'The action to perform: get, list, create, update, search.', 'wp-mcp-ai-pro' ),
					'enum'        => array( 'get', 'list', 'create', 'update', 'search' ),
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

		$product->set_status( isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'publish' );

		$product_id = $product->save();

		if ( ! $product_id ) {
			return new WP_Error(
				'create_failed',
				__( 'Failed to create product.', 'wp-mcp-ai-pro' )
			);
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

		$product->save();

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
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'categories'        => $product->get_category_ids(),
			'tags'              => $product->get_tag_ids(),
			'image'             => wp_get_attachment_url( $product->get_image_id() ),
			'permalink'         => get_permalink( $product->get_id() ),
			'date_created'      => $product->get_date_created() ? $product->get_date_created()->format( 'c' ) : null,
			'date_modified'     => $product->get_date_modified() ? $product->get_date_modified()->format( 'c' ) : null,
		);
	}
}
