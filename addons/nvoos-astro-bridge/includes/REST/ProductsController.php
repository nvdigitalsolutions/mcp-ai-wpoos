<?php
/**
 * Products REST controller — Payload-compatible endpoints.
 *
 * Delegates every operation to the active product sources
 * (NvoosAstroBridge\Sources) — the built-in CPTs, WooCommerce, or both merged —
 * so the contract shape is identical regardless of backing store.
 *
 * @package NvoosAstroBridge\REST
 */

namespace NvoosAstroBridge\REST;

use NvoosAstroBridge\Schema;
use NvoosAstroBridge\Sources\ProductSources;
use NvoosAstroBridge\Sources\WhereClause;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles /funiq/v1/products and /funiq/v1/products/{id}.
 *
 * GET is public (matches Payload's read: () => true).
 * POST/PUT/DELETE require the manage capability.
 */
class ProductsController extends BaseController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'funiq/v1';

	/**
	 * REST base path.
	 *
	 * @var string
	 */
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
	 * GET /funiq/v1/products — merged across all active sources.
	 *
	 * Each source paginates independently and the pages are concatenated
	 * (native first, then WooCommerce) with summed totals — documented
	 * merged-pagination semantics.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$page  = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$limit = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?: 10 ) ) );
		$where = WhereClause::normalize( $request->get_param( 'where' ) );
		$sort  = $request->get_param( 'sort' ) ? (string) $request->get_param( 'sort' ) : null;

		$docs  = array();
		$total = 0;

		foreach ( ProductSources::active() as $source ) {
			$result = $source->list_products( $page, $limit, $where, $sort );
			$docs   = array_merge( $docs, $result['docs'] );
			$total += $result['total'];
		}

		return rest_ensure_response(
			$this->paginatedResponse( $docs, $total, $page, $limit )
		);
	}

	// -----------------------------------------------------------------------
	// Collection POST (create).
	// -----------------------------------------------------------------------

	/**
	 * POST /funiq/v1/products — routed to the requested (or primary) source.
	 *
	 * The optional `source` body field (native|woocommerce) selects the
	 * backing store in `both` mode; the primary source is the default.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$source = ProductSources::from_name( isset( $data['source'] ) ? (string) $data['source'] : null );

		if ( null === $source ) {
			return new WP_Error(
				'astro_invalid_source',
				'Unknown or inactive product source.',
				array( 'status' => 400 )
			);
		}

		$result = $source->create_product( $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	// -----------------------------------------------------------------------
	// Single GET.
	// -----------------------------------------------------------------------

	/**
	 * GET /funiq/v1/products/{id} — routed by the post's owning source.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$product_id = (int) $request['id'];
		$source     = ProductSources::resolve( $product_id );

		if ( null === $source ) {
			return new WP_Error(
				'astro_not_found',
				'Product not found.',
				array( 'status' => 404 )
			);
		}

		$doc = $source->get_product( $product_id );
		if ( null === $doc ) {
			return new WP_Error(
				'astro_not_found',
				'Product not found.',
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $doc );
	}

	// -----------------------------------------------------------------------
	// Single PUT (update).
	// -----------------------------------------------------------------------

	/**
	 * PUT /funiq/v1/products/{id} — routed by the post's owning source.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$product_id = (int) $request['id'];
		$source     = ProductSources::resolve( $product_id );

		if ( null === $source ) {
			return new WP_Error(
				'astro_not_found',
				'Product not found.',
				array( 'status' => 404 )
			);
		}

		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$result = $source->update_product( $product_id, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	// -----------------------------------------------------------------------
	// Single DELETE.
	// -----------------------------------------------------------------------

	/**
	 * DELETE /funiq/v1/products/{id} — routed by the post's owning source.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$product_id = (int) $request['id'];
		$source     = ProductSources::resolve( $product_id );

		if ( null === $source ) {
			return new WP_Error(
				'astro_not_found',
				'Product not found.',
				array( 'status' => 404 )
			);
		}

		$result = $source->delete_product( $product_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( null, 204 );
	}

	// -----------------------------------------------------------------------
	// Params + schema.
	// -----------------------------------------------------------------------

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
			'sort'  => array(
				'description'       => 'Sort order: date (default), -date, price, -price, rating, -rating, name, -name.',
				'type'              => 'string',
				'default'           => 'date',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Sanitize the where parameter.
	 *
	 * Query strings carry the Payload-style clause JSON-encoded; decode it
	 * here so the source layer always receives the structured shape.
	 *
	 * @param mixed $where Raw where value (string or array).
	 * @return array<string, array<string, mixed>>
	 */
	public function sanitize_where( $where ): array {
		if ( is_string( $where ) && '' !== $where ) {
			$decoded = json_decode( $where, true );
			if ( is_array( $decoded ) ) {
				$where = $decoded;
			}
		}
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
			'title'      => 'product',
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'name'         => array(
					'type'     => 'string',
					'required' => true,
				),
				'price'        => array(
					'type'     => 'number',
					'required' => true,
				),
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
				'image'        => array(
					'type'        => 'string',
					'description' => 'Featured image URL (PWA-facing).',
				),
				'imageId'      => array(
					'type'        => array( 'integer', 'null' ),
					'description' => 'Featured image attachment ID (admin-facing).',
				),
				'images'       => array(
					'type'        => 'array',
					'description' => 'Gallery image URLs (PWA-facing).',
				),
				'imagesIds'    => array(
					'type'        => 'array',
					'description' => 'Gallery attachment IDs (admin-facing).',
				),
				'statuses'     => array( 'type' => 'array' ),
				'promotion'    => array( 'type' => array( 'object', 'null' ) ),
				'createdAt'    => array( 'type' => 'string' ),
				'updatedAt'    => array( 'type' => 'string' ),
				'source'       => array(
					'type'        => 'string',
					'description' => 'Backing store: native (built-in) or woocommerce.',
					'enum'        => array( Schema::SOURCE_NATIVE, Schema::SOURCE_WOO ),
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
