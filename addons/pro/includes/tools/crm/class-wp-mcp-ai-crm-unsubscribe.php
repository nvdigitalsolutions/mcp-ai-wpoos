<?php
/**
 * CRM Unsubscribe
 *
 * Signed, one-click unsubscribe (RFC 8058) for cold and marketing email.
 * The token is the authority: a mailbox provider POSTs it without a nonce.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-click unsubscribe.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_CRM_Unsubscribe {

	/**
	 * Secret option.
	 *
	 * @var string
	 */
	const SECRET_OPTION = 'wp_mcp_ai_crm_unsubscribe_secret';

	/**
	 * Token TTL in seconds (90 days).
	 *
	 * @var int
	 */
	const TTL = 7776000;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'mcp-ai/v1',
			'/crm/unsubscribe',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'handle_get' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle_post' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Secret used to sign tokens.
	 *
	 * @return string
	 */
	private static function secret() {
		$secret = get_option( self::SECRET_OPTION, '' );
		if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
			$secret = wp_generate_password( 64, false, false );
			update_option( self::SECRET_OPTION, $secret, false );
		}
		return $secret;
	}

	/**
	 * Base64url encode.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function b64url_encode( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64url decode.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function b64url_decode( $value ) {
		$value = strtr( $value, '-_', '+/' );
		$pad   = strlen( $value ) % 4;
		if ( $pad ) {
			$value .= str_repeat( '=', 4 - $pad );
		}
		return (string) base64_decode( $value, true );
	}

	/**
	 * Generate a token.
	 *
	 * @param int    $lead_id Lead post ID.
	 * @param string $email   Email.
	 * @param string $channel Channel.
	 * @return string
	 */
	public static function generate_token( $lead_id, $email, $channel = 'email' ) {
		$lead_id = absint( $lead_id );
		$email   = strtolower( trim( (string) $email ) );
		$channel = sanitize_key( $channel );
		$issued  = time();
		$data    = $lead_id . '|' . $email . '|' . $channel . '|' . $issued;
		$sig     = hash_hmac( 'sha256', $data, wp_salt( 'auth' ) . self::secret() );
		return self::b64url_encode( $data ) . '.' . self::b64url_encode( $sig );
	}

	/**
	 * Verify a token.
	 *
	 * @param string $token Token.
	 * @return array|WP_Error
	 */
	public static function verify_token( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token || false === strpos( $token, '.' ) ) {
			return new WP_Error( 'invalid_token', __( 'Invalid unsubscribe token.', 'mcp-ai-wpoos-pro' ) );
		}

		list( $encoded, $encoded_sig ) = explode( '.', $token, 2 );
		$data                          = self::b64url_decode( $encoded );
		$sig                           = self::b64url_decode( $encoded_sig );
		if ( '' === $data || '' === $sig ) {
			return new WP_Error( 'invalid_token', __( 'Invalid unsubscribe token.', 'mcp-ai-wpoos-pro' ) );
		}

		$expected = hash_hmac( 'sha256', $data, wp_salt( 'auth' ) . self::secret() );
		if ( ! hash_equals( $expected, $sig ) ) {
			return new WP_Error( 'invalid_token', __( 'Invalid unsubscribe token.', 'mcp-ai-wpoos-pro' ) );
		}

		$parts = explode( '|', $data );
		if ( 4 !== count( $parts ) ) {
			return new WP_Error( 'invalid_token', __( 'Invalid unsubscribe token.', 'mcp-ai-wpoos-pro' ) );
		}

		$lead_id = absint( $parts[0] );
		$email   = sanitize_email( $parts[1] );
		$channel = sanitize_key( $parts[2] );
		$issued  = absint( $parts[3] );

		if ( ! $lead_id || ! is_email( $email ) ) {
			return new WP_Error( 'invalid_token', __( 'Invalid unsubscribe token.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'lead_id' => $lead_id,
			'email'   => $email,
			'channel' => $channel,
			'issued'  => $issued,
			'expired' => $issued > 0 && ( time() - $issued ) > self::TTL,
		);
	}

	/**
	 * Build the unsubscribe URL for a lead.
	 *
	 * @param int    $lead_id Lead post ID.
	 * @param string $email   Email.
	 * @param string $channel Channel.
	 * @return string
	 */
	public static function url( $lead_id, $email, $channel = 'email' ) {
		$url = rest_url( 'mcp-ai/v1/crm/unsubscribe' );
		return add_query_arg( 'token', self::generate_token( $lead_id, $email, $channel ), $url );
	}

	/**
	 * Headers to add to an outbound email.
	 *
	 * @param int    $lead_id Lead post ID.
	 * @param string $channel Channel.
	 * @param string $kind    Kind.
	 * @return string[]
	 */
	public static function headers_for_lead( $lead_id, $channel = 'email', $kind = 'sequence' ) {
		if ( 'email' !== $channel || ! in_array( sanitize_key( $kind ), array( 'sequence', 'marketing' ), true ) ) {
			return array();
		}

		$lead_id = absint( $lead_id );
		$email   = $lead_id ? (string) get_post_meta( $lead_id, 'email', true ) : '';
		if ( ! $email || ! is_email( $email ) ) {
			return array();
		}

		$url = self::url( $lead_id, $email, 'email' );
		return array(
			'List-Unsubscribe: <' . $url . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		);
	}

	/**
	 * Revoke from a token.
	 *
	 * @param string $token Token.
	 * @return array|WP_Error
	 */
	public static function revoke_token( $token ) {
		$verified = self::verify_token( $token );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$lead_id = $verified['lead_id'];
		$email   = $verified['email'];

		if ( class_exists( 'WP_MCP_AI_CRM_Consent' ) ) {
			WP_MCP_AI_CRM_Consent::revoke( $lead_id, 'all' );
		} elseif ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			WP_MCP_AI_CRM_Engine::add_to_dnc( $email, 'all' );
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			WP_MCP_AI_CRM_Engine::add_to_dnc( $email, 'all' );
		}

		update_post_meta( $lead_id, '_sequence_paused', '1' );
		update_post_meta( $lead_id, '_crm_unsubscribed_at', gmdate( 'c' ) );

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'unsubscribe_processed',
				'lead',
				$lead_id,
				array(
					'channel' => $verified['channel'],
					'expired' => $verified['expired'] ? '1' : '0',
				)
			);
		}

		return array(
			'lead_id' => $lead_id,
			'email'   => $email,
			'channel' => $verified['channel'],
			'expired' => $verified['expired'],
		);
	}

	/**
	 * Handle a GET click.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return void
	 */
	public static function handle_get( $request ) {
		$token  = (string) $request->get_param( 'token' );
		$result = self::revoke_token( $token );

		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html__( 'This unsubscribe link is invalid or has expired.', 'mcp-ai-wpoos-pro' ),
				esc_html__( 'Unsubscribe', 'mcp-ai-wpoos-pro' ),
				array( 'response' => 400 )
			);
		}

		wp_die(
			esc_html__( 'You have been unsubscribed. You will not receive further messages.', 'mcp-ai-wpoos-pro' ),
			esc_html__( 'Unsubscribed', 'mcp-ai-wpoos-pro' ),
			array( 'response' => 200 )
		);
	}

	/**
	 * Handle an RFC 8058 POST.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_post( $request ) {
		$token = (string) $request->get_param( 'token' );
		if ( ! $token && isset( $_POST['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Signed token is the authority.
			$token = sanitize_text_field( wp_unslash( $_POST['token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Signed token is the authority.
		}

		$result = self::revoke_token( $token );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Unsubscribed.', 'mcp-ai-wpoos-pro' ),
			),
			200
		);
	}
}
