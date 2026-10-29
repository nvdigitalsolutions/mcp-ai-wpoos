<?php
/**
 * Native product source — the built-in funiq_product CPT.
 *
 * Extracted from ProductsController (2026-10-29) so the controller can
 * delegate to whichever sources are active. Behaviour is byte-for-byte the
 * previous implementation: same query args, same where/sort operators, same
 * meta/taxonomy writes, same transformer.
 *
 * @package FuniqBridge\Sources
 */

namespace FuniqBridge\Sources;

use FuniqBridge\Schema;
use FuniqBridge\Transformers\ProductTransformer;
use WP_Error;
use WP_Query;

/**
 * The built-in CPT backing store.
 */
class NativeProductSource implements ProductSource {

	/**
	 * Source name.
	 *
	 * @return string
	 */
	public function name(): string {
		return Schema::SOURCE_NATIVE;
	}

	/**
	 * The built-in store is always available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * List published native products.
	 *
	 * @param int                           $page  1-based page.
	 * @param int                           $limit Docs per page.
	 * @param array<string, array<string, mixed>> $where Normalized where clause.
	 * @param string|null                   $sort  Raw sort param.
	 * @return array{docs: array<int, array<string, mixed>>, total: int}
	 */
	public function list_products( int $page, int $limit, array $where, ?string $sort ): array {
		$args = array(
			'post_type'      => Schema::CPT_PRODUCT,
			'post_status'    => 'publish',
			'paged'          => $page,
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$this->apply_where_query( $args, $where );
		$this->apply_sort( $args, $sort );

		$query       = new WP_Query( $args );
		$total       = (int) $query->found_posts;
		$docs        = array();
		$transformer = new ProductTransformer();

		foreach ( $query->posts as $post ) {
			$docs[] = $transformer->transform( $post );
		}

		return array(
			'docs'  => $docs,
			'total' => $total,
		);
	}

	/**
	 * Fetch one native product document.
	 *
	 * @param int $id Product ID.
	 * @return array<string, mixed>|null
	 */
	public function get_product( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || Schema::CPT_PRODUCT !== $post->post_type ) {
			return null;
		}
		return ( new ProductTransformer() )->transform( $post );
	}

	/**
	 * Create a native product.
	 *
	 * @param array<string, mixed> $data Request body fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_product( array $data ) {
		$product_id = wp_insert_post(
			array(
				'post_type'    => Schema::CPT_PRODUCT,
				'post_title'   => sanitize_text_field( $data['name'] ?? '' ),
				'post_content' => wp_kses_post( $data['description'] ?? '' ),
				'post_status'  => 'publish',
			),
			true
		);

		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}

		$this->save_meta( $product_id, $data );
		$this->save_taxonomies( $product_id, $data );

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
	 * Update a native product.
	 *
	 * @param int                  $id   Product ID.
	 * @param array<string, mixed> $data Request body fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_product( int $id, array $data ) {
		$post = get_post( $id );
		if ( ! $post || Schema::CPT_PRODUCT !== $post->post_type ) {
			return new WP_Error(
				'funiq_not_found',
				'Product not found.',
				array( 'status' => 404 )
			);
		}

		wp_update_post(
			array(
				'ID'           => $id,
				'post_title'   => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : $post->post_title,
				'post_content' => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : $post->post_content,
			)
		);

		$this->save_meta( $id, $data );
		$this->save_taxonomies( $id, $data );

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
	 * Delete a native product permanently.
	 *
	 * @param int $id Product ID.
	 * @return true|WP_Error
	 */
	public function delete_product( int $id ) {
		$post = get_post( $id );
		if ( ! $post || Schema::CPT_PRODUCT !== $post->post_type ) {
			return new WP_Error(
				'funiq_not_found',
				'Product not found.',
				array( 'status' => 404 )
			);
		}

		if ( ! wp_delete_post( $id, true ) ) {
			return new WP_Error(
				'funiq_delete_failed',
				'Failed to delete product.',
				array( 'status' => 500 )
			);
		}
		return true;
	}

	// -----------------------------------------------------------------------
	// Query translation.
	// -----------------------------------------------------------------------

	/**
	 * Apply the where clause to WP_Query args (bridge enhancement B2).
	 *
	 * Supported operators: id.equals, name.like, category.equals/in,
	 * brand.equals/in, colors.equals/in, statuses.equals/in.
	 *
	 * @param array<string, mixed> $args  WP_Query args (by reference).
	 * @param array<string, array<string, mixed>> $where Normalized where clause.
	 * @return void
	 */
	private function apply_where_query( array &$args, array $where ): void {
		$tax_map = array(
			'category' => Schema::TAX_CATEGORY,
			'brand'    => Schema::TAX_BRAND,
			'colors'   => Schema::TAX_COLOR,
			'statuses' => Schema::TAX_STATUS,
		);

		foreach ( $where as $field => $operators ) {
			foreach ( $operators as $operator => $value ) {
				if ( 'id' === $field && 'equals' === $operator ) {
					$args['p'] = (int) $value;
				}

				if ( 'name' === $field && 'like' === $operator ) {
					$args['s'] = sanitize_text_field( (string) $value );
				}

				if ( isset( $tax_map[ $field ] ) && in_array( $operator, array( 'equals', 'in' ), true ) ) {
					$terms = 'equals' === $operator
						? array( (int) $value )
						: array_map( 'intval', (array) $value );
					$terms = array_values( array_filter( $terms ) );
					if ( empty( $terms ) ) {
						continue;
					}
					$args['tax_query'][] = array(
						'taxonomy' => $tax_map[ $field ],
						'field'    => 'term_id',
						'terms'    => $terms,
					);
				}
			}
		}
	}

