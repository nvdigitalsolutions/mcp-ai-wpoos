<?php
/**
 * Trait for formatting product data as rich markdown cards in chat responses.
 *
 * Provides methods to generate structured, visually appealing product card
 * markdown that renders in the chat client via the markdown service. Supports
 * multiple product data sources (WooCommerce, Shopify, Flowhub, EZuite ERP).
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait WP_MCP_AI_Tool_Product_Card
 *
 * Generates rich markdown product cards for chat display. Cards include
 * product images, pricing with sale indicators, stock status badges,
 * key metadata, and action links. Designed to work with the existing
 * markdown rendering pipeline (marked.js + DOMPurify).
 *
 * Usage:
 * ```php
 * class My_Product_Tool implements WP_MCP_AI_Tool_Interface {
 *     use WP_MCP_AI_Tool_Product_Card;
 *
 *     public function execute( array $arguments, array $context ) {
 *         $products = $this->fetch_products();
 *         $cards    = $this->format_product_cards( $products, 'woocommerce' );
 *         return array(
 *             'message'  => $cards,
 *             'products' => $products,
 *         );
 *     }
 * }
 * ```
 */
trait WP_MCP_AI_Tool_Product_Card {

	/**
	 * Format multiple products as rich markdown cards.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $products Array of product data arrays/objects.
	 * @param string $source   Source identifier: 'woocommerce', 'shopify', 'flowhub', 'ezuite', or 'generic'.
	 * @param array  $options  Optional. Formatting options.
	 * @return string Markdown-formatted product cards.
	 */
	protected function format_product_cards( $products, $source = 'generic', $options = array() ) {
		$defaults = array(
			'show_images'      => true,
			'show_description' => true,
			'max_description'  => 120,
			'currency_symbol'  => '$',
			'source_label'     => '',
		);

		$options = wp_parse_args( $options, $defaults );

		if ( empty( $products ) ) {
			return '';
		}

		$count = count( $products );
		$cards = array();

		foreach ( $products as $product ) {
			$normalized = $this->normalize_product_data( $product, $source );
			$cards[]    = $this->render_single_product_card( $normalized, $options );
		}

		$source_label = $options['source_label'];
		if ( empty( $source_label ) ) {
			$source_label = $this->get_source_label( $source );
		}

		$header = sprintf(
			/* translators: 1: number of products, 2: source label */
			__( '### 📦 %1$d Product(s) from %2$s', 'mcp-ai-wpoos' ),
			$count,
			$source_label
		);

		return $header . "\n\n" . implode( "\n\n---\n\n", $cards );
	}

	/**
	 * Format a single product as a rich markdown card.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $product Product data array/object.
	 * @param string $source  Source identifier.
	 * @param array  $options Optional. Formatting options.
	 * @return string Markdown-formatted product card.
	 */
	protected function format_single_product_card( $product, $source = 'generic', $options = array() ) {
		$defaults = array(
			'show_images'      => true,
			'show_description' => true,
			'max_description'  => 200,
			'currency_symbol'  => '$',
			'source_label'     => '',
		);

		$options    = wp_parse_args( $options, $defaults );
		$normalized = $this->normalize_product_data( $product, $source );

		return $this->render_single_product_card( $normalized, $options );
	}

