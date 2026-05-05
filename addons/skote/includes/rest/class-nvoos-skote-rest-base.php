<?php
/**
 * NV oOS Skote — REST Base
 *
 * Shared permission, nonce, sanitization, and response-shape helpers used by
 * every Skote REST controller. Keeps a consistent envelope so the React Query
 * hooks on the SPA share a single response shape.
 *
 * Auth model:
 *  - Cookie + `X-WP-Nonce` for the in-admin SPA (the standard WP REST flow).
 *  - Bearer token paths (assistant credentials, Auth0) are accepted only when
 *    the host site has them wired via the base plugin's
 *    `wp_mcp_ai_authenticate_request` filter; we do not re-implement them
 *    here.
 *
 * @package NV_oOS_Skote
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared REST helpers.
 *
 * @since 0.1.0
 */
abstract class NVOOS_Skote_REST_Base {

	/**
	 * Build a uniform success envelope.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $data Response payload.
	 * @param array $meta Optional meta (pagination, totals).
	 * @param int   $status HTTP status. Default 200.
	 *
	 * @return WP_REST_Response
	 */
	protected static function success( $data, array $meta = array(), $status = 200 ) {
		$response = new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
				'errors'  => array(),
				'meta'    => $meta,
			),
			(int) $status
		);
		return $response;
	}

	/**
	 * Build a uniform error response.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code    Machine-readable code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status.
	 * @param array  $extra   Optional additional fields.
	 *
	 * @return WP_Error
	 */
	protected static function error( $code, $message, $status = 400, array $extra = array() ) {
		return new WP_Error(
			(string) $code,
			(string) $message,
			array_merge( array( 'status' => (int) $status ), $extra )
		);
	}

	/**
	 * Permission callback — current user has the given capability AND the
	 * cookie-auth nonce check has passed (when running under cookie auth).
	 *
	 * @since 0.1.0
	 *
	 * @param string $capability Required capability.
	 *
	 * @return callable
	 */
	protected static function require_cap( $capability ) {
		$capability = (string) $capability;
		return static function ( $request ) use ( $capability ) {
			if ( ! current_user_can( $capability ) ) {
				return new WP_Error(
					'nvoos_skote_forbidden',
					__( 'You do not have permission to access this resource.', 'nvoos-skote' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
			// REST cookie auth populates `rest_nonce` via `X-WP-Nonce`. When the
			// request is authenticated via a different mechanism (e.g. an
			// Application Password) WordPress sets `wp_validate_auth_cookie()`
			// false and rest_cookie_check_errors() returns null — that's fine.
			$cookie_check = rest_cookie_check_errors( null );
			if ( is_wp_error( $cookie_check ) ) {
				return $cookie_check;
			}
			unset( $request );
			return true;
		};
	}

	/**
	 * Sanitize a free-text field for storage / response.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	protected static function sanitize_text( $value ) {
		return sanitize_text_field( wp_unslash( (string) $value ) );
	}

	/**
	 * Sanitize a key (post type slug, app name).
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	protected static function sanitize_slug( $value ) {
		return sanitize_key( (string) $value );
	}

	/**
	 * Whitelist filter — keep only keys that appear in `$allowed`.
	 *
	 * @since 0.1.0
	 *
	 * @param array $input   Input array.
	 * @param array $allowed Allowed keys.
	 *
	 * @return array
	 */
	protected static function whitelist( array $input, array $allowed ) {
		return array_intersect_key( $input, array_flip( $allowed ) );
	}
}
