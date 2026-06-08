<?php
/**
 * Base class for Funiq Bridge REST controllers.
 *
 * Provides the Payload-paginated response helper shared across all controllers.
 *
 * @package FuniqBridge\REST
 */

namespace FuniqBridge\REST;

use WP_REST_Controller;

/**
 * Shared base for all Funiq REST controllers.
 */
abstract class BaseController extends WP_REST_Controller {

	/**
	 * Build a Payload-compatible paginated response.
	 *
	 * @param array<int, array<string, mixed>> $docs  Transformed documents.
	 * @param int                              $total Total number of matching items.
	 * @param int                              $page  Current page (1-based).
	 * @param int                              $limit Items per page.
	 * @return array<string, mixed>
	 */
	protected function paginatedResponse( array $docs, int $total, int $page, int $limit ): array {
		$pages = $limit > 0 ? (int) ceil( $total / $limit ) : 1;

		return array(
			'docs'        => $docs,
			'totalDocs'   => $total,
			'limit'       => $limit,
			'totalPages'  => $pages,
			'page'        => $page,
			'hasNextPage' => $page < $pages,
			'hasPrevPage' => $page > 1,
		);
	}

	/**
	 * Permission check for write operations — requires the manage_funiq cap.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|\WP_Error
	 */
	public function item_permissions_check( $request ) {
		if ( ! current_user_can( \FuniqBridge\Schema::CAP_MANAGE_FUNIQ ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'Sorry, you are not allowed to do that.',
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}
}
