<?php
/**
 * Base class for Funiq Bridge REST controllers.
 *
 * Provides the Payload-paginated response helper shared across all controllers.
 *
 * @package NvoosAstroBridge\REST
 */

namespace NvoosAstroBridge\REST;

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
	 * Set the REST namespace (used by the Proposal 092 alias registration:
	 * the same controller is registered under funiq/v1 and nvoos-commerce/v1).
	 *
	 * @param string $ns Namespace in `{prefix}/v{n}` form.
	 * @return void
	 */
	public function set_namespace( string $ns ): void {
		$this->namespace = $ns;
	}

	/**
	 * Permission check for write operations — requires the manage cap.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|\WP_Error
	 */
	public function item_permissions_check( $request ) {
		if ( ! current_user_can( \NvoosAstroBridge\Schema::CAP_MANAGE ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'Sorry, you are not allowed to do that.',
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Cache headers for public GETs (bridge enhancement B1, Proposal 092).
	 *
	 * Marks successful, unauthenticated GET responses on both namespaces
	 * cacheable so the Velocity serve.mjs micro-cache (which only caches
	 * `Cache-Control: public` responses) and the Astro fetch layer can serve
	 * from cache. Single documents also get Last-Modified/ETag validators.
	 *
	 * @param \WP_REST_Response $response REST response (possibly not ours).
	 * @param \WP_REST_Server   $server   REST server instance.
	 * @param \WP_REST_Request  $request  Original request.
	 * @return \WP_REST_Response
	 */
	public static function set_cache_headers_on_public_gets( $response, $server, $request ) {
		if ( 'GET' !== $request->get_method() || ! ( $response instanceof \WP_REST_Response ) ) {
			return $response;
		}

		$route = $request->get_route();
		if (
			0 !== strpos( $route, '/' . \NvoosAstroBridge\Schema::REST_NAMESPACE )
			&& 0 !== strpos( $route, '/' . \NvoosAstroBridge\Schema::REST_NAMESPACE_NV )
		) {
			return $response;
		}

		// Per-session surfaces must never be marked public. /auth/nonce is a
		// session-tied CSRF token — caching it at the edge serves one
		// visitor's nonce to everyone and breaks cookie auth (incident
		// 2026-10-29: the cached anonymous nonce failed validation for every
		// authenticated REST call).
		if (
			0 === strpos( $route, '/' . \NvoosAstroBridge\Schema::REST_NAMESPACE . '/auth' )
			|| 0 === strpos( $route, '/' . \NvoosAstroBridge\Schema::REST_NAMESPACE_NV . '/auth' )
		) {
			return $response;
		}

		$status = $response->get_status();
		if ( $status < 200 || $status >= 300 ) {
			return $response;
		}

		// Never mark authenticated responses public — the serve.mjs
		// micro-cache additionally default-denies Set-Cookie/Vary: Cookie.
		if ( is_user_logged_in() ) {
			return $response;
		}

		$response->header( 'Cache-Control', 'public, s-maxage=300, stale-while-revalidate=60' );

		// ETag from the serialized body — correct for collections and singles.
		$body = wp_json_encode( $response->get_data() );
		if ( is_string( $body ) ) {
			$response->header( 'ETag', '"' . md5( $body ) . '"' );
		}

		// Last-Modified for single documents that expose updatedAt.
		$data = $response->get_data();
		if ( is_array( $data ) && isset( $data['updatedAt'] ) && is_string( $data['updatedAt'] ) ) {
			$ts = strtotime( $data['updatedAt'] );
			if ( $ts ) {
				$response->header( 'Last-Modified', gmdate( 'D, d M Y H:i:s', $ts ) . ' GMT' );
			}
		}

		return $response;
	}
}
