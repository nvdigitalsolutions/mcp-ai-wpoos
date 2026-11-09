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
		// The REST pipeline force-logs-out cookie sessions before the callback
		// runs (see AuthSupport) — restore the session here, then gate on the
		// capability.
		AuthSupport::maybe_restore_user_from_logged_in_cookie();

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
		// authenticated REST call). /checkout serves one visitor's draft
		// order + addresses (Proposal 110) — the same rule applies, and the
		// CheckoutController additionally pins private/no-store headers.
		if (
			0 === strpos( $route, '/' . \NvoosAstroBridge\Schema::REST_NAMESPACE . '/auth' )
			|| 0 === strpos( $route, '/' . \NvoosAstroBridge\Schema::REST_NAMESPACE_NV . '/auth' )
			|| 0 === strpos( $route, '/' . \NvoosAstroBridge\Schema::REST_NAMESPACE . '/checkout' )
			|| 0 === strpos( $route, '/' . \NvoosAstroBridge\Schema::REST_NAMESPACE_NV . '/checkout' )
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

		// Never mark a preview response public (Proposal 135 Phase 5). A
		// `preview_token` request deliberately returns NON-published content to
		// an anonymous caller; caching it at the edge would serve one editor's
		// draft to every visitor. The token is per-post and short-lived, but the
		// cache has no idea either fact.
		if ( self::is_preview_request( $request ) ) {
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

		self::apply_cache_tags( $response, $request );

		return $response;
	}

	/**
	 * Route-specific cache override for the globals/theme + globals/shop
	 * documents (Proposals 105/106 §1) — `public, s-maxage=60,
	 * stale-while-revalidate=15` instead of the B1 hook's 300/60.
	 *
	 * Registered by ThemePolicyController + ShopGlobalsController on
	 * `rest_post_dispatch` at priority 20 — after the B1 filter (10) — so
	 * the short TTL wins for those routes. The same public/authenticated
	 * guards as B1 apply: per-session or error responses are never marked
	 * cacheable.
	 *
	 * @param \WP_REST_Response $response REST response (possibly not ours).
	 * @param \WP_REST_Server   $server   REST server instance.
	 * @param \WP_REST_Request  $request  Original request.
	 * @return \WP_REST_Response
	 */
	public static function set_short_cache_headers_on_public_gets( $response, $server, $request ) {
		if ( 'GET' !== $request->get_method() || ! ( $response instanceof \WP_REST_Response ) ) {
			return $response;
		}

		$route = $request->get_route();
		$short = false;
		foreach ( array( 'globals/theme', 'globals/shop' ) as $base ) {
			if (
				0 === strpos( $route, '/' . \NvoosAstroBridge\Schema::REST_NAMESPACE . '/' . $base )
				|| 0 === strpos( $route, '/' . \NvoosAstroBridge\Schema::REST_NAMESPACE_NV . '/' . $base )
			) {
				$short = true;
				break;
			}
		}
		if ( ! $short ) {
			return $response;
		}

		$status = $response->get_status();
		if ( $status < 200 || $status >= 300 ) {
			return $response;
		}

		// Never mark authenticated responses public (the same guard as B1).
		if ( is_user_logged_in() ) {
			return $response;
		}

		// Same preview guard as B1 — see is_preview_request().
		if ( self::is_preview_request( $request ) ) {
			return $response;
		}

		$response->header( 'Cache-Control', 'public, s-maxage=60, stale-while-revalidate=15' );

		// ETag from the serialized body — the doc hash the storefront uses
		// for conditional revalidation.
		$body = wp_json_encode( $response->get_data() );
		if ( is_string( $body ) ) {
			$response->header( 'ETag', '"' . md5( $body ) . '"' );
		}

		self::apply_cache_tags( $response, $request );

		return $response;
	}

	/**
	 * Whether a request carries a preview token.
	 *
	 * A valid token returns non-published content to an anonymous caller, so
	 * every "is this cacheable?" decision must answer no. The check is on the
	 * PARAMETER's presence, not its validity: a forged/garbage token still
	 * means the caller is asking for a draft, and a cacheable 404/403 would be
	 * indistinguishable from the real thing anyway.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	protected static function is_preview_request( $request ): bool {
		if ( ! $request instanceof \WP_REST_Request ) {
			return false;
		}
		$token = $request->get_param( 'preview_token' );

		return is_string( $token ) && '' !== $token;
	}

	/**
	 * Cache tags for targeted invalidation (Proposal 135 / A2).
	 *
	 * The vocabulary matches the frontend's `Astro.cache.set({ tags })`:
	 * one tag per collection plus `collection:<id>` for a single document.
	 * `Cache-Tag` is the Fastly/Cloudflare convention, `Surrogate-Key` the
	 * Varnish/Fastly one — both carry the same list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int, string>
	 */
	private static function cache_tags( $request ): array {
		$route = (string) $request->get_route();
		// The namespace constants already carry `/v1` (funiq/v1,
		// nvoos-commerce/v1) — do not append it again.
		$rest = (string) preg_replace(
			'#^/(?:' . preg_quote( \NvoosAstroBridge\Schema::REST_NAMESPACE, '#' ) . '|' . preg_quote( \NvoosAstroBridge\Schema::REST_NAMESPACE_NV, '#' ) . ')/?#',
			'',
			$route
		);

		$segments = array_values( array_filter( explode( '/', $rest ), 'strlen' ) );
		if ( empty( $segments ) ) {
			return array();
		}

		// The route is the MATCHED PATTERN, so parameter segments arrive as
		// regex noise — keep only the leading collection slug.
		$collection = (string) preg_replace( '/[^a-z0-9_-].*$/i', '', (string) $segments[0] );
		if ( '' === $collection ) {
			return array();
		}

		$tags = array( $collection );
		$id   = $request->get_param( 'id' );
		if ( is_numeric( $id ) && (int) $id > 0 ) {
			$tags[] = $collection . ':' . (int) $id;
		}

		return array_values( array_unique( $tags ) );
	}

	/**
	 * Apply the tag headers to a response.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_REST_Request  $request  Request.
	 * @return void
	 */
	private static function apply_cache_tags( $response, $request ): void {
		$tags = self::cache_tags( $request );
		if ( empty( $tags ) ) {
			return;
		}
		$response->header( 'Cache-Tag', implode( ',', $tags ) );
		$response->header( 'Surrogate-Key', implode( ' ', $tags ) );
	}
}
