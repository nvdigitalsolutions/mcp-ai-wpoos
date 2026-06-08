<?php
/**
 * Categories REST controller.
 *
 * @package FuniqBridge\REST
 */

namespace FuniqBridge\REST;

use FuniqBridge\Schema;
use FuniqBridge\Transformers\CategoryTransformer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles /funiq/v1/categories.
 *
 * Categories have an image (term meta) and a computed productsCount field.
 */
class CategoriesController extends TermController {

	/** @var string */
	protected $namespace = 'funiq/v1';

	/** @var string */
	protected $rest_base = 'categories';

	/**
	 * @return string
	 */
	protected function taxonomy(): string {
		return Schema::TAX_CATEGORY;
	}

	/**
	 * GET collection — list all categories with productsCount.
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

		$transformer = new CategoryTransformer();
		$docs        = array_map( array( $transformer, 'transform' ), $terms );

		return rest_ensure_response(
			$this->paginatedResponse( $docs, count( $docs ), 1, count( $docs ) )
		);
	}

	/**
	 * GET single — uses CategoryTransformer for productsCount.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$term = get_term( (int) $request['id'], $this->taxonomy() );
		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'funiq_not_found', 'Category not found.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( ( new CategoryTransformer() )->transform( $term ) );
	}

	/**
	 * Save image term meta.
	 *
	 * @param int                   $term_id Term ID.
	 * @param array<string, mixed>  $data    Request body.
	 * @return void
	 */
	protected function save_term_meta( int $term_id, array $data ): void {
		if ( isset( $data['image'] ) ) {
			if ( is_numeric( $data['image'] ) && (int) $data['image'] > 0 ) {
				update_term_meta( $term_id, Schema::TERM_META_IMAGE_ID, (int) $data['image'] );
			} elseif ( empty( $data['image'] ) ) {
				delete_term_meta( $term_id, Schema::TERM_META_IMAGE_ID );
			}
		}
	}

	/**
	 * Build response using CategoryTransformer.
	 *
	 * @param int $term_id Term ID.
	 * @return array<string, mixed>
	 */
	protected function build_term_response( int $term_id ): array {
		$term = get_term( $term_id, $this->taxonomy() );
		if ( ! $term || is_wp_error( $term ) ) {
			return array();
		}
		return ( new CategoryTransformer() )->transform( $term );
	}
}
