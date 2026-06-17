<?php
/**
 * Abstract base for post-type-backed REST controllers (promotions, promocodes).
 *
 * Reuses the same Paginated CRUD pattern as taxonomy controllers
 * but operates on CPTs via WP_Query / wp_insert_post / wp_update_post.
 *
 * @package FuniqBridge\REST
 */

namespace FuniqBridge\REST;

use FuniqBridge\Schema;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Base class for CPT-based controllers.
 */
abstract class PostTypeController extends BaseController {

	/**
	 * Get the post type slug.
	 *
	 * @return string
	 */
	abstract protected function post_type(): string;

	/**
	 * Transform a WP_Post into the output shape.
	 *
	 * @param \WP_Post $post
	 * @return array<string, mixed>
	 */
	abstract protected function transform( \WP_Post $post ): array;

	/**
	 * Build post insert/update args from request data.
	 *
	 * @param array<string, mixed> $data  Request body.
	 * @param int|null             $id    Existing post ID (null for create).
	 * @return array<string, mixed>
	 */
	abstract protected function post_args( array $data, ?int $id ): array;

	/**
	 * Save meta fields after creating/updating the post.
	 *
	 * @param int                   $post_id
	 * @param array<string, mixed>  $data
	 * @return void
	 */
	abstract protected function save_meta( int $post_id, array $data ): void;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$base = $this->rest_base;

		register_rest_route(
			$this->namespace,
			'/' . $base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $base . '/(?P<id>[\d]+)',
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
			)
		);
	}

	/**
	 * GET collection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$page  = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$limit = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?: 100 ) ) );

		$query = new WP_Query(
			array(
				'post_type'      => $this->post_type(),
				'post_status'    => 'publish',
				'paged'          => $page,
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$docs = array();
		foreach ( $query->posts as $post ) {
			$docs[] = $this->transform( $post );
		}

		return rest_ensure_response(
			$this->paginatedResponse( $docs, (int) $query->found_posts, $page, $limit )
		);
	}

	/**
	 * POST create.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$args = $this->post_args( $request->get_json_params(), null );
		$args['post_status'] = 'publish';

		$id = wp_insert_post( $args, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$this->save_meta( $id, $request->get_json_params() );

		return $this->single_response( $id );
	}

	/**
	 * GET single.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		return $this->single_response( (int) $request['id'] );
	}

	/**
	 * PUT update.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );

		if ( ! $post || $this->post_type() !== $post->post_type ) {
			return new WP_Error( 'funiq_not_found', 'Item not found.', array( 'status' => 404 ) );
		}

		$data = $request->get_json_params();
		$args = $this->post_args( $data, $post_id );
		wp_update_post( $args, true );
		$this->save_meta( $post_id, $data );

		return $this->single_response( $post_id );
	}

	/**
	 * DELETE.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );

		if ( ! $post || $this->post_type() !== $post->post_type ) {
			return new WP_Error( 'funiq_not_found', 'Item not found.', array( 'status' => 404 ) );
		}

		$result = wp_delete_post( $post_id, true );
		if ( ! $result ) {
			return new WP_Error( 'funiq_delete_failed', 'Failed to delete.', array( 'status' => 500 ) );
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Build a single-item REST response.
	 *
	 * @param int $post_id
	 * @return WP_REST_Response|WP_Error
	 */
	protected function single_response( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || $this->post_type() !== $post->post_type ) {
			return new WP_Error( 'funiq_not_found', 'Item not found.', array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->transform( $post ) );
	}
}