	/**
	 * Apply the sort param to WP_Query args (bridge enhancement B2).
	 *
	 * @param array<string, mixed> $args WP_Query args (by reference).
	 * @param string|null          $sort Raw sort param.
	 * @return void
	 */
	private function apply_sort( array &$args, ?string $sort ): void {
		$parsed = SortClause::parse( $sort );

		switch ( $parsed['field'] ) {
			case 'price':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = Schema::META_PRICE;
				break;
			case 'rating':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = Schema::META_RATING;
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
	 * Save product meta fields from request data.
	 *
	 * @param int                  $product_id Post ID.
	 * @param array<string, mixed> $data       Request body.
	 * @return void
	 */
	private function save_meta( int $product_id, array $data ): void {
		$meta_map = array(
			Schema::META_PRICE         => array( $data, 'price', 'floatval' ),
			Schema::META_OLD_PRICE     => array( $data, 'oldPrice', 'floatval' ),
			Schema::META_WIDTH         => array( $data, 'width', 'floatval' ),
			Schema::META_HEIGHT        => array( $data, 'height', 'floatval' ),
			Schema::META_DEPTH         => array( $data, 'depth', 'floatval' ),
			Schema::META_RATING        => array( $data, 'rating', 'floatval' ),
			Schema::META_IS_BESTSELLER => array( $data, 'isBestseller', 'boolval' ),
			Schema::META_IS_FEATURED   => array( $data, 'isFeatured', 'boolval' ),
			Schema::META_GALLERY       => array( $data, 'images', null ),
		);

		foreach ( $meta_map as $key => list( $source, $field, $cast ) ) {
			if ( ! array_key_exists( $field, $source ) ) {
				continue;
			}
			$value = $source[ $field ];
			if ( null !== $cast ) {
				$value = call_user_func( $cast, $value );
			}
			update_post_meta( $product_id, $key, $value );
		}

		// Promotion is a single relationship ({ id, ... }) in the SPA payload —
		// extract the ID explicitly. intval() on the object coerces to 1.
		if ( array_key_exists( 'promotion', $data ) ) {
			$promotion = $data['promotion'];
			$promo_id  = 0;
			if ( is_array( $promotion ) && isset( $promotion['id'] ) ) {
				$promo_id = (int) $promotion['id'];
			} elseif ( is_numeric( $promotion ) ) {
				$promo_id = (int) $promotion;
			}
			update_post_meta( $product_id, Schema::META_PROMOTION_ID, $promo_id );
		}

		// Handle featured image — accept either `image` (numeric ID or URL) or
		// `imageId` (numeric ID). The admin SPA sends `imageId` from ImageUpload.
		$thumbnail_id = $data['imageId'] ?? $data['image'] ?? null;
		if ( null !== $thumbnail_id ) {
			if ( is_numeric( $thumbnail_id ) && (int) $thumbnail_id > 0 ) {
				set_post_thumbnail( $product_id, (int) $thumbnail_id );
			} elseif ( empty( $thumbnail_id ) ) {
				delete_post_thumbnail( $product_id );
			}
		}

		// Handle gallery — accept `imagesIds` (array of attachment IDs) or
		// `images` (array of URLs or IDs).
		$gallery_ids = $data['imagesIds'] ?? $data['images'] ?? null;
		if ( null !== $gallery_ids && is_array( $gallery_ids ) ) {
			$gallery_ids = array_filter(
				array_map(
					static function ( $v ) {
						return is_numeric( $v ) ? (int) $v : 0;
					},
					$gallery_ids
				)
			);
			update_post_meta( $product_id, Schema::META_GALLERY, $gallery_ids );
		}
	}

	/**
	 * Save taxonomy terms from request data.
	 *
	 * @param int                  $product_id Post ID.
	 * @param array<string, mixed> $data       Request body.
	 * @return void
	 */
	private function save_taxonomies( int $product_id, array $data ): void {
		$tax_map = array(
			Schema::TAX_CATEGORY => 'category',
			Schema::TAX_BRAND    => 'brand',
			Schema::TAX_COLOR    => 'colors',
			Schema::TAX_STATUS   => 'statuses',
		);

		foreach ( $tax_map as $taxonomy => $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				continue;
			}

			$value = $data[ $field ];

			// Single relationship (category, brand): expect { id, name } or null.
			if ( in_array( $field, array( 'category', 'brand' ), true ) ) {
				$term_ids = null === $value ? array() : array( (int) ( $value['id'] ?? 0 ) );
			} else {
				// hasMany: array of { id, name }.
				$term_ids = array_map(
					static fn( $item ) => (int) ( $item['id'] ?? 0 ),
					(array) $value
				);
			}

			$term_ids = array_filter( $term_ids );
			wp_set_object_terms( $product_id, $term_ids, $taxonomy, false );
		}
	}
}