	/**
	 * Render a single normalized product as a markdown card.
	 *
	 * @since 1.2.0
	 *
	 * @param array $product Normalized product data.
	 * @param array $options Formatting options.
	 * @return string Markdown card string.
	 */
	protected function render_single_product_card( $product, $options ) {
		$lines = array();

		// Product image (if available and enabled).
		if ( $options['show_images'] && ! empty( $product['image_url'] ) ) {
			$alt     = ! empty( $product['name'] ) ? $product['name'] : __( 'Product image', 'mcp-ai-wpoos' );
			$lines[] = sprintf( '![%s](%s)', $this->escape_markdown( $alt ), esc_url( $product['image_url'] ) );
			$lines[] = '';
		}

		// Product name as heading.
		$name    = ! empty( $product['name'] ) ? $product['name'] : __( 'Untitled Product', 'mcp-ai-wpoos' );
		$lines[] = sprintf( '**🛍️ %s**', $this->escape_markdown( $name ) );

		// Price line.
		$price_line = $this->format_price_line( $product, $options );
		if ( ! empty( $price_line ) ) {
			$lines[] = $price_line;
		}

		// Stock status line.
		$stock_line = $this->format_stock_line( $product );
		if ( ! empty( $stock_line ) ) {
			$lines[] = $stock_line;
		}

		// Metadata line (SKU, type, vendor, category).
		$meta_line = $this->format_metadata_line( $product );
		if ( ! empty( $meta_line ) ) {
			$lines[] = $meta_line;
		}

		// Source-specific details.
		$extra_lines = $this->format_source_specific_details( $product );
		if ( ! empty( $extra_lines ) ) {
			foreach ( $extra_lines as $extra ) {
				$lines[] = $extra;
			}
		}

		// Short description.
		if ( $options['show_description'] && ! empty( $product['description'] ) ) {
			$desc = wp_strip_all_tags( $product['description'] );
			if ( strlen( $desc ) > $options['max_description'] ) {
				$desc = mb_substr( $desc, 0, $options['max_description'] ) . '…';
			}
			$lines[] = '';
			$lines[] = sprintf( '> %s', $this->escape_markdown( $desc ) );
		}

		// Action links.
		$links = $this->format_action_links( $product );
		if ( ! empty( $links ) ) {
			$lines[] = '';
			$lines[] = $links;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Format the price line for a product card.
	 *
	 * @since 1.2.0
	 *
	 * @param array $product Normalized product data.
	 * @param array $options Formatting options.
	 * @return string Formatted price line.
	 */
	protected function format_price_line( $product, $options ) {
		$currency = $options['currency_symbol'];
		$parts    = array();

		if ( ! empty( $product['on_sale'] ) && ! empty( $product['sale_price'] ) ) {
			$parts[] = sprintf(
				'💰 **%s%s** ~~%s%s~~',
				$currency,
				$this->format_price_number( $product['sale_price'] ),
				$currency,
				$this->format_price_number( $product['regular_price'] )
			);
		} elseif ( ! empty( $product['price'] ) ) {
			$parts[] = sprintf( '💰 **%s%s**', $currency, $this->format_price_number( $product['price'] ) );
		} elseif ( ! empty( $product['price_range'] ) ) {
			$parts[] = sprintf( '💰 **%s**', $this->escape_markdown( $product['price_range'] ) );
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return implode( '', $parts );
	}

	/**
	 * Format the stock status line.
	 *
	 * @since 1.2.0
	 *
	 * @param array $product Normalized product data.
	 * @return string Formatted stock status line.
	 */
	protected function format_stock_line( $product ) {
		$status = isset( $product['stock_status'] ) ? $product['stock_status'] : '';

		if ( empty( $status ) && ! isset( $product['stock_quantity'] ) ) {
			return '';
		}

		// Normalize stock status values across sources.
		$status_lower = strtolower( $status );

		switch ( $status_lower ) {
			case 'instock':
			case 'in_stock':
			case 'active':
			case 'available':
				$badge = '🟢 ' . __( 'In Stock', 'mcp-ai-wpoos' );
				break;

			case 'outofstock':
			case 'out_of_stock':
			case 'unavailable':
				$badge = '🔴 ' . __( 'Out of Stock', 'mcp-ai-wpoos' );
				break;

			case 'onbackorder':
			case 'on_backorder':
			case 'backorder':
				$badge = '🟡 ' . __( 'On Backorder', 'mcp-ai-wpoos' );
				break;

			default:
				if ( ! empty( $status ) ) {
					$badge = '⚪ ' . $this->escape_markdown( ucfirst( $status ) );
				} elseif ( isset( $product['stock_quantity'] ) ) {
					$qty = floatval( $product['stock_quantity'] );
					if ( $qty > 0 ) {
						$badge = '🟢 ' . __( 'In Stock', 'mcp-ai-wpoos' );
					} else {
						$badge = '🔴 ' . __( 'Out of Stock', 'mcp-ai-wpoos' );
					}
				} else {
					return '';
				}
		}

		// Append quantity if available.
		if ( isset( $product['stock_quantity'] ) ) {
			$qty    = floatval( $product['stock_quantity'] );
			$badge .= sprintf(
				' (%s)',
				/* translators: %s: stock quantity */
				sprintf( __( '%s available', 'mcp-ai-wpoos' ), number_format_i18n( $qty ) )
			);
		}

		return $badge;
	}

	/**
	 * Format the metadata line (SKU, type, vendor, category).
	 *
	 * @since 1.2.0
	 *
	 * @param array $product Normalized product data.
	 * @return string Formatted metadata line.
	 */
	protected function format_metadata_line( $product ) {
		$parts = array();

		if ( ! empty( $product['sku'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: product SKU */
				__( 'SKU: `%s`', 'mcp-ai-wpoos' ),
				$this->escape_markdown( $product['sku'] )
			);
		}

		if ( ! empty( $product['type'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: product type */
				__( 'Type: %s', 'mcp-ai-wpoos' ),
				$this->escape_markdown( ucfirst( $product['type'] ) )
			);
		}

		if ( ! empty( $product['vendor'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: vendor name */
				__( 'Vendor: %s', 'mcp-ai-wpoos' ),
				$this->escape_markdown( $product['vendor'] )
			);
		}

		if ( ! empty( $product['category'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: category name */
				__( 'Category: %s', 'mcp-ai-wpoos' ),
				$this->escape_markdown( $product['category'] )
			);
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Format source-specific details for the product card.
	 *
	 * @since 1.2.0
	 *
	 * @param array $product Normalized product data.
	 * @return array Array of detail lines.
	 */
	protected function format_source_specific_details( $product ) {
		$lines  = array();
		$source = isset( $product['_source'] ) ? $product['_source'] : '';

		switch ( $source ) {
			case 'flowhub':
				// Cannabis-specific fields.
				if ( ! empty( $product['thc_content'] ) ) {
					$lines[] = sprintf( '🧪 THC: %s%%', $this->escape_markdown( $product['thc_content'] ) );
				}
				if ( ! empty( $product['cbd_content'] ) ) {
					$lines[] = sprintf( '🧪 CBD: %s%%', $this->escape_markdown( $product['cbd_content'] ) );
				}
				if ( ! empty( $product['strain_type'] ) ) {
					$lines[] = sprintf( '🌿 %s', $this->escape_markdown( ucfirst( $product['strain_type'] ) ) );
				}
				break;

			case 'ezuite':
				// ERP-specific fields.
				if ( ! empty( $product['location_code'] ) ) {
					/* translators: %s: location code */
					$lines[] = sprintf( __( '📍 Location: %s', 'mcp-ai-wpoos' ), $this->escape_markdown( $product['location_code'] ) );
				}
				if ( ! empty( $product['uom'] ) ) {
					/* translators: %s: unit of measure */
					$lines[] = sprintf( __( '📏 UOM: %s', 'mcp-ai-wpoos' ), $this->escape_markdown( $product['uom'] ) );
				}
				if ( isset( $product['unit_cost'] ) && $product['unit_cost'] > 0 ) {
					/* translators: %s: unit cost */
					$lines[] = sprintf( __( '📊 Cost: $%s', 'mcp-ai-wpoos' ), $this->format_price_number( $product['unit_cost'] ) );
				}
				break;

			case 'shopify':
				// Shopify-specific: total inventory across locations.
				if ( isset( $product['total_inventory'] ) && '' !== $product['total_inventory'] ) {
					/* translators: %s: total inventory */
					$lines[] = sprintf( __( '📦 Total Inventory: %s', 'mcp-ai-wpoos' ), number_format_i18n( intval( $product['total_inventory'] ) ) );
				}
				if ( ! empty( $product['tags'] ) && is_array( $product['tags'] ) ) {
					$tags    = array_slice( $product['tags'], 0, 5 );
					$lines[] = '🏷️ ' . implode( ', ', array_map( array( $this, 'escape_markdown' ), $tags ) );
				}
				break;

			case 'woocommerce':
				// WooCommerce-specific: sale badge, parent info for variations.
				if ( ! empty( $product['on_sale'] ) ) {
					$lines[] = '🏷️ **' . __( 'On Sale!', 'mcp-ai-wpoos' ) . '**';
				}
				if ( ! empty( $product['parent_name'] ) ) {
					/* translators: %s: parent product name */
					$lines[] = sprintf( __( '↳ Variation of: %s', 'mcp-ai-wpoos' ), $this->escape_markdown( $product['parent_name'] ) );
				}
				if ( ! empty( $product['attributes'] ) ) {
					$attr_parts = array();
					foreach ( $product['attributes'] as $attr_key => $attr_value ) {
						if ( is_string( $attr_value ) && ! empty( $attr_value ) ) {
							$attr_parts[] = ucfirst( str_replace( array( 'pa_', '-', '_' ), array( '', ' ', ' ' ), $attr_key ) ) . ': ' . $attr_value;
						}
					}
					if ( ! empty( $attr_parts ) ) {
						$lines[] = '🔖 ' . implode( ' · ', array_map( array( $this, 'escape_markdown' ), $attr_parts ) );
					}
				}
				break;
		}

		return $lines;
	}

	/**
	 * Format action links for a product card.
	 *
	 * @since 1.2.0
	 *
	 * @param array $product Normalized product data.
	 * @return string Formatted action links.
	 */
	protected function format_action_links( $product ) {
		$links = array();

		if ( ! empty( $product['permalink'] ) ) {
			$links[] = sprintf(
				'[%s](%s)',
				__( 'View Product →', 'mcp-ai-wpoos' ),
				esc_url( $product['permalink'] )
			);
		}

		if ( ! empty( $product['edit_url'] ) ) {
			$links[] = sprintf(
				'[%s](%s)',
				__( 'Edit', 'mcp-ai-wpoos' ),
				esc_url( $product['edit_url'] )
			);
		}

		if ( empty( $links ) ) {
			return '';
		}

		return implode( ' · ', $links );
	}

	/**
	 * Normalize product data from different sources into a common format.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed  $product Raw product data (array or object).
	 * @param string $source  Source identifier.
	 * @return array Normalized product data.
	 */
	protected function normalize_product_data( $product, $source ) {
		// Convert object to array.
		if ( is_object( $product ) ) {
			$product = (array) $product;
		}

		if ( ! is_array( $product ) ) {
			return array( '_source' => $source );
		}

		switch ( $source ) {
			case 'woocommerce':
				return $this->normalize_woocommerce_product( $product );

			case 'shopify':
				return $this->normalize_shopify_product( $product );

			case 'flowhub':
				return $this->normalize_flowhub_product( $product );

			case 'ezuite':
				return $this->normalize_ezuite_product( $product );

			default:
				return $this->normalize_generic_product( $product );
		}
	}

	/**
	 * Normalize WooCommerce product data.
	 *
	 * Handles both local WC_Product objects and remote REST API data.
	 *
	 * @since 1.2.0
	 *
	 * @param array $product Raw WooCommerce product data.
	 * @return array Normalized product data.
	 */
	protected function normalize_woocommerce_product( $product ) {
		$image_url = '';
		// Handle images array (remote WC REST API format).
		if ( ! empty( $product['images'] ) && is_array( $product['images'] ) ) {
			$first_image = reset( $product['images'] );
			if ( is_object( $first_image ) && isset( $first_image->src ) ) {
				$image_url = $first_image->src;
			} elseif ( is_array( $first_image ) && isset( $first_image['src'] ) ) {
				$image_url = $first_image['src'];
			}
		}
		// Handle single image field (variation format).
		if ( empty( $image_url ) && ! empty( $product['image'] ) ) {
			if ( is_object( $product['image'] ) && isset( $product['image']->src ) ) {
				$image_url = $product['image']->src;
			} elseif ( is_array( $product['image'] ) && isset( $product['image']['src'] ) ) {
				$image_url = $product['image']['src'];
			}
		}

		// Determine on_sale status.
		$on_sale = false;
		if ( isset( $product['on_sale'] ) ) {
			$on_sale = (bool) $product['on_sale'];
		} elseif ( ! empty( $product['sale_price'] ) && ! empty( $product['regular_price'] ) ) {
			$on_sale = floatval( $product['sale_price'] ) < floatval( $product['regular_price'] );
		}

		// Build category string.
		$category = '';
		if ( ! empty( $product['categories'] ) && is_array( $product['categories'] ) ) {
			$cat_names = array();
			foreach ( $product['categories'] as $cat ) {
				if ( is_object( $cat ) && isset( $cat->name ) ) {
					$cat_names[] = $cat->name;
				} elseif ( is_array( $cat ) && isset( $cat['name'] ) ) {
					$cat_names[] = $cat['name'];
				}
			}
			$category = implode( ', ', $cat_names );
		}

		return array(
			'_source'        => 'woocommerce',
			'name'           => isset( $product['name'] ) ? $product['name'] : '',
			'price'          => isset( $product['price'] ) ? $product['price'] : '',
			'regular_price'  => isset( $product['regular_price'] ) ? $product['regular_price'] : '',
			'sale_price'     => isset( $product['sale_price'] ) ? $product['sale_price'] : '',
			'on_sale'        => $on_sale,
			'stock_status'   => isset( $product['stock_status'] ) ? $product['stock_status'] : '',
			'stock_quantity' => isset( $product['stock_quantity'] ) ? $product['stock_quantity'] : null,
			'sku'            => isset( $product['sku'] ) ? $product['sku'] : '',
			'type'           => isset( $product['type'] ) ? $product['type'] : '',
			'status'         => isset( $product['status'] ) ? $product['status'] : '',
			'permalink'      => isset( $product['permalink'] ) ? $product['permalink'] : '',
			'image_url'      => $image_url,
			'description'    => isset( $product['short_description'] ) ? $product['short_description'] : ( isset( $product['description'] ) ? $product['description'] : '' ),
			'category'       => $category,
			'vendor'         => '',
			'parent_name'    => isset( $product['parent_name'] ) ? $product['parent_name'] : '',
			'parent_id'      => isset( $product['parent_id'] ) ? $product['parent_id'] : '',
			'attributes'     => isset( $product['attributes'] ) ? $product['attributes'] : array(),
			'edit_url'       => ! empty( $product['id'] ) ? admin_url( 'post.php?post=' . absint( $product['id'] ) . '&action=edit' ) : '',
		);
	}

	/**
	 * Normalize Shopify product data.
	 *
	 * @since 1.2.0
	 *
	 * @param array $product Raw Shopify product data (already normalized by the tool).
	 * @return array Normalized product data.
	 */
	protected function normalize_shopify_product( $product ) {
		$image_url = '';
		if ( ! empty( $product['images'] ) && is_array( $product['images'] ) ) {
			$first_image = reset( $product['images'] );
			if ( is_array( $first_image ) && isset( $first_image['url'] ) ) {
				$image_url = $first_image['url'];
			} elseif ( is_array( $first_image ) && isset( $first_image['originalSrc'] ) ) {
				$image_url = $first_image['originalSrc'];
			} elseif ( is_array( $first_image ) && isset( $first_image['src'] ) ) {
				$image_url = $first_image['src'];
			}
		}
		// Fallback: check featuredImage field.
		if ( empty( $image_url ) && ! empty( $product['featuredImage'] ) ) {
			if ( is_array( $product['featuredImage'] ) ) {
				if ( isset( $product['featuredImage']['url'] ) ) {
					$image_url = $product['featuredImage']['url'];
				} elseif ( isset( $product['featuredImage']['originalSrc'] ) ) {
					$image_url = $product['featuredImage']['originalSrc'];
				}
			}
		}

		// Parse price range.
		$price       = '';
		$price_range = '';
		if ( ! empty( $product['price_range'] ) && is_array( $product['price_range'] ) ) {
			$min = isset( $product['price_range']['minVariantPrice']['amount'] ) ? $product['price_range']['minVariantPrice']['amount'] : '';
			$max = isset( $product['price_range']['maxVariantPrice']['amount'] ) ? $product['price_range']['maxVariantPrice']['amount'] : '';

			if ( ! empty( $min ) && ! empty( $max ) ) {
				if ( $min === $max ) {
					$price = $min;
				} else {
					$price_range = sprintf( '$%s – $%s', $this->format_price_number( $min ), $this->format_price_number( $max ) );
				}
			} elseif ( ! empty( $min ) ) {
				$price = $min;
			}
		}

		// Get price from first variant if no price range.
		if ( empty( $price ) && empty( $price_range ) && ! empty( $product['variants'] ) && is_array( $product['variants'] ) ) {
			$first_variant = reset( $product['variants'] );
			if ( is_array( $first_variant ) && isset( $first_variant['price'] ) ) {
				$price = $first_variant['price'];
			}
		}

		// Map Shopify status to stock status.
		$stock_status = '';
		if ( isset( $product['status'] ) ) {
			$shopify_status = strtoupper( $product['status'] );
			if ( 'ACTIVE' === $shopify_status ) {
				$stock_status = 'instock';
			} elseif ( 'ARCHIVED' === $shopify_status ) {
				$stock_status = 'outofstock';
			} elseif ( 'DRAFT' === $shopify_status ) {
				$stock_status = 'draft';
			}
		}

		return array(
			'_source'         => 'shopify',
			'name'            => isset( $product['title'] ) ? $product['title'] : '',
			'price'           => $price,
			'regular_price'   => '',
			'sale_price'      => '',
			'on_sale'         => false,
			'price_range'     => $price_range,
			'stock_status'    => $stock_status,
			'stock_quantity'  => null,
			'total_inventory' => isset( $product['total_inventory'] ) ? $product['total_inventory'] : '',
			'sku'             => '',
			'type'            => isset( $product['product_type'] ) ? $product['product_type'] : '',
			'status'          => isset( $product['status'] ) ? $product['status'] : '',
			'permalink'       => ! empty( $product['handle'] ) ? $product['handle'] : '',
			'image_url'       => $image_url,
			'description'     => '',
			'category'        => '',
			'vendor'          => isset( $product['vendor'] ) ? $product['vendor'] : '',
			'tags'            => isset( $product['tags'] ) ? $product['tags'] : array(),
		);
	}

	/**
	 * Normalize Flowhub product data.
	 *
	 * @since 1.2.0
	 *
	 * @param array $product Raw Flowhub product data.
	 * @return array Normalized product data.
	 */
	protected function normalize_flowhub_product( $product ) {
		// Flowhub products may use various field names.
		$name = '';
		foreach ( array( 'name', 'productName', 'product_name', 'title' ) as $key ) {
			if ( ! empty( $product[ $key ] ) ) {
				$name = $product[ $key ];
				break;
			}
		}

		$price = '';
		foreach ( array( 'price', 'unitPrice', 'unit_price', 'retailPrice', 'retail_price' ) as $key ) {
			if ( isset( $product[ $key ] ) && '' !== $product[ $key ] && null !== $product[ $key ] ) {
				$price = $product[ $key ];
				break;
			}
		}

		$stock_qty = null;
		foreach ( array( 'quantity', 'quantityOnHand', 'quantity_on_hand', 'stock', 'inventory' ) as $key ) {
			if ( isset( $product[ $key ] ) && '' !== $product[ $key ] && null !== $product[ $key ] ) {
				$stock_qty = $product[ $key ];
				break;
			}
		}

		$image_url = '';
		foreach ( array( 'image', 'imageUrl', 'image_url', 'thumbnail', 'photo' ) as $key ) {
			if ( ! empty( $product[ $key ] ) && is_string( $product[ $key ] ) ) {
				$image_url = $product[ $key ];
				break;
			}
		}

		return array(
			'_source'        => 'flowhub',
			'name'           => $name,
			'price'          => $price,
			'regular_price'  => '',
			'sale_price'     => '',
			'on_sale'        => false,
			'stock_status'   => '',
			'stock_quantity' => $stock_qty,
			'sku'            => isset( $product['sku'] ) ? $product['sku'] : ( isset( $product['barcode'] ) ? $product['barcode'] : '' ),
			'type'           => isset( $product['category'] ) ? $product['category'] : ( isset( $product['productType'] ) ? $product['productType'] : '' ),
			'status'         => '',
			'permalink'      => '',
			'image_url'      => $image_url,
			'description'    => isset( $product['description'] ) ? $product['description'] : '',
			'category'       => isset( $product['category'] ) ? $product['category'] : '',
			'vendor'         => isset( $product['brand'] ) ? $product['brand'] : ( isset( $product['vendor'] ) ? $product['vendor'] : '' ),
			'thc_content'    => isset( $product['thcContent'] ) ? $product['thcContent'] : ( isset( $product['thc_content'] ) ? $product['thc_content'] : '' ),
			'cbd_content'    => isset( $product['cbdContent'] ) ? $product['cbdContent'] : ( isset( $product['cbd_content'] ) ? $product['cbd_content'] : '' ),
			'strain_type'    => isset( $product['strainType'] ) ? $product['strainType'] : ( isset( $product['strain_type'] ) ? $product['strain_type'] : '' ),
		);
	}

	/**
	 * Normalize EZuite ERP product data.
	 *
	 * @since 1.2.0
	 *
	 * @param array $product Raw EZuite product data.
	 * @return array Normalized product data.
	 */
	protected function normalize_ezuite_product( $product ) {
		$stock_qty = isset( $product['quantity_on_hand'] ) ? floatval( $product['quantity_on_hand'] ) : null;

		return array(
			'_source'        => 'ezuite',
			'name'           => isset( $product['description'] ) ? $product['description'] : ( isset( $product['item_code'] ) ? $product['item_code'] : '' ),
			'price'          => isset( $product['unit_price'] ) ? $product['unit_price'] : '',
			'regular_price'  => '',
			'sale_price'     => '',
			'on_sale'        => false,
			'stock_status'   => '',
			'stock_quantity' => $stock_qty,
			'sku'            => isset( $product['item_code'] ) ? $product['item_code'] : '',
			'type'           => '',
			'status'         => isset( $product['status'] ) ? $product['status'] : '',
			'permalink'      => '',
			'image_url'      => '',
			'description'    => '',
			'category'       => isset( $product['category'] ) ? $product['category'] : '',
			'vendor'         => isset( $product['vendor_code'] ) ? $product['vendor_code'] : '',
			'location_code'  => isset( $product['location_code'] ) ? $product['location_code'] : '',
			'uom'            => isset( $product['uom'] ) ? $product['uom'] : '',
			'unit_cost'      => isset( $product['unit_cost'] ) ? $product['unit_cost'] : 0,
			'barcode'        => isset( $product['barcode'] ) ? $product['barcode'] : '',
		);
	}

	/**
	 * Normalize a generic product data structure.
	 *
	 * @since 1.2.0
	 *
	 * @param array $product Raw product data.
	 * @return array Normalized product data.
	 */
	protected function normalize_generic_product( $product ) {
		$name = '';
		foreach ( array( 'name', 'title', 'product_name', 'description', 'item_code' ) as $key ) {
			if ( ! empty( $product[ $key ] ) && is_string( $product[ $key ] ) ) {
				$name = $product[ $key ];
				break;
			}
		}

		$price = '';
		foreach ( array( 'price', 'unit_price', 'regular_price', 'amount' ) as $key ) {
			if ( isset( $product[ $key ] ) && '' !== $product[ $key ] ) {
				$price = $product[ $key ];
				break;
			}
		}

		return array(
			'_source'        => 'generic',
			'name'           => $name,
			'price'          => $price,
			'regular_price'  => isset( $product['regular_price'] ) ? $product['regular_price'] : '',
			'sale_price'     => isset( $product['sale_price'] ) ? $product['sale_price'] : '',
			'on_sale'        => isset( $product['on_sale'] ) ? (bool) $product['on_sale'] : false,
			'stock_status'   => isset( $product['stock_status'] ) ? $product['stock_status'] : '',
			'stock_quantity' => isset( $product['stock_quantity'] ) ? $product['stock_quantity'] : null,
			'sku'            => isset( $product['sku'] ) ? $product['sku'] : '',
			'type'           => isset( $product['type'] ) ? $product['type'] : '',
			'status'         => isset( $product['status'] ) ? $product['status'] : '',
			'permalink'      => isset( $product['permalink'] ) ? $product['permalink'] : '',
			'image_url'      => isset( $product['image_url'] ) ? $product['image_url'] : '',
			'description'    => isset( $product['description'] ) ? $product['description'] : '',
			'category'       => isset( $product['category'] ) ? $product['category'] : '',
			'vendor'         => isset( $product['vendor'] ) ? $product['vendor'] : '',
		);
	}

	/**
	 * Get human-readable source label.
	 *
	 * @since 1.2.0
	 *
	 * @param string $source Source identifier.
	 * @return string Human-readable source label.
	 */
	protected function get_source_label( $source ) {
		$labels = array(
			'woocommerce' => 'WooCommerce',
			'shopify'     => 'Shopify',
			'flowhub'     => 'Flowhub',
			'ezuite'      => 'EZuite ERP',
			'generic'     => __( 'Store', 'mcp-ai-wpoos' ),
		);

		return isset( $labels[ $source ] ) ? $labels[ $source ] : ucfirst( $source );
	}

	/**
	 * Format a price number consistently.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $price Price value.
	 * @return string Formatted price string.
	 */
	protected function format_price_number( $price ) {
		if ( '' === $price || null === $price ) {
			return '';
		}

		$numeric = floatval( $price );

		// Show two decimal places for prices.
		return number_format( $numeric, 2, '.', ',' );
	}

	/**
	 * Escape markdown special characters in text.
	 *
	 * @since 1.2.0
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped text.
	 */
	protected function escape_markdown( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}

		// Escape characters that have special meaning in markdown.
		// We only escape characters that could break card layout.
		$text = str_replace(
			array( '[', ']', '(', ')', '`' ),
			array( '\\[', '\\]', '\\(', '\\)', '\\`' ),
			$text
		);

		return $text;
	}
}
