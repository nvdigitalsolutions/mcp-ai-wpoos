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

/**
 * Creates draft WooCommerce products using a reference identifier.
 */
class WP_MCP_AI_Tool_Create_Woo_Product implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'The WooCommerce product creation tool is disabled because WooCommerce is not active.', 'wp-mcp-ai' );
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'create_woo_product';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Create WooCommerce Product Draft', 'wp-mcp-ai' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Creates a WooCommerce product draft using merchandising data gathered for a reference number.', 'wp-mcp-ai' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'reference'             => array(
					'type'        => 'string',
					'description' => __( 'Reference identifier for the product. Used as the SKU.', 'wp-mcp-ai' ),
					'minLength'   => 1,
				),
				'product_type'          => array(
					'type'        => 'string',
					'description' => __( 'Product type to create (simple or variable).', 'wp-mcp-ai' ),
					'enum'        => array( 'simple', 'variable' ),
					'default'     => 'simple',
				),
				'brand'                 => array(
					'type'        => 'string',
					'description' => __( 'Brand name associated with the product.', 'wp-mcp-ai' ),
				),
				'title'                 => array(
					'type'        => 'string',
					'description' => __( 'Product title.', 'wp-mcp-ai' ),
				),
				'local_price'           => array(
					'type'        => array( 'string', 'number' ),
					'description' => __( 'Local price for the product. Used as the regular price for simple products.', 'wp-mcp-ai' ),
				),
				'description'           => array(
					'type'        => 'string',
					'description' => __( 'Full product description.', 'wp-mcp-ai' ),
				),
				'description_secondary' => array(
					'type'        => 'string',
					'description' => __( 'Secondary description or marketing copy.', 'wp-mcp-ai' ),
				),
				'brand_page_url'        => array(
					'type'        => 'string',
					'description' => __( 'URL for the brand page to inspect for lifestyle imagery.', 'wp-mcp-ai' ),
					'format'      => 'uri',
				),
				'image_urls'            => array(
					'type'        => 'array',
					'description' => __( 'Explicit product or lifestyle image URLs to sideload.', 'wp-mcp-ai' ),
					'items'       => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'minItems'    => 2,
					'maxItems'    => 10,
				),
			),
			'required'             => array( 'reference' ),
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
			return new WP_Error( 'wp_mcp_ai_woo_missing', __( 'WooCommerce is not active on this site.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to create products.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'edit_products' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create WooCommerce products.', 'wp-mcp-ai' ) );
		}

		$reference_raw = isset( $arguments['reference'] ) ? (string) $arguments['reference'] : '';
		$reference     = function_exists( 'wc_clean' ) ? wc_clean( $reference_raw ) : sanitize_text_field( $reference_raw );

		if ( '' === $reference ) {
			return new WP_Error( 'wp_mcp_ai_missing_reference', __( 'A product reference is required.', 'wp-mcp-ai' ) );
		}

		$product_type = isset( $arguments['product_type'] ) ? sanitize_key( $arguments['product_type'] ) : 'simple';
		if ( ! in_array( $product_type, array( 'simple', 'variable' ), true ) ) {
			$product_type = 'simple';
		}

		$brand = isset( $arguments['brand'] ) ? $this->sanitize_brand( $arguments['brand'] ) : '';
		$title = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		if ( '' === $title ) {
			/* translators: %s product reference */
			$title = sprintf( __( 'Product %s', 'wp-mcp-ai' ), $reference );
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
			$product->set_description( $description );
		}

		if ( '' !== $description2 ) {
			$product->set_short_description( $description2 );
		}

		if ( 'simple' === $product_type && '' !== $local_price ) {
			$product->set_regular_price( $local_price );
			$product->set_price( $local_price );
		}

		$product_id = $product->save();

		if ( ! $product_id ) {
			return new WP_Error( 'wp_mcp_ai_product_not_saved', __( 'The product could not be saved.', 'wp-mcp-ai' ) );
		}

		$saved_product = wc_get_product( $product_id );

		if ( ! $saved_product ) {
			return new WP_Error( 'wp_mcp_ai_product_missing', __( 'The product draft was created but could not be loaded.', 'wp-mcp-ai' ) );
		}

		$messages = array();

		if ( '' !== $brand ) {
			if ( ! $this->assign_brand( $product_id, $brand ) ) {
				$messages[] = __( 'The brand value was stored as product metadata because no brand taxonomy is registered.', 'wp-mcp-ai' );
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
			$messages[] = __( 'No images were attached because none could be discovered.', 'wp-mcp-ai' );
		}

		if ( ! empty( $attachments ) ) {
			$saved_product = wc_get_product( $product_id );
		}

		$response = array(
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
				return new WP_Error( 'wp_mcp_ai_missing_product_class', __( 'Variable product support is unavailable.', 'wp-mcp-ai' ) );
			}

			return new WC_Product_Variable();
		}

		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_product_class', __( 'Simple product support is unavailable.', 'wp-mcp-ai' ) );
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
				$messages[] = sprintf( __( 'No images could be extracted from %s.', 'wp-mcp-ai' ), esc_url( $arguments['brand_page_url'] ) );
			}
		}

		$urls = array_values( array_unique( $urls ) );

		if ( count( $urls ) > 10 ) {
			$urls       = array_slice( $urls, 0, 10 );
			$messages[] = __( 'Only the first 10 images were attached to match the gallery limit.', 'wp-mcp-ai' );
		}

		if ( count( $urls ) > 0 && count( $urls ) < 2 ) {
			$messages[] = __( 'Fewer than two images were available; consider supplying additional image URLs.', 'wp-mcp-ai' );
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
			if ( ! class_exists( 'WP_Http' ) ) {
				require_once ABSPATH . WPINC . '/class-http.php';
			}
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
					__( 'Image %s could not be imported.', 'wp-mcp-ai' ),
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
