<?php
/**
 * Tool that creates WooCommerce product drafts populated with merchandising data.
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

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once __DIR__ . '/trait-wp-mcp-ai-tool-content-media.php';

/**
 * Creates draft WooCommerce products using a reference identifier.
 */
class WP_MCP_AI_Tool_Create_Woo_Product implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Content_Media;
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Determine whether WooCommerce is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WooCommerce' ) && class_exists( 'WC_Product' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The WooCommerce product creation tool is disabled because WooCommerce is not active.', 'mcp-ai-wpoos' );
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'create_woo_product';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Create WooCommerce Product Draft', 'mcp-ai-wpoos' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Creates a WooCommerce product draft using merchandising data gathered for a reference number.', 'mcp-ai-wpoos' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'reference'             => array(
					'type'        => 'string',
					'description' => __( 'Reference identifier for the product. Used as the SKU.', 'mcp-ai-wpoos' ),
					'minLength'   => 1,
				),
				'product_type'          => array(
					'type'        => 'string',
					'description' => __( 'Product type to create (simple or variable).', 'mcp-ai-wpoos' ),
					'enum'        => array( 'simple', 'variable' ),
					'default'     => 'simple',
				),
				'brand'                 => array(
					'type'        => 'string',
					'description' => __( 'Brand name associated with the product.', 'mcp-ai-wpoos' ),
				),
				'title'                 => array(
					'type'        => 'string',
					'description' => __( 'Product title.', 'mcp-ai-wpoos' ),
				),
				'local_price'           => array(
					'type'        => array( 'string', 'number' ),
					'description' => __( 'Local price for the product. Used as the regular price for simple products.', 'mcp-ai-wpoos' ),
				),
				'description'           => array(
					'type'        => 'string',
					'description' => __( 'Full product description.', 'mcp-ai-wpoos' ),
				),
				'description_secondary' => array(
					'type'        => 'string',
					'description' => __( 'Secondary description or marketing copy.', 'mcp-ai-wpoos' ),
				),
				'brand_page_url'        => array(
					'type'        => 'string',
					'description' => __( 'URL for the brand page to inspect for lifestyle imagery.', 'mcp-ai-wpoos' ),
					'format'      => 'uri',
				),
				'image_urls'            => array(
					'type'        => 'array',
					'description' => __( 'Explicit product or lifestyle image URLs to sideload.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'minItems'    => 2,
					'maxItems'    => 10,
				),
				// Enhanced parameters for comprehensive product creation.
				'categories'            => array(
					'type'        => 'array',
					'description' => __( 'Array of product category IDs or names to assign. Categories will be auto-created if they don\'t exist.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'anyOf' => array(
							array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							array( 'type' => 'string' ),
						),
					),
				),
				'tags'                  => array(
					'type'        => 'array',
					'description' => __( 'Array of product tag IDs or names to assign. Tags will be auto-created if they don\'t exist.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'anyOf' => array(
							array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							array( 'type' => 'string' ),
						),
					),
				),
				'sale_price'            => array(
					'type'        => array( 'string', 'number' ),
					'description' => __( 'Sale price for the product (must be lower than regular price).', 'mcp-ai-wpoos' ),
				),
				'manage_stock'          => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to enable stock management for this product.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'stock_quantity'        => array(
					'type'        => 'integer',
					'description' => __( 'Stock quantity (requires manage_stock to be true).', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
				),
				'stock_status'          => array(
					'type'        => 'string',
					'description' => __( 'Stock status: instock, outofstock, or onbackorder.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'instock', 'outofstock', 'onbackorder' ),
					'default'     => 'instock',
				),
				'weight'                => array(
					'type'        => array( 'string', 'number' ),
					'description' => __( 'Product weight for shipping calculations.', 'mcp-ai-wpoos' ),
				),
				'length'                => array(
					'type'        => array( 'string', 'number' ),
					'description' => __( 'Product length for shipping calculations.', 'mcp-ai-wpoos' ),
				),
				'width'                 => array(
					'type'        => array( 'string', 'number' ),
					'description' => __( 'Product width for shipping calculations.', 'mcp-ai-wpoos' ),
				),
				'height'                => array(
					'type'        => array( 'string', 'number' ),
					'description' => __( 'Product height for shipping calculations.', 'mcp-ai-wpoos' ),
				),
				'virtual'               => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this is a virtual product (no shipping).', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'downloadable'          => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this is a downloadable product.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'reviews_allowed'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to allow customer reviews.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'attributes'            => array(
					'type'        => 'array',
					'description' => __( 'Product attributes (e.g., size, color).', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'      => array(
								'type'        => 'string',
								'description' => __( 'Attribute name (e.g., "Size", "Color").', 'mcp-ai-wpoos' ),
							),
							'options'   => array(
								'type'        => 'array',
								'description' => __( 'Array of attribute values.', 'mcp-ai-wpoos' ),
								'items'       => array( 'type' => 'string' ),
							),
							'visible'   => array(
								'type'        => 'boolean',
								'description' => __( 'Whether attribute is visible on product page.', 'mcp-ai-wpoos' ),
								'default'     => true,
							),
							'variation' => array(
								'type'        => 'boolean',
								'description' => __( 'Whether attribute should be used for variations (only for variable products).', 'mcp-ai-wpoos' ),
								'default'     => false,
							),
						),
						'required'   => array( 'name', 'options' ),
					),
				),
				'variations'            => array(
					'type'        => 'array',
					'description' => __( 'Array of product variations (only for variable products). Each variation must specify attributes, SKU, and price.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'attributes'     => array(
								'type'                 => 'object',
								'description'          => __( 'Variation attributes (e.g., {"Size": "Large", "Color": "Red"}). Keys must match attribute names.', 'mcp-ai-wpoos' ),
								'additionalProperties' => array( 'type' => 'string' ),
							),
							'sku'            => array(
								'type'        => 'string',
								'description' => __( 'SKU for this variation.', 'mcp-ai-wpoos' ),
							),
							'regular_price'  => array(
								'type'        => array( 'string', 'number' ),
								'description' => __( 'Regular price for this variation.', 'mcp-ai-wpoos' ),
							),
							'sale_price'     => array(
								'type'        => array( 'string', 'number' ),
								'description' => __( 'Sale price for this variation (optional).', 'mcp-ai-wpoos' ),
							),
							'stock_quantity' => array(
								'type'        => 'integer',
								'description' => __( 'Stock quantity for this variation (optional).', 'mcp-ai-wpoos' ),
								'minimum'     => 0,
							),
							'stock_status'   => array(
								'type'        => 'string',
								'description' => __( 'Stock status for this variation: instock, outofstock, or onbackorder.', 'mcp-ai-wpoos' ),
								'enum'        => array( 'instock', 'outofstock', 'onbackorder' ),
								'default'     => 'instock',
							),
							'manage_stock'   => array(
								'type'        => 'boolean',
								'description' => __( 'Whether to enable stock management for this variation.', 'mcp-ai-wpoos' ),
								'default'     => false,
							),
							'weight'         => array(
								'type'        => array( 'string', 'number' ),
								'description' => __( 'Weight for this variation (optional).', 'mcp-ai-wpoos' ),
							),
							'length'         => array(
								'type'        => array( 'string', 'number' ),
								'description' => __( 'Length for this variation (optional).', 'mcp-ai-wpoos' ),
							),
							'width'          => array(
								'type'        => array( 'string', 'number' ),
								'description' => __( 'Width for this variation (optional).', 'mcp-ai-wpoos' ),
							),
							'height'         => array(
								'type'        => array( 'string', 'number' ),
								'description' => __( 'Height for this variation (optional).', 'mcp-ai-wpoos' ),
							),
							'description'    => array(
								'type'        => 'string',
								'description' => __( 'Description for this variation (optional).', 'mcp-ai-wpoos' ),
							),
						),
						'required'   => array( 'attributes', 'regular_price' ),
					),
				),
				'meta_input'            => array(
					'type'                 => 'object',
					'description'          => __( 'Array of custom field key-value pairs to set as product meta.', 'mcp-ai-wpoos' ),
					'additionalProperties' => true,
				),
			),
			'required'             => array( 'reference' ),
			'additionalProperties' => false,
		);

		// Merge content media parameters for long description.
		$schema['properties'] = array_merge( $schema['properties'], $this->get_content_media_parameters() );

		// Add separate parameters for short description media.
		$schema['properties']['short_description_images'] = array(
			'type'        => 'array',
			'description' => __( 'Array of images to embed in the short description. Maximum 2 images.', 'mcp-ai-wpoos' ),
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'source'  => array(
						'description' => __( 'Image source - either attachment ID (integer) or URL (string)', 'mcp-ai-wpoos' ),
						'anyOf'       => array(
							array( 'type' => 'integer' ),
							array( 'type' => 'string' ),
						),
					),
					'caption' => array(
						'type'        => 'string',
						'description' => __( 'Optional caption for the image', 'mcp-ai-wpoos' ),
					),
					'alt'     => array(
						'type'        => 'string',
						'description' => __( 'Optional alt text for accessibility', 'mcp-ai-wpoos' ),
					),
				),
				'required'   => array( 'source' ),
			),
			'maxItems'    => 2,
		);

		$schema['properties']['short_description_charts'] = array(
			'type'        => 'array',
			'description' => __( 'Array of charts to embed in the short description. Maximum 1 chart.', 'mcp-ai-wpoos' ),
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'type'  => array(
						'type'        => 'string',
						'description' => __( 'Chart type', 'mcp-ai-wpoos' ),
						'enum'        => array( 'bar', 'line', 'pie', 'doughnut', 'radar', 'polarArea' ),
					),
					'title' => array(
						'type'        => 'string',
						'description' => __( 'Chart title', 'mcp-ai-wpoos' ),
					),
					'data'  => array(
						'type'        => 'object',
						'description' => __( 'Chart data with labels and datasets', 'mcp-ai-wpoos' ),
					),
				),
				'required'   => array( 'type', 'data' ),
			),
			'maxItems'    => 1,
		);

		$schema['properties']['variation_images'] = array(
			'type'        => 'array',
			'description' => __( 'Array of images for product variations. Each image should specify which variation it belongs to.', 'mcp-ai-wpoos' ),
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'variation_attributes' => array(
						'type'        => 'object',
						'description' => __( 'Attributes identifying the variation (e.g., {"Size": "Large", "Color": "Red"})', 'mcp-ai-wpoos' ),
					),
					'image_id'             => array(
						'type'        => 'integer',
						'description' => __( 'Attachment ID of the variation image', 'mcp-ai-wpoos' ),
						'minimum'     => 1,
					),
				),
				'required'   => array( 'variation_attributes', 'image_id' ),
			),
		);

		return $schema;
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to create products.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'edit_products' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create WooCommerce products.', 'mcp-ai-wpoos' ) );
		}

		$reference_raw = isset( $arguments['reference'] ) ? (string) $arguments['reference'] : '';
		$reference     = function_exists( 'wc_clean' ) ? wc_clean( $reference_raw ) : sanitize_text_field( $reference_raw );

		if ( '' === $reference ) {
			return new WP_Error( 'wp_mcp_ai_missing_reference', __( 'A product reference is required.', 'mcp-ai-wpoos' ) );
		}

		$product_type = isset( $arguments['product_type'] ) ? sanitize_key( $arguments['product_type'] ) : 'simple';
		if ( ! in_array( $product_type, array( 'simple', 'variable' ), true ) ) {
			$product_type = 'simple';
		}

		$brand = isset( $arguments['brand'] ) ? $this->sanitize_brand( $arguments['brand'] ) : '';
		$title = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		if ( '' === $title ) {
			/* translators: %s product reference */
			$title = sprintf( __( 'Product %s', 'mcp-ai-wpoos' ), $reference );
		}

		$description  = isset( $arguments['description'] ) ? $this->sanitize_html( $arguments['description'] ) : '';
		$description2 = isset( $arguments['description_secondary'] ) ? $this->sanitize_html( $arguments['description_secondary'] ) : '';

		$local_price = isset( $arguments['local_price'] ) ? $this->normalise_price( $arguments['local_price'] ) : '';

		$product = $this->instantiate_product( $product_type );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$product->set_name( $title );
		$product->set_status( 'draft' );
		$product->set_sku( $reference );

		if ( '' !== $description ) {
			// Embed content media in long description.
			$description_with_media = $this->embed_content_media( $description, $arguments );
			$product->set_description( $description_with_media );
		}

		if ( '' !== $description2 ) {
			// Support content media in short description as well.
			// Use separate parameters with limits (2 images, 1 chart max).
			$short_desc_args = array();
			if ( isset( $arguments['short_description_images'] ) ) {
				$short_desc_args['content_images'] = array_slice( $arguments['short_description_images'], 0, 2 );
			}
			if ( isset( $arguments['short_description_charts'] ) ) {
				$short_desc_args['content_charts'] = array_slice( $arguments['short_description_charts'], 0, 1 );
			}

			if ( ! empty( $short_desc_args ) ) {
				$description2_with_media = $this->embed_content_media( $description2, $short_desc_args );
				$product->set_short_description( $description2_with_media );
			} else {
				$product->set_short_description( $description2 );
			}
		}

		if ( 'simple' === $product_type && '' !== $local_price ) {
			$product->set_regular_price( $local_price );
			$product->set_price( $local_price );
		}

		// Handle sale price.
		if ( isset( $arguments['sale_price'] ) ) {
			$sale_price = $this->normalise_price( $arguments['sale_price'] );
			if ( '' !== $sale_price && 'simple' === $product_type ) {
				$product->set_sale_price( $sale_price );
				$product->set_price( $sale_price );
			}
		}

		// Handle stock management.
		if ( isset( $arguments['manage_stock'] ) && $arguments['manage_stock'] ) {
			$product->set_manage_stock( true );
			if ( isset( $arguments['stock_quantity'] ) ) {
				$product->set_stock_quantity( absint( $arguments['stock_quantity'] ) );
			}
		}

		// Handle stock status.
		if ( isset( $arguments['stock_status'] ) ) {
			$stock_status = sanitize_key( $arguments['stock_status'] );
			if ( in_array( $stock_status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
				$product->set_stock_status( $stock_status );
			}
		}

		// Handle shipping dimensions.
		if ( isset( $arguments['weight'] ) && '' !== $arguments['weight'] ) {
			$product->set_weight( $this->sanitize_dimension( $arguments['weight'] ) );
		}
		if ( isset( $arguments['length'] ) && '' !== $arguments['length'] ) {
			$product->set_length( $this->sanitize_dimension( $arguments['length'] ) );
		}
		if ( isset( $arguments['width'] ) && '' !== $arguments['width'] ) {
			$product->set_width( $this->sanitize_dimension( $arguments['width'] ) );
		}
		if ( isset( $arguments['height'] ) && '' !== $arguments['height'] ) {
			$product->set_height( $this->sanitize_dimension( $arguments['height'] ) );
		}

		// Handle virtual and downloadable flags.
		if ( isset( $arguments['virtual'] ) && $arguments['virtual'] ) {
			$product->set_virtual( true );
		}
		if ( isset( $arguments['downloadable'] ) && $arguments['downloadable'] ) {
			$product->set_downloadable( true );
		}

		// Handle reviews.
		if ( isset( $arguments['reviews_allowed'] ) ) {
			$product->set_reviews_allowed( (bool) $arguments['reviews_allowed'] );
		}

		$product_id = $product->save();

		if ( ! $product_id ) {
			return new WP_Error( 'wp_mcp_ai_product_not_saved', __( 'The product could not be saved.', 'mcp-ai-wpoos' ) );
		}

		$saved_product = wc_get_product( $product_id );

		if ( ! $saved_product ) {
			return new WP_Error( 'wp_mcp_ai_product_missing', __( 'The product draft was created but could not be loaded.', 'mcp-ai-wpoos' ) );
		}

		$messages = array();

		if ( '' !== $brand ) {
			if ( ! $this->assign_brand( $product_id, $brand ) ) {
				$messages[] = __( 'The brand value was stored as product metadata because no brand taxonomy is registered.', 'mcp-ai-wpoos' );
			}
		}

		if ( '' !== $description2 ) {
			update_post_meta( $product_id, '_wp_mcp_ai_description_secondary', wp_strip_all_tags( $description2 ) );
		}

		update_post_meta( $product_id, '_wp_mcp_ai_reference', $reference );

		if ( '' !== $local_price ) {
			update_post_meta( $product_id, '_wp_mcp_ai_local_price', $local_price );
		}

		$image_urls  = $this->collect_image_urls( $arguments, $messages );
		$attachments = array();

		if ( ! empty( $image_urls ) ) {
			$attachments = $this->sideload_images( $product_id, $image_urls, $saved_product, $messages );
		} else {
			$messages[] = __( 'No images were attached because none could be discovered.', 'mcp-ai-wpoos' );
		}

		if ( ! empty( $attachments ) ) {
			$saved_product = wc_get_product( $product_id );
		}

		// Handle variation images if provided.
		if ( isset( $arguments['variation_images'] ) && is_array( $arguments['variation_images'] ) ) {
			$this->assign_variation_images( $product_id, $arguments['variation_images'], $messages );
		}

		// Handle product categories.
		if ( isset( $arguments['categories'] ) && is_array( $arguments['categories'] ) ) {
			$category_ids = $this->resolve_product_terms( $arguments['categories'], 'product_cat' );
			if ( ! empty( $category_ids ) ) {
				wp_set_object_terms( $product_id, $category_ids, 'product_cat' );
			}
		}

		// Handle product tags.
		if ( isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			$tag_ids = $this->resolve_product_terms( $arguments['tags'], 'product_tag' );
			if ( ! empty( $tag_ids ) ) {
				wp_set_object_terms( $product_id, $tag_ids, 'product_tag' );
			}
		}

		// Handle product attributes.
		if ( isset( $arguments['attributes'] ) && is_array( $arguments['attributes'] ) ) {
			$this->set_product_attributes( $product_id, $arguments['attributes'], $product_type );
		}

		// Handle product variations (only for variable products).
		if ( 'variable' === $product_type && isset( $arguments['variations'] ) && is_array( $arguments['variations'] ) ) {
			$variation_result = $this->create_product_variations( $product_id, $arguments['variations'], $messages );
			if ( is_wp_error( $variation_result ) ) {
				$messages[] = $variation_result->get_error_message();
			}
		}

		// Handle custom meta fields.
		if ( isset( $arguments['meta_input'] ) && is_array( $arguments['meta_input'] ) ) {
			$this->add_product_meta( $product_id, $arguments['meta_input'] );
		}

		$summary_text = sprintf(
			/* translators: 1: product title, 2: product ID */
			__( 'Created WooCommerce product: %1$s (ID: %2$d)', 'mcp-ai-wpoos' ),
			$saved_product ? $saved_product->get_name() : $title,
			$product_id
		);

		$response = array(
			'message'      => $summary_text, // Chat client display.
			'summary'      => $summary_text, // Backward compatibility.
			'product_id'   => $product_id,
			'product_type' => $saved_product ? $saved_product->get_type() : $product_type,
			'status'       => $saved_product ? $saved_product->get_status() : 'draft',
			'sku'          => $reference,
			'title'        => $saved_product ? $saved_product->get_name() : $title,
			'permalink'    => get_permalink( $product_id ),
			'price'        => ( 'simple' === $product_type && $saved_product ) ? $saved_product->get_regular_price() : $local_price,
			'brand'        => $brand,
			'attachments'  => $attachments,
			'notices'      => $messages,
			'edit_url'     => get_edit_post_link( $product_id, 'raw' ),
		);

		/**
		 * Filter the WooCommerce product creation response returned to the assistant.
		 *
		 * @param array                         $response      Response payload.
		 * @param WC_Product                    $saved_product Saved product instance.
		 * @param array                         $arguments     Original tool arguments.
		 * @param array                         $context       Execution context.
		 */
		return apply_filters( 'wp_mcp_ai_create_woo_product_response', $response, $saved_product, $arguments, $context );
	}

	/**
	 * Instantiate a product for the requested type.
	 *
	 * @param string $type Product type.
	 * @return WC_Product|WP_Error
	 */
	protected function instantiate_product( $type ) {
		if ( 'variable' === $type ) {
			if ( ! class_exists( 'WC_Product_Variable' ) ) {
				return new WP_Error( 'wp_mcp_ai_missing_product_class', __( 'Variable product support is unavailable.', 'mcp-ai-wpoos' ) );
			}

			return new WC_Product_Variable();
		}

		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_product_class', __( 'Simple product support is unavailable.', 'mcp-ai-wpoos' ) );
		}

		return new WC_Product_Simple();
	}

	/**
	 * Sanitize brand names.
	 *
	 * @param string $brand Raw brand value.
	 * @return string
	 */
	protected function sanitize_brand( $brand ) {
		if ( is_string( $brand ) ) {
			$brand = trim( wp_strip_all_tags( $brand ) );
		} else {
			$brand = '';
		}

		return $brand;
	}

	/**
	 * Normalize HTML payloads while preserving basic formatting.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	protected function sanitize_html( $html ) {
		$allowed_tags = wp_kses_allowed_html( 'post' );

		return wp_kses( $html, $allowed_tags );
	}

	/**
	 * Normalise various price formats into a WooCommerce decimal string.
	 *
	 * @param mixed $value Raw price value.
	 * @return string
	 */
	protected function normalise_price( $value ) {
		if ( is_numeric( $value ) ) {
			return wc_format_decimal( $value );
		}

		if ( is_string( $value ) ) {
			$filtered = preg_replace( '/[^\d.,-]/', '', $value );
			if ( '' === $filtered ) {
				return '';
			}

			$decimal_separator  = wc_get_price_decimal_separator();
			$thousand_separator = wc_get_price_thousand_separator();

			if ( false !== strpos( $filtered, $decimal_separator ) ) {
				$filtered = str_replace( $thousand_separator, '', $filtered );
				$filtered = str_replace( $decimal_separator, '.', $filtered );
			} elseif ( substr_count( $filtered, ',' ) === 1 && substr_count( $filtered, '.' ) === 0 ) {
				$filtered = str_replace( ',', '.', $filtered );
			} else {
				$filtered = str_replace( ',', '', $filtered );
			}

			return wc_format_decimal( $filtered );
		}

		return '';
	}

	/**
	 * Assign the brand data to the product.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $brand      Brand name.
	 * @return bool Whether the brand was attached via taxonomy.
	 */
	protected function assign_brand( $product_id, $brand ) {
		$brand_attached = false;
		$taxonomies     = array( 'pa_brand', 'product_brand', 'brand' );

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term = term_exists( $brand, $taxonomy );
			if ( ! $term ) {
				$term = wp_insert_term( $brand, $taxonomy );
			}

			if ( is_wp_error( $term ) ) {
				continue;
			}

			$term_id = is_array( $term ) ? $term['term_id'] : $term;

			wp_set_object_terms( $product_id, array( (int) $term_id ), $taxonomy, false );
			$brand_attached = true;
			break;
		}

		if ( ! $brand_attached ) {
			update_post_meta( $product_id, '_wp_mcp_ai_brand', $brand );
		}

		return $brand_attached;
	}

	/**
	 * Build a list of image URLs from tool arguments.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $messages  Message buffer for notices.
	 * @return array
	 */
	protected function collect_image_urls( $arguments, &$messages ) {
		$urls = array();

		if ( ! empty( $arguments['image_urls'] ) && is_array( $arguments['image_urls'] ) ) {
			foreach ( $arguments['image_urls'] as $url ) {
				$absolute = $this->prepare_image_url( $url, isset( $arguments['brand_page_url'] ) ? $arguments['brand_page_url'] : '' );
				if ( $absolute ) {
					$urls[] = $absolute;
				}
			}
		}

		if ( empty( $urls ) && ! empty( $arguments['brand_page_url'] ) ) {
			$scraped = $this->scrape_brand_page_images( $arguments['brand_page_url'] );
			if ( ! empty( $scraped ) ) {
				$urls = $scraped;
			} else {
				/* translators: %s brand page URL */
				$messages[] = sprintf( __( 'No images could be extracted from %s.', 'mcp-ai-wpoos' ), esc_url( $arguments['brand_page_url'] ) );
			}
		}

		$urls = array_values( array_unique( $urls ) );

		if ( count( $urls ) > 10 ) {
			$urls       = array_slice( $urls, 0, 10 );
			$messages[] = __( 'Only the first 10 images were attached to match the gallery limit.', 'mcp-ai-wpoos' );
		}

		if ( count( $urls ) > 0 && count( $urls ) < 2 ) {
			$messages[] = __( 'Fewer than two images were available; consider supplying additional image URLs.', 'mcp-ai-wpoos' );
		}

		return $urls;
	}

	/**
	 * Convert a potential image URL into an absolute URL.
	 *
	 * @param string $candidate Candidate URL.
	 * @param string $base_url  Base URL for relative paths.
	 * @return string
	 */
	protected function prepare_image_url( $candidate, $base_url = '' ) {
		if ( ! is_string( $candidate ) || '' === $candidate ) {
			return '';
		}

		$candidate = trim( $candidate );

		if ( 0 === strpos( $candidate, 'data:' ) ) {
			return '';
		}

		$validated = wp_http_validate_url( $candidate );
		if ( $validated ) {
			return esc_url_raw( $validated );
		}

		if ( '' !== $base_url ) {
			// WP_Http is autoloaded in WordPress core, no require needed.
			$absolute = WP_Http::make_absolute_url( $candidate, $base_url );
			if ( $absolute && wp_http_validate_url( $absolute ) ) {
				return esc_url_raw( $absolute );
			}
		}

		return '';
	}

	/**
	 * Scrape image sources from the supplied brand page.
	 *
	 * @param string $url Brand page URL.
	 * @return array
	 */
	protected function scrape_brand_page_images( $url ) {
		$url = esc_url_raw( $url );
		if ( ! $url ) {
			return array();
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return array();
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			return array();
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $dom->loadHTML( $body );
		libxml_clear_errors();

		if ( ! $loaded ) {
			return array();
		}

		$xpath  = new DOMXPath( $dom );
		$images = $xpath->query( '//img[@src or @data-src or @data-large_image]' );

		$urls = array();

		foreach ( $images as $node ) {
			$candidates = array(
				$node->getAttribute( 'data-large_image' ),
				$node->getAttribute( 'data-full-size-image' ),
				$node->getAttribute( 'data-src' ),
				$node->getAttribute( 'data-lazy-src' ),
				$node->getAttribute( 'src' ),
			);

			$srcset = $node->getAttribute( 'srcset' );
			if ( $srcset ) {
				$parsed_srcset = $this->parse_srcset( $srcset );
				if ( ! empty( $parsed_srcset ) ) {
					$candidates = array_merge( $parsed_srcset, $candidates );
				}
			}

			foreach ( $candidates as $candidate ) {
				$absolute = $this->prepare_image_url( $candidate, $url );
				if ( $absolute ) {
					$urls[] = $absolute;
					break;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Parse a srcset attribute into an ordered list of URLs, preferring the largest assets.
	 *
	 * @param string $srcset Srcset attribute value.
	 * @return array
	 */
	protected function parse_srcset( $srcset ) {
		$entries = array();
		$parts   = array_filter( array_map( 'trim', explode( ',', $srcset ) ) );

		foreach ( $parts as $part ) {
			$segments = preg_split( '/\s+/', $part );
			if ( empty( $segments ) ) {
				continue;
			}

			$url  = array_shift( $segments );
			$size = 0;

			if ( ! empty( $segments ) ) {
				$descriptor = strtolower( $segments[0] );
				if ( $this->string_ends_with( $descriptor, 'w' ) ) {
					$size = (int) $descriptor;
				} elseif ( $this->string_ends_with( $descriptor, 'x' ) ) {
					$size = (int) ( 1000 * (float) $descriptor );
				}
			}

			$entries[] = array(
				'url'  => $url,
				'size' => $size,
			);
		}

		usort(
			$entries,
			function ( $a, $b ) {
				return $b['size'] <=> $a['size'];
			}
		);

		$urls = array();
		foreach ( $entries as $entry ) {
			$urls[] = $entry['url'];
		}

		return $urls;
	}

	/**
	 * Determine whether a string ends with a particular substring.
	 *
	 * @param string $haystack The string to inspect.
	 * @param string $needle   The substring to check.
	 * @return bool
	 */
	protected function string_ends_with( $haystack, $needle ) {
		$haystack = (string) $haystack;
		$needle   = (string) $needle;

		if ( '' === $needle || '' === $haystack ) {
			return false;
		}

		return substr( $haystack, -strlen( $needle ) ) === $needle;
	}

	/**
	 * Download and attach imagery to the product gallery.
	 *
	 * @param int        $product_id  Product ID.
	 * @param array      $image_urls  Image URLs to sideload.
	 * @param WC_Product $product     Product instance.
	 * @param array      $messages    Message buffer for notices.
	 * @return array
	 */
	protected function sideload_images( $product_id, $image_urls, $product, &$messages ) {
		if ( empty( $image_urls ) ) {
			return array();
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$attachment_ids = array();

		foreach ( $image_urls as $index => $url ) {
			$attachment_id = media_sideload_image( $url, $product_id, $product->get_name(), 'id' );

			if ( is_wp_error( $attachment_id ) ) {
				$messages[] = sprintf(
					/* translators: %s image URL */
					__( 'Image %s could not be imported.', 'mcp-ai-wpoos' ),
					esc_url( $url )
				);
				continue;
			}

			$attachment_ids[] = (int) $attachment_id;
		}

		if ( empty( $attachment_ids ) ) {
			return array();
		}

		$attachment_ids = array_values( array_unique( $attachment_ids ) );

		$primary_id  = $attachment_ids[0];
		$gallery_ids = array_slice( $attachment_ids, 1 );

		$product->set_image_id( $primary_id );

		$existing_gallery = $product->get_gallery_image_ids();
		if ( empty( $existing_gallery ) ) {
			$existing_gallery = array();
		}

		$product->set_gallery_image_ids( array_merge( $existing_gallery, $gallery_ids ) );
		$product->save();

		return array(
			'featured_image_id' => $primary_id,
			'gallery_image_ids' => $product->get_gallery_image_ids(),
		);
	}

	/**
	 * Resolves product taxonomy terms from IDs or names.
	 *
	 * @param array  $terms    Array of term IDs or names.
	 * @param string $taxonomy Taxonomy name (product_cat or product_tag).
	 * @return array Array of term IDs.
	 */
	protected function resolve_product_terms( $terms, $taxonomy ) {
		$term_ids = array();

		foreach ( $terms as $term ) {
			if ( is_numeric( $term ) ) {
				$term_id = absint( $term );
				if ( term_exists( $term_id, $taxonomy ) ) {
					$term_ids[] = $term_id;
				}
			} else {
				// Try to find or create term by name.
				$term_obj = term_exists( $term, $taxonomy );
				if ( ! $term_obj ) {
					// Create the term if it doesn't exist.
					$term_obj = wp_insert_term( sanitize_text_field( $term ), $taxonomy );
				}

				if ( ! is_wp_error( $term_obj ) && isset( $term_obj['term_id'] ) ) {
					$term_ids[] = $term_obj['term_id'];
				}
			}
		}

		return array_unique( $term_ids );
	}

	/**
	 * Sets product attributes.
	 *
	 * @param int    $product_id   Product ID.
	 * @param array  $attributes   Array of attribute definitions.
	 * @param string $product_type Product type (simple or variable).
	 */
	protected function set_product_attributes( $product_id, $attributes, $product_type = 'simple' ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$product_attributes = array();
		$position           = 0;

		foreach ( $attributes as $attribute_data ) {
			if ( empty( $attribute_data['name'] ) || empty( $attribute_data['options'] ) ) {
				continue;
			}

			$attribute_name     = wc_sanitize_taxonomy_name( $attribute_data['name'] );
			$is_variation       = isset( $attribute_data['variation'] ) ? (bool) $attribute_data['variation'] : false;
			$taxonomy_name      = wc_attribute_taxonomy_name( $attribute_name );
			$global_attr_exists = taxonomy_exists( $taxonomy_name );

			$attribute = new WC_Product_Attribute();

			// If global attribute exists, use it; otherwise use local attribute.
			if ( $global_attr_exists ) {
				$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy_name ) );
				$attribute->set_name( $taxonomy_name );

				// Ensure terms exist in the taxonomy.
				$term_ids = array();
				foreach ( $attribute_data['options'] as $option ) {
					$option = sanitize_text_field( $option );
					$term   = term_exists( $option, $taxonomy_name );
					if ( ! $term ) {
						$term = wp_insert_term( $option, $taxonomy_name );
					}
					if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
						$term_ids[] = $term['term_id'];
					}
				}
				// Set terms for the product.
				wp_set_object_terms( $product_id, $term_ids, $taxonomy_name, true );
				$attribute->set_options( $term_ids );
			} else {
				// Use local attribute.
				$attribute->set_name( $attribute_name );
				$attribute->set_options( array_map( 'sanitize_text_field', $attribute_data['options'] ) );
			}

			$attribute->set_position( $position++ );
			$attribute->set_visible( isset( $attribute_data['visible'] ) ? (bool) $attribute_data['visible'] : true );

			// Set variation flag - only true for variable products when explicitly set.
			$attribute->set_variation( 'variable' === $product_type && $is_variation );

			$product_attributes[] = $attribute;
		}

		if ( ! empty( $product_attributes ) ) {
			$product->set_attributes( $product_attributes );
			$product->save();
		}
	}

	/**
	 * Creates product variations for a variable product.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $variations Array of variation definitions.
	 * @param array $messages   Message buffer for notices.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	protected function create_product_variations( $product_id, $variations, &$messages ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_product', __( 'Product must be a variable product to create variations.', 'mcp-ai-wpoos' ) );
		}

		// Check if WC_Product_Variation class exists.
		if ( ! class_exists( 'WC_Product_Variation' ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_variation_class', __( 'WC_Product_Variation class not found.', 'mcp-ai-wpoos' ) );
		}

		// Get product attributes to validate variation attributes.
		$product_attributes = $product->get_attributes();
		if ( empty( $product_attributes ) ) {
			return new WP_Error( 'wp_mcp_ai_no_attributes', __( 'Product must have attributes defined before creating variations.', 'mcp-ai-wpoos' ) );
		}

		$variation_ids = array();
		$created_count = 0;

		foreach ( $variations as $variation_data ) {
			// Validate required fields.
			if ( empty( $variation_data['attributes'] ) || ! is_array( $variation_data['attributes'] ) ) {
				$messages[] = __( 'Skipped variation: attributes are required.', 'mcp-ai-wpoos' );
				continue;
			}

			if ( empty( $variation_data['regular_price'] ) ) {
				$messages[] = __( 'Skipped variation: regular_price is required.', 'mcp-ai-wpoos' );
				continue;
			}

			// Normalize and validate attributes against product attributes.
			$normalized_attributes = array();
			foreach ( $variation_data['attributes'] as $attr_name => $attr_value ) {
				$attr_name_sanitized = wc_sanitize_taxonomy_name( stripslashes( $attr_name ) );

				// Check if this attribute exists in product.
				$found = false;
				foreach ( $product_attributes as $product_attr ) {
					$product_attr_name = $product_attr->get_name();

					// Handle both taxonomy-based (pa_color) and custom (color) attributes.
					if ( $product_attr_name === $attr_name_sanitized ||
						$product_attr_name === wc_attribute_taxonomy_name( $attr_name_sanitized ) ||
						wc_sanitize_taxonomy_name( $product_attr_name ) === $attr_name_sanitized ) {

						// For taxonomy-based attributes, use full taxonomy name.
						if ( taxonomy_exists( $product_attr_name ) ) {
							$normalized_attributes[ $product_attr_name ] = sanitize_text_field( $attr_value );
						} else {
							// For custom attributes, use the attribute key with 'attribute_' prefix.
							$normalized_attributes[ 'attribute_' . $product_attr_name ] = sanitize_text_field( $attr_value );
						}
						$found = true;
						break;
					}
				}

				if ( ! $found ) {
					$messages[] = sprintf(
						/* translators: %s: attribute name */
						__( 'Skipped variation: attribute "%s" is not defined for this product.', 'mcp-ai-wpoos' ),
						esc_html( $attr_name )
					);
					continue 2; // Skip this variation entirely.
				}
			}

			// Create the variation.
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product_id );
			$variation->set_attributes( $normalized_attributes );

			// Set regular price.
			$regular_price = $this->normalise_price( $variation_data['regular_price'] );
			if ( '' !== $regular_price ) {
				$variation->set_regular_price( $regular_price );
				$variation->set_price( $regular_price );
			}

			// Set sale price if provided.
			if ( isset( $variation_data['sale_price'] ) ) {
				$sale_price = $this->normalise_price( $variation_data['sale_price'] );
				if ( '' !== $sale_price ) {
					$variation->set_sale_price( $sale_price );
					$variation->set_price( $sale_price );
				}
			}

			// Set SKU if provided.
			if ( isset( $variation_data['sku'] ) && '' !== $variation_data['sku'] ) {
				$sku = function_exists( 'wc_clean' ) ? wc_clean( $variation_data['sku'] ) : sanitize_text_field( $variation_data['sku'] );
				$variation->set_sku( $sku );
			}

			// Set stock management.
			if ( isset( $variation_data['manage_stock'] ) && $variation_data['manage_stock'] ) {
				$variation->set_manage_stock( true );
				if ( isset( $variation_data['stock_quantity'] ) ) {
					$variation->set_stock_quantity( absint( $variation_data['stock_quantity'] ) );
				}
			}

			// Set stock status.
			if ( isset( $variation_data['stock_status'] ) ) {
				$stock_status = sanitize_key( $variation_data['stock_status'] );
				if ( in_array( $stock_status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
					$variation->set_stock_status( $stock_status );
				}
			}

			// Set dimensions.
			if ( isset( $variation_data['weight'] ) && '' !== $variation_data['weight'] ) {
				$variation->set_weight( $this->sanitize_dimension( $variation_data['weight'] ) );
			}
			if ( isset( $variation_data['length'] ) && '' !== $variation_data['length'] ) {
				$variation->set_length( $this->sanitize_dimension( $variation_data['length'] ) );
			}
			if ( isset( $variation_data['width'] ) && '' !== $variation_data['width'] ) {
				$variation->set_width( $this->sanitize_dimension( $variation_data['width'] ) );
			}
			if ( isset( $variation_data['height'] ) && '' !== $variation_data['height'] ) {
				$variation->set_height( $this->sanitize_dimension( $variation_data['height'] ) );
			}

			// Set description if provided.
			if ( isset( $variation_data['description'] ) && '' !== $variation_data['description'] ) {
				$variation->set_description( $this->sanitize_html( $variation_data['description'] ) );
			}

			// Save the variation.
			$variation_id = $variation->save();
			if ( $variation_id ) {
				$variation_ids[] = $variation_id;
				++$created_count;
			}
		}

		if ( $created_count > 0 ) {
			// Sync the variable product to update its available variations.
			WC_Product_Variable::sync( $product_id );

			$messages[] = sprintf(
				/* translators: %d: number of variations created */
				_n( 'Created %d product variation.', 'Created %d product variations.', $created_count, 'mcp-ai-wpoos' ),
				$created_count
			);
		} else {
			$messages[] = __( 'No variations were created.', 'mcp-ai-wpoos' );
		}

		return true;
	}

	/**
	 * Adds custom product meta.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $meta_input Array of meta key-value pairs.
	 */
	protected function add_product_meta( $product_id, $meta_input ) {
		foreach ( $meta_input as $key => $value ) {
			$sanitized_key = sanitize_key( $key );

			// Skip protected meta keys.
			if ( is_protected_meta( $sanitized_key, 'post' ) ) {
				continue;
			}

			// Recursively sanitize arrays.
			if ( is_array( $value ) ) {
				$sanitized_value = array_map( 'sanitize_text_field', $value );
			} else {
				$sanitized_value = sanitize_text_field( $value );
			}

			update_post_meta( $product_id, $sanitized_key, $sanitized_value );
		}
	}

	/**
	 * Sanitizes dimension values.
	 *
	 * @param mixed $dimension Raw dimension value.
	 * @return string Sanitized dimension.
	 */
	protected function sanitize_dimension( $dimension ) {
		return wc_format_decimal( $dimension );
	}

	/**
	 * Assigns images to product variations.
	 *
	 * @param int   $product_id       Product ID.
	 * @param array $variation_images Array of variation image configurations.
	 * @param array $messages         Messages array to append to.
	 */
	protected function assign_variation_images( $product_id, $variation_images, &$messages ) {
		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			$messages[] = __( 'Variation images can only be assigned to variable products.', 'mcp-ai-wpoos' );
			return;
		}

		$variations = $product->get_children();

		if ( empty( $variations ) ) {
			$messages[] = __( 'No variations found to assign images to.', 'mcp-ai-wpoos' );
			return;
		}

		$assigned_count = 0;

		foreach ( $variation_images as $var_image_config ) {
			if ( empty( $var_image_config['variation_attributes'] ) || empty( $var_image_config['image_id'] ) ) {
				continue;
			}

			$target_attributes = $var_image_config['variation_attributes'];
			$image_id          = absint( $var_image_config['image_id'] );

			// Verify the image exists.
			if ( ! wp_get_attachment_url( $image_id ) ) {
				$messages[] = sprintf(
					/* translators: %d: Image ID */
					__( 'Image ID %d does not exist and was skipped.', 'mcp-ai-wpoos' ),
					$image_id
				);
				continue;
			}

			// Find matching variation.
			foreach ( $variations as $variation_id ) {
				$variation = wc_get_product( $variation_id );

				if ( ! $variation ) {
					continue;
				}

				$variation_attrs = $variation->get_attributes();
				$matches         = true;

				// Check if all target attributes match this variation.
				foreach ( $target_attributes as $attr_name => $attr_value ) {
					$attr_key = 'attribute_' . sanitize_title( $attr_name );

					if ( ! isset( $variation_attrs[ $attr_key ] ) ||
						sanitize_title( $variation_attrs[ $attr_key ] ) !== sanitize_title( $attr_value ) ) {
						$matches = false;
						break;
					}
				}

				if ( $matches ) {
					$variation->set_image_id( $image_id );
					$variation->save();
					++$assigned_count;
					break;
				}
			}
		}

		if ( $assigned_count > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: Number of variations */
				_n(
					'Image assigned to %d variation.',
					'Images assigned to %d variations.',
					$assigned_count,
					'mcp-ai-wpoos'
				),
				$assigned_count
			);
		} else {
			$messages[] = __( 'No matching variations found for the provided variation images.', 'mcp-ai-wpoos' );
		}
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

			'risk_level'            => 'standard',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Creates products.
			'local-only',           // No external API calls (except image sideloading).
			'requires-capability',  // Requires product creation capabilities.
			'state-changing',       // Modifies database state.
			'reversible',           // Can be undone by deleting the product.
		);
	}
}
