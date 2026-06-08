<?php
/**
 * Products REST controller — Payload-compatible endpoints.
 *
 * @package FuniqBridge\REST
 */

namespace FuniqBridge\REST;

use FuniqBridge\Schema;
use FuniqBridge\Transformers\ProductTransformer;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles /funiq/v1/products and /funiq/v1/products/{id}.
 *
 * GET is public (matches Payload's read: () => true).
 * POST/PUT/DELETE require manage_funiq capability.
 */
class ProductsController extends BaseController {

	/** @var string */
	protected $namespace = 'funiq/v1';

	/** @var string */
	protected $rest_base = 'products';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Collection: GET, POST.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( true ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// Single: GET, PUT, DELETE.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'item_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	// -----------------------------------------------------------------------
	// Collection GET.
	// -----------------------------------------------------------------------

	/**
	 * GET /funiq/v1/products
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$page  = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$limit = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?: 10 ) ) );

		$args = array(
			'post_type'      => Schema::CPT_PRODUCT,
			'post_status'    => 'publish',
			'paged'          => $page,
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$this->apply_where_query( $args, $request->get_param( 'where' ) );

		$query   = new WP_Query( $args );
		$total   = (int) $query->found_posts;
		$docs    = array();
		$transformer = new ProductTransformer();

		foreach ( $query->posts as $post ) {
			$docs[] = $transformer->transform( $post );
		}

		return rest_ensure_response(
			$this->paginatedResponse( $docs, $total, $page, $limit )
		);
	}

	// -----------------------------------------------------------------------
	// Collection POST (create).
	// -----------------------------------------------------------------------

	/**
	 * POST /funiq/v1/products
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$product_id = wp_insert_post(
			array(
				'post_type'    => Schema::CPT_PRODUCT,
				'post_title'   => sanitize_text_field( $request->get_param( 'name' ) ?? '' ),
				'post_content' => wp_kses_post( $request->get_param( 'description' ) ?? '' ),
				'post_status'  => 'publish',
			),
			true
		);

		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}

		$this->save_meta( $product_id, $request->get_json_params() );
		$this->save_taxonomies( $product_id, $request->get_json_params() );

		return $this->build_single_response( $product_id );
	}

	// -----------------------------------------------------------------------
	// Single GET.
	// -----------------------------------------------------------------------

	/**
	 * GET /funiq/v1/products/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$product_id = (int) $request['id'];
		$post       = get_post( $product_id );

		if ( ! $post || Schema::CPT_PRODUCT !== $post->post_type ) {
			return new WP_Error(
				'funiq_not_found',
				'Product not found.',
				array( 'status' => 404 )
			);
		}

		return $this->build_single_response( $product_id );
	}

	// -----------------------------------------------------------------------
	// Single PUT (update).
	// -----------------------------------------------------------------------

	/**
	 * PUT /funiq/v1/products/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$product_id = (int) $request['id'];
		$post       = get_post( $product_id );

		if ( ! $post || Schema::CPT_PRODUCT !== $post->post_type ) {
			return new WP_Error(
				'funiq_not_found',
				'Product not found.',
				array( 'status' => 404 )
			);
		}

		$data = $request->get_json_params();

		wp_update_post(
			array(
				'ID'           => $product_id,
				'post_title'   => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : $post->post_title,
				'post_content' => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : $post->post_content,
			)
		);

		$this->save_meta( $product_id, $data );
		$this->save_taxonomies( $product_id, $data );

		return $this->build_single_response( $product_id );
	}

	// -----------------------------------------------------------------------
	// Single DELETE.
	// -----------------------------------------------------------------------

	/**
	 * DELETE /funiq/v1/products/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$product_id = (int) $request['id'];
		$post       = get_post( $product_id );

		if ( ! $post || Schema::CPT_PRODUCT !== $post->post_type ) {
			return new WP_Error(
				'funiq_not_found',
				'Product not found.',
				array( 'status' => 404 )
			);
		}

		$result = wp_delete_post( $product_id, true );
		if ( ! $result ) {
			return new WP_Error(
				'funiq_delete_failed',
				'Failed to delete product.',
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( null, 204 );
	}

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * Save product meta fields from request data.
	 *
	 * @param int                    $product_id Post ID.
	 * @param array<string, mixed>   $data       Request body.
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
			Schema::META_PROMOTION_ID  => array( $data, 'promotion', 'intval' ),
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
	 * @param int                    $product_id Post ID.
	 * @param array<string, mixed>   $data       Request body.
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
				$term_ids = null === $value ? array() : array( (int) $value['id'] ?? 0 );
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

	/**
	 * Apply Payload-style where query to WP_Query args.
	 *
	 * @param array<string, mixed> $args  WP_Query args (by reference).
	 * @param mixed                $where Where param from request.
	 * @return void
	 */
	private function apply_where_query( array &$args, $where ): void {
		if ( empty( $where ) || ! is_array( $where ) ) {
			return;
		}

		foreach ( $where as $field => $operators ) {
			if ( ! is_array( $operators ) ) {
				continue;
			}
			foreach ( $operators as $operator => $value ) {
				if ( 'id' === $field && 'equals' === $operator ) {
					$args['p'] = (int) $value;
				}
				if ( 'category' === $field && 'equals' === $operator ) {
					$args['tax_query'][] = array(
						'taxonomy' => Schema::TAX_CATEGORY,
						'field'    => 'term_id',
						'terms'    => (int) $value,
					);
				}
			}
		}
	}

	/**
	 * Build a single-product REST response.
	 *
	 * @param int $product_id
	 * @return WP_REST_Response|WP_Error
	 */
	private function build_single_response( int $product_id ) {
		$post = get_post( $product_id );
		if ( ! $post ) {
			return new WP_Error(
				'funiq_not_found',
				'Product not found after save.',
				array( 'status' => 500 )
			);
		}

		$transformer = new ProductTransformer();
		return rest_ensure_response( $transformer->transform( $post ) );
	}

	/**
	 * Get collection params for GET /products.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_collection_params(): array {
		return array(
			'page'  => array(
				'description'       => 'Current page of the collection.',
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'limit' => array(
				'description'       => 'Maximum number of items to return.',
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'where' => array(
				'description'       => 'Payload-style where clause.',
				'type'              => 'object',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_where' ),
			),
		);
	}

	/**
	 * Sanitize the where parameter.
	 *
	 * @param mixed $where Raw where value.
	 * @return array<string, array<string, mixed>>
	 */
	public function sanitize_where( $where ): array {
		if ( ! is_array( $where ) ) {
			return array();
		}
		return $where;
	}

	/**
	 * Get item schema for the product.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'funiq_product',
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'name'         => array( 'type' => 'string', 'required' => true ),
				'price'        => array( 'type' => 'number', 'required' => true ),
				'oldPrice'     => array( 'type' => array( 'number', 'null' ) ),
				'description'  => array( 'type' => 'string' ),
				'category'     => array( 'type' => array( 'object', 'null' ) ),
				'brand'        => array( 'type' => array( 'object', 'null' ) ),
				'colors'       => array( 'type' => 'array' ),
				'width'        => array( 'type' => 'number' ),
				'height'       => array( 'type' => 'number' ),
				'depth'        => array( 'type' => 'number' ),
				'rating'       => array( 'type' => array( 'number', 'null' ) ),
				'isBestseller' => array( 'type' => 'boolean' ),
				'isFeatured'   => array( 'type' => 'boolean' ),
				'image'        => array( 'type' => 'string', 'description' => 'Featured image URL (PWA-facing).' ),
					'imageId'      => array( 'type' => array( 'integer', 'null' ), 'description' => 'Featured image attachment ID (admin-facing).' ),
					'images'       => array( 'type' => 'array', 'description' => 'Gallery image URLs (PWA-facing).' ),
					'imagesIds'    => array( 'type' => 'array', 'description' => 'Gallery attachment IDs (admin-facing).' ),
				'statuses'     => array( 'type' => 'array' ),
				'promotion'    => array( 'type' => array( 'object', 'null' ) ),
				'createdAt'    => array( 'type' => 'string' ),
				'updatedAt'    => array( 'type' => 'string' ),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
