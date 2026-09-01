<?php
/**
 * Mesh Peer Test REST API Endpoint
 *
 * Provides REST endpoint for testing mesh peer connections.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Mesh;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API endpoint for testing mesh peer connections.
 */
class MeshPeerTestRest {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Register REST API routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register mesh peer test routes.
	 */
	public function register_routes() {
		// POST /mcp-ai/v1/mesh/test-peer - Test a mesh peer connection.
		register_rest_route(
			self::REST_NAMESPACE,
			'/mesh/test-peer',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_peer' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'name'    => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'url'     => array(
						'required'          => true,
						'type'              => 'string',
						'format'            => 'uri',
						'sanitize_callback' => 'esc_url_raw',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'api_key' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'peer_id' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'Mesh peer ID for updating CPT status',
					),
				),
			)
		);
	}

	/**
	 * Check if user has admin permission.
	 *
	 * @return bool True if user has permission.
	 */
	public function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Test mesh peer connection.
	 *
	 * @param \\WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function test_peer( $request ) {
		$peer = array(
			'name'    => $request->get_param( 'name' ),
			'url'     => $request->get_param( 'url' ),
			'api_key' => $request->get_param( 'api_key' ),
		);

		$peer_id = $request->get_param( 'peer_id' );

		// Run the connection test.
		$result = MeshPeerTester::test_connection( $peer );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		// Update CPT status if peer_id provided.
		if ( ! empty( $peer_id ) ) {
			MeshPeerTester::update_peer_test_status( $peer_id, $result );
		}

		return new \WP_REST_Response( $result, 200 );
	}
}
