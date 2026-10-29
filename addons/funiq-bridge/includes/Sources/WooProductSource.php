<?php
/**
 * WooCommerce product source.
 *
 * Backs the /products contract collection with WooCommerce products via the
 * in-process CRUD APIs (WC_Product_Query, WC_Product_Factory, wc_get_product)
 * — the industry-standard approach for a plugin living on the same
 * WordPress install. The REST/Store APIs exist for REMOTE headless
 * consumers and add HTTP + auth overhead in-process; this source uses the
 * CRUD layer directly and emits the same Payload-shaped documents as the
 * native source, so the storefront contract never changes.
 *
 * @package FuniqBridge\Sources
 */

namespace FuniqBridge\Sources;

use FuniqBridge\Schema;
use WC_Product;
use WC_Product_Simple;
use WP_Error;

/**
 * WooCommerce-backed store for the products collection.
 */
class WooProductSource implements ProductSource {

	/**
	 * Source name.
	 *
	 * @return string
	 */
	public function name(): string {
		return Schema::SOURCE_WOO;
	}

	/**
	 * Available when WooCommerce is loaded.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return WooTaxonomy::is_woo_available();
	}

	/**
	 * List published Woo products (one page).
	 *
	 * The list runs on WP_Query over the `product` post type (with WC's own
	 * pricing/rating meta keys) rather than WC_Product_Query: WC's data
	 * store drops `s` and `meta_query` from its query vars, while WP_Query
	 * supports both natively. Documents are hydrated through the WC CRUD
	 * layer (wc_get_product) so reads keep WC's caching + property mapping.
	 *
	 * @param int                           $page  1-based page.
	 * @param int                           $limit Docs per page.
	 * @param array<string, array<string, mixed>> $where Normalized where clause.
	 * @param string|null                   $sort  Raw sort param.
	 * @return array{docs: array<int, array<string, mixed>>, total: int}
	 */
	public function list_products( int $page, int $limit, array $where, ?string $sort ): array {
		if ( ! $this->is_available() ) {
			return array(
				'docs'  => array(),
				'total' => 0,
			);
		}

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'paged'          => $page,
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$this->apply_where_query( $args, $where );
		$this->apply_sort( $args, $sort );

		$query = new \WP_Query( $args );
		$total = (int) $query->found_posts;
		$docs  = array();

		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( $product instanceof WC_Product ) {
				$docs[] = $this->transform( $product );
			}
		}

