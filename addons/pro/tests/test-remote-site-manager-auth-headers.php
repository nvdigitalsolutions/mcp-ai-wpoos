<?php
/**
 * Remote Sites authentication header mapping.
 *
 * `get_auth_headers()` previously resolved application_password/basic_auth
 * and jwt only, so a connection set to `bearer` (a first-class option in the
 * edit form) sent no Authorization header at all and failed as unauthenticated.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

/**
 * Tests for the `make_request()` credential mapping.
 */
class Test_Remote_Site_Manager_Auth_Headers extends WP_UnitTestCase {

	/**
	 * Invoke the protected header builder.
	 *
	 * @param array $connection Connection data.
	 * @return array
	 */
	private function headers( array $connection ) {
		$method = new ReflectionMethod( 'WP_MCP_AI_Pro_Remote_Site_Manager', 'get_auth_headers' );
		$method->setAccessible( true );

		return $method->invoke( null, $connection );
	}

	/**
	 * A bearer connection sends Authorization: Bearer.
	 */
	public function test_bearer_auth_sends_the_authorization_header() {
		$headers = $this->headers(
			array(
				'auth_type' => 'bearer',
				'token'     => WP_MCP_AI_Pro_Remote_Site_Manager::encrypt_value( 'tok-live-123' ),
			)
		);

		$this->assertArrayHasKey( 'Authorization', $headers );
		$this->assertSame( 'Bearer tok-live-123', $headers['Authorization'] );
	}

	/**
	 * JWT keeps the same bearer shape (regression).
	 */
	public function test_jwt_auth_still_sends_the_authorization_header() {
		$headers = $this->headers(
			array(
				'auth_type' => 'jwt',
				'token'     => 'jwt-token-abc',
			)
		);

		$this->assertSame( 'Bearer jwt-token-abc', $headers['Authorization'] );
	}

	/**
	 * An empty bearer token must not emit an empty header.
	 */
	public function test_bearer_auth_without_a_token_sends_no_header() {
		$headers = $this->headers(
			array(
				'auth_type' => 'bearer',
				'token'     => '',
			)
		);

		$this->assertArrayNotHasKey( 'Authorization', $headers );
	}

	/**
	 * Basic auth is unchanged.
	 */
	public function test_basic_auth_sends_basic_credentials() {
		$headers = $this->headers(
			array(
				'auth_type' => 'basic_auth',
				'username'  => 'user',
				// Production stores the password encrypted; a plaintext fixture
				// that happens to be valid base64 would be XOR-decoded by decrypt_value().
				'password'  => WP_MCP_AI_Pro_Remote_Site_Manager::encrypt_value( 'pass' ),
			)
		);

		$this->assertSame( 'Basic ' . base64_encode( 'user:pass' ), $headers['Authorization'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Constructs the expected HTTP Basic credential (RFC 7617) for the assertion.
	}

	/**
	 * `none` sends no credential.
	 */
	public function test_none_sends_no_authorization_header() {
		$headers = $this->headers( array( 'auth_type' => 'none' ) );

		$this->assertArrayNotHasKey( 'Authorization', $headers );
	}

	/**
	 * The header reaches the wire through make_request().
	 */
	public function test_make_request_sends_the_bearer_header() {
		$captured = array();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured ) {
				$captured[] = array(
					'url'     => $url,
					'headers' => $args['headers'],
				);

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'ok' => true ) ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$connection = array(
			'id'              => 'conn_bearer_' . wp_generate_password( 8, false ),
			'connection_type' => 'generic',
			'auth_type'       => 'bearer',
			'token'           => WP_MCP_AI_Pro_Remote_Site_Manager::encrypt_value( 'tok-live-456' ),
			'url'             => 'https://api.example.com',
			'enabled'         => true,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, '/v1/ping' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $captured );
		$this->assertSame( 'https://api.example.com/v1/ping', $captured[0]['url'] );
		$this->assertSame( 'Bearer tok-live-456', $captured[0]['headers']['Authorization'] );
	}
}
