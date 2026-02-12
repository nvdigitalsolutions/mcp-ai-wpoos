<?php
/**
 * Advanced Product Creation Tool
 *
 * Creates WooCommerce products with comprehensive meta data including
 * variations, attributes, galleries, and all product settings.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for creating advanced WooCommerce products.
 *
 * Supports:
 * - Simple and variable products
 * - Product attributes and variations
 * - Image galleries
 * - Stock management
 * - Pricing (regular, sale)
 * - Categories and tags
 * - Product meta data
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Create_Product_Advanced implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Advanced product creation requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Advanced product creation tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'create_product_advanced';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Create Advanced WooCommerce Product', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Create a WooCommerce product with comprehensive settings including variations, attributes, galleries, stock management, and all product meta data. Supports simple and variable products.', 'mcp-ai-wpoos-pro' );
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
				'name'              => array(
					'type'        => 'string',
					'description' => __( 'Product name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'type'              => array(
					'type'        => 'string',
					'description' => __( 'Product type: simple, variable, grouped, external', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'simple', 'variable', 'grouped', 'external' ),
					'default'     => 'simple',
				),
				'status'            => array(
					'type'        => 'string',
					'description' => __( 'Product status: publish, draft, private', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'publish', 'draft', 'private' ),
					'default'     => 'draft',
				),
				'description'       => array(
					'type'        => 'string',
					'description' => __( 'Full product description (supports HTML)', 'mcp-ai-wpoos-pro' ),
				),
				'short_description' => array(
					'type'        => 'string',
					'description' => __( 'Short product description', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'sku'               => array(
					'type'        => 'string',
					'description' => __( 'Product SKU/reference code', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'regular_price'     => array(
					'type'        => 'number',
					'description' => __( 'Regular price (numeric value)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'sale_price'        => array(
					'type'        => 'number',
					'description' => __( 'Sale price (numeric value, must be less than regular price)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'manage_stock'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to manage stock for this product', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'stock_quantity'    => array(
					'type'        => 'integer',
					'description' => __( 'Stock quantity (required if manage_stock is true)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'stock_status'      => array(
					'type'        => 'string',
					'description' => __( 'Stock status', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'instock', 'outofstock', 'onbackorder' ),
					'default'     => 'instock',
				),
				'categories'        => array(
					'type'        => 'array',
					'description' => __( 'Array of category names or IDs', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'oneOf' => array(
							array( 'type' => 'string' ),
							array( 'type' => 'integer' ),
						),
					),
				),
				'tags'              => array(
					'type'        => 'array',
					'description' => __( 'Array of tag names', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'images'            => array(
					'type'        => 'array',
					'description' => __( 'Array of image URLs or attachment IDs. First image becomes featured image.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'oneOf' => array(
							array( 'type' => 'string' ),
							array( 'type' => 'integer' ),
						),
					),
				),
				'attributes'        => array(
					'type'        => 'array',
					'description' => __( 'Product attributes for variable products', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'      => array( 'type' => 'string' ),
							'options'   => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'visible'   => array(
								'type'    => 'boolean',
								'default' => true,
							),
							'variation' => array(
								'type'    => 'boolean',
								'default' => false,
							),
						),
						'required'   => array( 'name', 'options' ),
					),
				),
				'weight'            => array(
					'type'        => 'number',
					'description' => __( 'Product weight', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'dimensions'        => array(
					'type'        => 'object',
					'description' => __( 'Product dimensions', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'length' => array(
							'type'    => 'number',
							'minimum' => 0,
						),
						'width'  => array(
							'type'    => 'number',
							'minimum' => 0,
						),
						'height' => array(
							'type'    => 'number',
							'minimum' => 0,
						),
					),
				),
				'downloadable'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the product is downloadable', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'virtual'           => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the product is virtual (no shipping)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'tax_status'        => array(
					'type'        => 'string',
					'description' => __( 'Tax status', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'taxable', 'shipping', 'none' ),
					'default'     => 'taxable',
				),
				'featured'          => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to mark product as featured', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'name' ),
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
				__( 'You do not have permission to create products.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		// Validate required fields.
		if ( empty( $arguments['name'] ) ) {
			return new WP_Error(
				'missing_name',
				__( 'Product name is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Sanitize inputs.
		$product_data = $this->sanitize_product_data( $arguments );

		// Create the product.
		$product_id = $this->create_woocommerce_product( $product_data, $current_user_id );

		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}

		// Get the created product for response.
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return new WP_Error(
				'product_creation_failed',
				__( 'Product was created but could not be retrieved.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'success'    => true,
			'product_id' => $product_id,
			'product'    => array(
				'id'        => $product->get_id(),
				'name'      => $product->get_name(),
				'type'      => $product->get_type(),
				'status'    => $product->get_status(),
				'sku'       => $product->get_sku(),
				'price'     => $product->get_price(),
				'permalink' => $product->get_permalink(),
				'edit_url'  => admin_url( 'post.php?post=' . $product_id . '&action=edit' ),
			),
			'message'    => sprintf(
				/* translators: %s: Product name */
				__( 'Product "%s" created successfully.', 'mcp-ai-wpoos-pro' ),
				$product->get_name()
			),
		);
	}

	/**
	 * Sanitize product data.
	 *
	 * @param array $arguments Raw arguments.
	 * @return array Sanitized data.
	 */
	protected function sanitize_product_data( $arguments ) {
		$data = array();

		// Basic fields.
		$data['name']              = sanitize_text_field( $arguments['name'] );
		$data['type']              = isset( $arguments['type'] ) ? sanitize_text_field( $arguments['type'] ) : 'simple';
		$data['status']            = isset( $arguments['status'] ) ? sanitize_text_field( $arguments['status'] ) : 'draft';
		$data['description']       = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';
		$data['short_description'] = isset( $arguments['short_description'] ) ? wp_kses_post( $arguments['short_description'] ) : '';
		$data['sku']               = isset( $arguments['sku'] ) ? sanitize_text_field( $arguments['sku'] ) : '';

		// Pricing.
		$data['regular_price'] = isset( $arguments['regular_price'] ) ? floatval( $arguments['regular_price'] ) : 0;
		$data['sale_price']    = isset( $arguments['sale_price'] ) ? floatval( $arguments['sale_price'] ) : 0;

		// Stock.
		$data['manage_stock']   = isset( $arguments['manage_stock'] ) ? (bool) $arguments['manage_stock'] : false;
		$data['stock_quantity'] = isset( $arguments['stock_quantity'] ) ? absint( $arguments['stock_quantity'] ) : 0;
		$data['stock_status']   = isset( $arguments['stock_status'] ) ? sanitize_text_field( $arguments['stock_status'] ) : 'instock';

		// Taxonomy.
		$data['categories'] = isset( $arguments['categories'] ) ? (array) $arguments['categories'] : array();
		$data['tags']       = isset( $arguments['tags'] ) ? array_map( 'sanitize_text_field', (array) $arguments['tags'] ) : array();

		// Images.
		$data['images'] = isset( $arguments['images'] ) ? (array) $arguments['images'] : array();

		// Attributes.
		$data['attributes'] = isset( $arguments['attributes'] ) ? (array) $arguments['attributes'] : array();

		// Physical properties.
		$data['weight']     = isset( $arguments['weight'] ) ? floatval( $arguments['weight'] ) : 0;
		$data['dimensions'] = isset( $arguments['dimensions'] ) ? (array) $arguments['dimensions'] : array();

		// Product properties.
		$data['downloadable'] = isset( $arguments['downloadable'] ) ? (bool) $arguments['downloadable'] : false;
		$data['virtual']      = isset( $arguments['virtual'] ) ? (bool) $arguments['virtual'] : false;
		$data['tax_status']   = isset( $arguments['tax_status'] ) ? sanitize_text_field( $arguments['tax_status'] ) : 'taxable';
		$data['featured']     = isset( $arguments['featured'] ) ? (bool) $arguments['featured'] : false;

		return $data;
	}

	/**
	 * Create WooCommerce product.
	 *
	 * @param array $data Product data.
	 * @param int   $user_id User ID.
	 * @return int|WP_Error Product ID or error.
	 */
	protected function create_woocommerce_product( $data, $user_id ) {
		// Create product object based on type.
		$product_class = 'WC_Product_' . ucfirst( $data['type'] );

		if ( ! class_exists( $product_class ) ) {
			return new WP_Error(
				'invalid_product_type',
				sprintf(
					/* translators: %s: Product type */
					__( 'Invalid product type: %s', 'mcp-ai-wpoos-pro' ),
					$data['type']
				)
			);
		}

		$product = new $product_class();

		// Set basic data.
		$product->set_name( $data['name'] );
		$product->set_status( $data['status'] );
		$product->set_description( $data['description'] );
		$product->set_short_description( $data['short_description'] );

		if ( ! empty( $data['sku'] ) ) {
			$product->set_sku( $data['sku'] );
		}

		// Set pricing.
		if ( $data['regular_price'] > 0 ) {
			$product->set_regular_price( $data['regular_price'] );
		}

		if ( $data['sale_price'] > 0 && $data['sale_price'] < $data['regular_price'] ) {
			$product->set_sale_price( $data['sale_price'] );
		}

		// Set stock.
		$product->set_manage_stock( $data['manage_stock'] );
		if ( $data['manage_stock'] ) {
			$product->set_stock_quantity( $data['stock_quantity'] );
		}
		$product->set_stock_status( $data['stock_status'] );

		// Set properties.
		$product->set_downloadable( $data['downloadable'] );
		$product->set_virtual( $data['virtual'] );
		$product->set_tax_status( $data['tax_status'] );
		$product->set_featured( $data['featured'] );

		// Set weight and dimensions.
		if ( $data['weight'] > 0 ) {
			$product->set_weight( $data['weight'] );
		}

		if ( ! empty( $data['dimensions'] ) ) {
			if ( isset( $data['dimensions']['length'] ) ) {
				$product->set_length( $data['dimensions']['length'] );
			}
			if ( isset( $data['dimensions']['width'] ) ) {
				$product->set_width( $data['dimensions']['width'] );
			}
			if ( isset( $data['dimensions']['height'] ) ) {
				$product->set_height( $data['dimensions']['height'] );
			}
		}

		// Save the product.
		$product_id = $product->save();

		if ( ! $product_id ) {
			return new WP_Error(
				'product_save_failed',
				__( 'Failed to save product.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Set author.
		wp_update_post(
			array(
				'ID'          => $product_id,
				'post_author' => $user_id,
			)
		);

		// Set categories.
		if ( ! empty( $data['categories'] ) ) {
			$category_ids = $this->process_categories( $data['categories'] );
			if ( ! empty( $category_ids ) ) {
				wp_set_object_terms( $product_id, $category_ids, 'product_cat' );
			}
		}

		// Set tags.
		if ( ! empty( $data['tags'] ) ) {
			wp_set_object_terms( $product_id, $data['tags'], 'product_tag' );
		}

		// Set images.
		if ( ! empty( $data['images'] ) ) {
			$this->process_images( $product_id, $data['images'] );
		}

		// Set attributes for variable products.
		if ( 'variable' === $data['type'] && ! empty( $data['attributes'] ) ) {
			$this->process_attributes( $product_id, $data['attributes'] );
		}

		return $product_id;
	}

	/**
	 * Process category names/IDs.
	 *
	 * @param array $categories Category names or IDs.
	 * @return array Category IDs.
	 */
	protected function process_categories( $categories ) {
		$category_ids = array();

		foreach ( $categories as $category ) {
			if ( is_numeric( $category ) ) {
				// It's an ID.
				$category_ids[] = absint( $category );
			} else {
				// It's a name - find or create.
				$term = get_term_by( 'name', $category, 'product_cat' );

				if ( ! $term ) {
					// Create the category.
					$result = wp_insert_term( $category, 'product_cat' );
					if ( ! is_wp_error( $result ) ) {
						$category_ids[] = $result['term_id'];
					}
				} else {
					$category_ids[] = $term->term_id;
				}
			}
		}

		return $category_ids;
	}

	/**
	 * Process product images.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $images Image URLs or attachment IDs.
	 * @return void
	 */
	protected function process_images( $product_id, $images ) {
		$gallery_ids = array();

		foreach ( $images as $index => $image ) {
			$attachment_id = 0;

			if ( is_numeric( $image ) ) {
				// It's an attachment ID.
				$attachment_id = absint( $image );
			} elseif ( filter_var( $image, FILTER_VALIDATE_URL ) ) {
				// It's a URL - download it.
				$attachment_id = $this->download_image( $image, $product_id );
			}

			if ( $attachment_id ) {
				if ( 0 === $index ) {
					// First image is featured image.
					set_post_thumbnail( $product_id, $attachment_id );
				} else {
					// Rest go to gallery.
					$gallery_ids[] = $attachment_id;
				}
			}
		}

		// Set gallery images.
		if ( ! empty( $gallery_ids ) ) {
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
		}
	}

	/**
	 * Download image from URL.
	 *
	 * @param string $url Image URL.
	 * @param int    $product_id Product ID.
	 * @return int|false Attachment ID or false on failure.
	 */
	protected function download_image( $url, $product_id ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $url, $product_id, null, 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			return false;
		}

		return $attachment_id;
	}

	/**
	 * Process product attributes.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $attributes Attributes data.
	 * @return void
	 */
	protected function process_attributes( $product_id, $attributes ) {
		$product_attributes = array();
		$position           = 0;

		foreach ( $attributes as $attribute_data ) {
			if ( empty( $attribute_data['name'] ) || empty( $attribute_data['options'] ) ) {
				continue;
			}

			$attribute = new WC_Product_Attribute();
			$attribute->set_name( sanitize_text_field( $attribute_data['name'] ) );
			$attribute->set_options( array_map( 'sanitize_text_field', $attribute_data['options'] ) );
			$attribute->set_visible( isset( $attribute_data['visible'] ) ? (bool) $attribute_data['visible'] : true );
			$attribute->set_variation( isset( $attribute_data['variation'] ) ? (bool) $attribute_data['variation'] : false );
			$attribute->set_position( $position++ );

			$product_attributes[] = $attribute;
		}

		$product = wc_get_product( $product_id );
		if ( $product ) {
			$product->set_attributes( $product_attributes );
			$product->save();
		}
	}
}
