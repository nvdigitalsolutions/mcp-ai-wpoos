<?php
/**
 * Abstract base for taxonomy term REST controllers.
 *
 * Handles GET (list), POST (create), GET (single), PUT (update), DELETE for
 * taxonomy terms exposed as Payload collections.
 *
 * @package FuniqBridge\REST
 */

namespace FuniqBridge\REST;

use FuniqBridge\Transformers\TermTransformer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Base class for taxonomy-based controllers (brands, colors, statuses).
 */
abstract class TermController extends BaseController {

	/**
	 * Get the taxonomy slug this controller manages.
	 *
	 * @return string
	 */
	abstract protected function taxonomy(): string;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$base = $this->rest_base;

		// Collection: GET, POST.
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

		// Single: GET, PUT, DELETE.
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
	 * GET collection — list all terms.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $this->taxonomy(),
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return rest_ensure_response(
				$this->paginatedResponse( array(), 0, 1, 100 )
			);
		}

		$transformer = new TermTransformer();
		$docs        = array_map( array( $transformer, 'transform' ), $terms );

		return rest_ensure_response(
			$this->paginatedResponse( $docs, count( $docs ), 1, count( $docs ) )
		);
	}

	/**
	 * POST — create a new term.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$data = $request->get_json_params();
		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( '' === $name ) {
			return new WP_Error( 'funiq_missing_name', 'Term name is required.', array( 'status' => 400 ) );
		}

		$result = wp_insert_term( $name, $this->taxonomy() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term_id = (int) $result['term_id'];
		$this->save_term_meta( $term_id, $data );

		return rest_ensure_response( $this->build_term_response( $term_id ) );
	}

	/**
	 * GET single — get one term.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$term = get_term( (int) $request['id'], $this->taxonomy() );
		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'funiq_not_found', 'Term not found.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( ( new TermTransformer() )->transform( $term ) );
	}

	/**
	 * PUT — update a term.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$term_id = (int) $request['id'];
		$term    = get_term( $term_id, $this->taxonomy() );
		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'funiq_not_found', 'Term not found.', array( 'status' => 404 ) );
		}

		$data = $request->get_json_params();
		if ( isset( $data['name'] ) ) {
			wp_update_term( $term_id, $this->taxonomy(), array( 'name' => sanitize_text_field( $data['name'] ) ) );
		}
		$this->save_term_meta( $term_id, $data );

		return rest_ensure_response( $this->build_term_response( $term_id ) );
	}

	/**
	 * DELETE — remove a term.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$term_id = (int) $request['id'];
		$result  = wp_delete_term( $term_id, $this->taxonomy() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new WP_Error( 'funiq_delete_failed', 'Failed to delete term.', array( 'status' => 500 ) );
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Save term meta from request data.
	 *
	 * Override in subclasses for custom meta fields.
	 *
	 * @param int                   $term_id Term ID.
	 * @param array<string, mixed>  $data    Request body.
	 * @return void
	 */
	protected function save_term_meta( int $term_id, array $data ): void {
		// Subclasses override this.
	}

	/**
	 * Build the response array for a single term.
	 *
	 * @param int $term_id Term ID.
	 * @return array<string, mixed>
	 */
	protected function build_term_response( int $term_id ): array {
		$term = get_term( $term_id, $this->taxonomy() );
		if ( ! $term || is_wp_error( $term ) ) {
			return array();
		}
		return ( new TermTransformer() )->transform( $term );
	}
}
