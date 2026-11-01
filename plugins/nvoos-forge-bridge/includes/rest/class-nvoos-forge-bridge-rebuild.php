<?php
/**
 * REST — rebuild triggers + preview tokens (namespace nvoos-forge/v1).
 *
 * @package NvoosForgeBridge
 */

namespace NvoosForgeBridge\Rest;

use NvoosForgeBridge\Rebuild_Webhook;
use NvoosForgeBridge\Preview_Token;

/**
 * Rebuild + preview-token routes.
 */
class Rebuild extends \WP_REST_Controller {

	/**
	 * Namespace.
	 */
	private const NS = 'nvoos-forge/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/rebuild',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_rebuild' ),
				'permission_callback' => array( $this, 'verify_signature' ),
			)
		);
		register_rest_route(
			self::NS,
			'/preview-tokens',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_mint' ),
				'permission_callback' => array( $this, 'can_mint' ),
			)
		);
		register_rest_route(
			self::NS,
			'/preview-tokens/verify',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_verify' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Inbound rebuild trigger — HMAC-verified (fail closed).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_rebuild( \WP_REST_Request $request ): \WP_REST_Response {
		Rebuild_Webhook::schedule( 'inbound' );
		return new \WP_REST_Response(
			array( 'accepted' => true ),
			202
		);
	}

	/**
	 * Signature permission callback — the body is the signed payload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function verify_signature( \WP_REST_Request $request ) {
		$signature = (string) $request->get_header( 'x_forge_signature' );
		$payload   = (string) $request->get_body();
		if ( '' === $payload ) {
			$payload = '{}';
		}
		if ( ! Rebuild_Webhook::verify( $payload, $signature ) ) {
			return new \WP_Error(
				'nvoos_forge_bridge_bad_signature',
				__( 'Invalid or missing request signature.', 'nvoos-forge-bridge' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * Mint permission callback — any editor can create preview tokens.
	 *
	 * @return true|\WP_Error
	 */
	public function can_mint() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'nvoos_forge_bridge_forbidden',
				__( 'You are not allowed to create preview tokens.', 'nvoos-forge-bridge' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Mint a single-use preview token.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_mint(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'token'     => Preview_Token::mint(),
				'expiresIn' => Preview_Token::TTL_SECONDS,
			),
			201
		);
	}

	/**
	 * Verify + consume a preview token (public, bearer proof).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_verify( \WP_REST_Request $request ): \WP_REST_Response {
		$valid = Preview_Token::verify_and_consume( (string) $request->get_param( 'token' ) );
		if ( ! $valid ) {
			return new \WP_REST_Response(
				array( 'valid' => false ),
				403
			);
		}
		return new \WP_REST_Response(
			array(
				'valid' => true,
				'site'  => wp_parse_url( home_url(), PHP_URL_HOST ),
			)
		);
	}
}