		return array(
			'docs'  => $docs,
			'total' => $total,
		);
	}

	/**
	 * Fetch one Woo product document.
	 *
	 * @param int $id Product ID.
	 * @return array<string, mixed>|null
	 */
	public function get_product( int $id ): ?array {
		if ( ! $this->is_available() ) {
			return null;
		}
		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product || 'product' !== get_post_type( $id ) ) {
			return null;
		}
		return $this->transform( $product );
	}

	/**
	 * Create a Woo product from contract-shaped data.
	 *
	 * Price semantics: the contract's `price` is the current selling price
	 * and `oldPrice` the strikethrough anchor. Mapped onto Woo as
	 * regular_price = oldPrice (when present and higher), sale_price = price.
	 *
	 * @param array<string, mixed> $data Request body fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_product( array $data ) {
		if ( ! $this->is_available() ) {
			return new WP_Error(
				'funiq_woo_unavailable',
				'WooCommerce is not active.',
				array( 'status' => 501 )
			);
		}

		$product = new WC_Product_Simple();
		$this->apply_fields( $product, $data, true );

		$product_id = $product->save();
		if ( ! $product_id ) {
			return new WP_Error(
				'funiq_create_failed',
				'Failed to create WooCommerce product.',
				array( 'status' => 500 )
			);
		}

		$this->save_attribute_terms( $product_id, $data );

		$doc = $this->get_product( $product_id );
		if ( null === $doc ) {
			return new WP_Error(
				'funiq_not_found',
				'Product not found after save.',
				array( 'status' => 500 )
			);
		}
		return $doc;
	}

	/**
	 * Update a Woo product from contract-shaped data.
	 *
	 * @param int                  $id   Product ID.
	 * @param array<string, mixed> $data Request body fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_product( int $id, array $data ) {
		if ( ! $this->is_available() ) {
			return new WP_Error(
				'funiq_woo_unavailable',
				'WooCommerce is not active.',
				array( 'status' => 501 )
			);
		}

		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error(
				'funiq_not_found',
				'Product not found.',
				array( 'status' => 404 )
			);
		}

		$this->apply_fields( $product, $data, false );
		$product->save();
		$this->save_attribute_terms( $id, $data );

		$doc = $this->get_product( $id );
		if ( null === $doc ) {
			return new WP_Error(
				'funiq_not_found',
				'Product not found after save.',
				array( 'status' => 500 )
			);
		}
		return $doc;
	}

	/**
	 * Delete a Woo product permanently.
	 *
	 * @param int $id Product ID.
	 * @return true|WP_Error
	 */
	public function delete_product( int $id ) {
		if ( ! $this->is_available() ) {
			return new WP_Error(
				'funiq_woo_unavailable',
				'WooCommerce is not active.',
				array( 'status' => 501 )
			);
		}

		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error(
				'funiq_not_found',
				'Product not found.',
				array( 'status' => 404 )
			);
		}

		if ( ! $product->delete( true ) ) {
			return new WP_Error(
				'funiq_delete_failed',
				'Failed to delete WooCommerce product.',
				array( 'status' => 500 )
			);
		}
		return true;
	}

	// -----------------------------------------------------------------------
	// Transformation.
	// -----------------------------------------------------------------------

	/**
	 * Transform a WC_Product into a Payload-shaped product document.
	 *
	 * @param WC_Product $product Product object.
	 * @return array<string, mixed>
	 */
	public function transform( WC_Product $product ): array {
		$id          = $product->get_id();
		$image_id    = (int) $product->get_image_id() ?: null;
		$gallery_ids = array_values( array_filter( array_map( 'intval', $product->get_gallery_image_ids() ) ) );

		$price      = (float) $product->get_price();
		$regular    = (float) $product->get_regular_price();
		$stock_doc  = WooTaxonomy::stock_status_doc( $product->get_stock_status() );
		$category   = WooTaxonomy::first_term( $id, Schema::TAX_WOO_CATEGORY );
		$brand      = WooTaxonomy::first_term( $id, WooTaxonomy::brand_taxonomy() );
		$colors     = WooTaxonomy::color_terms( $id, WooTaxonomy::color_taxonomy() );
		$date_creat = $product->get_date_created();
		$date_mod   = $product->get_date_modified();

		return array(
			'id'           => $id,
			'name'         => $product->get_name(),
			'price'        => $price,
			'oldPrice'     => $regular > $price ? $regular : null,
			'description'  => $product->get_description() ?: $product->get_short_description(),
			'category'     => $category,
			'brand'        => $brand,
			'colors'       => $colors,
			'width'        => (float) $product->get_width(),
			'height'       => (float) $product->get_height(),
			'depth'        => (float) $product->get_length(),
			'rating'       => $this->nullable_float( $product->get_average_rating() ),
			'isBestseller' => $this->is_bestseller( $product ),
			'isFeatured'   => (bool) $product->get_featured(),
			// PWA fields (URLs).
			'image'        => $image_id ? ( wp_get_attachment_url( $image_id ) ?: '' ) : '',
			'images'       => array_values(
				array_filter(
					array_map(
						static function ( int $attachment_id ): string {
							return wp_get_attachment_url( $attachment_id ) ?: '';
						},
						$gallery_ids
					)
				)
			),
			// Admin fields (raw attachment IDs for the uploader).
			'imageId'      => $image_id,
			'imagesIds'    => $gallery_ids,
			'statuses'     => $stock_doc ? array( $stock_doc ) : array(),
			'promotion'    => null,
			'createdAt'    => $date_creat ? $date_creat->date( 'Y-m-d H:i:s' ) : '',
			'updatedAt'    => $date_mod ? $date_mod->date( 'Y-m-d H:i:s' ) : '',
			'source'       => Schema::SOURCE_WOO,
		);
	}

	/**
	 * Bestseller flag — sales-count threshold (filterable; default 10).
	 *
	 * WooCommerce has no native bestseller flag; total sales is the closest
	 * industry-standard signal (top-seller widgets use it).
	 *
	 * @param WC_Product $product Product object.
	 * @return bool
	 */
	private function is_bestseller( WC_Product $product ): bool {
		$threshold = (int) apply_filters( 'funiq_bridge_woo_bestseller_threshold', 10 );
		return (int) $product->get_total_sales() >= $threshold;
	}

	/**
	 * Cast a raw value to float, returning null for empty values.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	private function nullable_float( $value ): ?float {
		return ( '' !== $value && null !== $value ) ? (float) $value : null;
	}

	// -----------------------------------------------------------------------
	// Query translation.
	// -----------------------------------------------------------------------

	/**
	 * Apply the where clause to WP_Query args.
	 *
	 * Supported operators: id.equals, name.like, category.equals/in,
	 * brand.equals/in, colors.equals/in, price.gt/gte/lt/lte.
	 *
	 * @param array<string, mixed> $args  Query args (by reference).
	 * @param array<string, array<string, mixed>> $where Normalized where clause.
	 * @return void
	 */
	private function apply_where_query( array &$args, array $where ): void {
		if ( WhereClause::has( $where, 'id', 'equals' ) ) {
			$id = (int) WhereClause::value( $where, 'id', 'equals', 0 );
			if ( $id > 0 ) {
				$args['p'] = $id;
			}
		}

		if ( WhereClause::has( $where, 'name', 'like' ) ) {
			$args['s'] = sanitize_text_field( (string) WhereClause::value( $where, 'name', 'like', '' ) );
		}

		// Price comparisons against the _price meta (WC product pricing).
		$price_ops = array(
			'gt'  => '>',
			'gte' => '>=',
			'lt'  => '<',
			'lte' => '<=',
		);
		foreach ( $price_ops as $op => $compare ) {
			if ( WhereClause::has( $where, 'price', $op ) ) {
				$args['meta_query'][] = array(
					'key'     => '_price',
					'value'   => (float) WhereClause::value( $where, 'price', $op, 0 ),
					'type'    => 'NUMERIC',
					'compare' => $compare,
				);
			}
		}

		// Term filters (category / brand / colors taxonomies).
		$tax_fields = array(
			'category' => Schema::TAX_WOO_CATEGORY,
			'brand'    => WooTaxonomy::brand_taxonomy(),
			'colors'   => WooTaxonomy::color_taxonomy(),
		);
		foreach ( $tax_fields as $field => $taxonomy ) {
			foreach ( array( 'equals', 'in' ) as $op ) {
				if ( ! WhereClause::has( $where, $field, $op ) ) {
					continue;
				}
				$terms = 'equals' === $op
					? array( (int) WhereClause::value( $where, $field, $op, 0 ) )
					: array_map( 'intval', (array) WhereClause::value( $where, $field, $op, array() ) );
				$terms = array_values( array_filter( $terms ) );
				if ( empty( $terms ) ) {
					continue;
				}
				$args['tax_query'][] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $terms,
				);
			}
		}
	}

	/**
	 * Apply the sort param to WP_Query args.
	 *
	 * WC pricing/rating live in the _price/_wc_average_rating meta keys,
	 * so price/rating sorting uses meta_value_num — identical to the native
	 * source.
	 *
	 * @param array<string, mixed> $args Query args (by reference).
	 * @param string|null          $sort Raw sort param.
	 * @return void
	 */
	private function apply_sort( array &$args, ?string $sort ): void {
		$parsed = SortClause::parse( $sort );

		switch ( $parsed['field'] ) {
			case 'price':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = '_price';
				break;
			case 'rating':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = '_wc_average_rating';
				break;
			case 'name':
				$args['orderby'] = 'title';
				break;
			case 'date':
			default:
				$args['orderby'] = 'date';
				break;
		}

		$args['order'] = $parsed['direction'];
	}

	// -----------------------------------------------------------------------
	// Writes.
	// -----------------------------------------------------------------------

	/**
	 * Apply contract fields onto a WC_Product.
	 *
	 * On create, required fields are defaulted (name, price, publish
	 * status); on update only provided fields are touched.
	 *
	 * @param WC_Product         $product Product object.
	 * @param array<string, mixed> $data  Request body fields.
	 * @param bool               $is_new  Whether this is a create.
	 * @return void
	 */
	private function apply_fields( WC_Product $product, array $data, bool $is_new ): void {
		if ( $is_new || isset( $data['name'] ) ) {
			$product->set_name( sanitize_text_field( $data['name'] ?? '' ) );
		}
		if ( $is_new ) {
			$product->set_status( 'publish' );
			$product->set_reviews_allowed( true );
			$product->set_stock_status( 'instock' );
		}
		if ( isset( $data['description'] ) ) {
			$product->set_description( wp_kses_post( $data['description'] ) );
		}

		// Price mapping: contract price = selling price, oldPrice = anchor.
		if ( $is_new || isset( $data['price'] ) || isset( $data['oldPrice'] ) ) {
			$price = isset( $data['price'] ) ? (float) $data['price'] : (float) $product->get_price();
			$old   = isset( $data['oldPrice'] ) && is_numeric( $data['oldPrice'] ) ? (float) $data['oldPrice'] : 0;

			if ( $old > $price ) {
				$product->set_regular_price( (string) $old );
				$product->set_sale_price( (string) $price );
			} else {
				$product->set_regular_price( (string) $price );
				$product->set_sale_price( '' );
			}
		}

		foreach ( array( 'width' => 'set_width', 'height' => 'set_height', 'depth' => 'set_length' ) as $field => $setter ) {
			if ( isset( $data[ $field ] ) && is_numeric( $data[ $field ] ) ) {
				$product->{$setter}( (string) $data[ $field ] );
			}
		}

		if ( isset( $data['isFeatured'] ) ) {
			$product->set_featured( (bool) $data['isFeatured'] );
		}

		// Images: imageId/imagesIds (admin SPA) or image/images (URLs/IDs).
		$thumbnail = $data['imageId'] ?? $data['image'] ?? null;
		if ( null !== $thumbnail ) {
			if ( is_numeric( $thumbnail ) && (int) $thumbnail > 0 ) {
				$product->set_image_id( (int) $thumbnail );
			} elseif ( empty( $thumbnail ) ) {
				$product->set_image_id( 0 );
			}
		}
		$gallery = $data['imagesIds'] ?? $data['images'] ?? null;
		if ( null !== $gallery && is_array( $gallery ) ) {
			$product->set_gallery_image_ids(
				array_values(
					array_filter(
						array_map(
							static function ( $v ) {
								return is_numeric( $v ) ? (int) $v : 0;
							},
							$gallery
						)
					)
				)
			);
		}

		// Categories.
		$category = $data['category'] ?? null;
		if ( null !== $category ) {
			$term_ids = array();
			if ( is_array( $category ) && isset( $category['id'] ) ) {
				$term_ids = array( (int) $category['id'] );
			} elseif ( is_numeric( $category ) ) {
				$term_ids = array( (int) $category );
			}
			$product->set_category_ids( $term_ids );
		}
	}

	/**
	 * Assign brand/color attribute terms (when the taxonomies exist).
	 *
	 * @param int                  $product_id Product ID.
	 * @param array<string, mixed> $data       Request body fields.
	 * @return void
	 */
	private function save_attribute_terms( int $product_id, array $data ): void {
		$map = array(
			'brand'  => WooTaxonomy::brand_taxonomy(),
			'colors' => WooTaxonomy::color_taxonomy(),
		);

		foreach ( $map as $field => $taxonomy ) {
			if ( ! isset( $data[ $field ] ) || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$value = $data[ $field ];
			$terms = array();
			if ( 'brand' === $field ) {
				if ( is_array( $value ) && isset( $value['id'] ) ) {
					$terms = array( (int) $value['id'] );
				} elseif ( is_numeric( $value ) ) {
					$terms = array( (int) $value );
				}
			} else {
				$terms = array_map(
					static fn( $item ) => (int) ( $item['id'] ?? 0 ),
					(array) $value
				);
			}

			$terms = array_values( array_filter( $terms ) );
			if ( ! empty( $terms ) ) {
				wp_set_object_terms( $product_id, $terms, $taxonomy, false );
			}
		}
	}
}
