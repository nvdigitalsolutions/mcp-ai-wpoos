<?php
/**
 * Tests for the Generate Auth0 Token tool.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for WP_MCP_AI_Tool_Generate_Auth0_Token.
 */
class Test_WP_MCP_AI_Tool_Generate_Auth0_Token extends WP_UnitTestCase {
	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Generate_Auth0_Token
	 */
	protected $tool;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected $subscriber_user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-interface.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-auth0-token.php';

		$this->tool = new WP_MCP_AI_Tool_Generate_Auth0_Token();

		$this->admin_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		$this->subscriber_user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
	}

	/**
	 * Test tool availability.
	 */
	public function test_tool_is_available() {
		$this->assertTrue( WP_MCP_AI_Tool_Generate_Auth0_Token::is_available() );
	}

	/**
	 * Test tool slug.
	 */
	public function test_get_slug() {
		$this->assertSame( 'generate_auth0_token', $this->tool->get_slug() );
	}

	/**
	 * Test tool name.
	 */
	public function test_get_name() {
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Test tool description.
	 */
	public function test_get_description() {
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Test parameters schema.
	 */
	public function test_get_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'auth0_domain', $schema['properties'] );
		$this->assertArrayHasKey( 'client_id', $schema['properties'] );
		$this->assertArrayHasKey( 'client_secret', $schema['properties'] );
		$this->assertArrayHasKey( 'audience', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'auth0_domain', $schema['required'] );
		$this->assertContains( 'client_id', $schema['required'] );
		$this->assertContains( 'client_secret', $schema['required'] );
	}

	/**
	 * Test execution without authentication.
	 */
	public function test_execute_requires_authentication() {
		$result = $this->tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_auth0_token_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execution without manage_options capability.
	 */
	public function test_execute_requires_manage_options() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->subscriber_user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_auth0_token_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execution with missing parameters.
	 */
	public function test_execute_requires_all_parameters() {
		wp_set_current_user( $this->admin_user_id );

		// Missing all parameters.
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_auth0_token_missing_params', $result->get_error_code() );

		// Missing client_secret.
		$result = $this->tool->execute(
			array(
				'auth0_domain' => 'example.us.auth0.com',
				'client_id'    => 'test-client-id',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_auth0_token_missing_params', $result->get_error_code() );
	}

	/**
	 * Test execution with HTTP error.
	 */
	public function test_execute_handles_http_error() {
		wp_set_current_user( $this->admin_user_id );

		// Mock wp_remote_post to return an error.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'oauth/token' ) !== false ) {
					return new WP_Error( 'http_request_failed', 'Network error' );
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'auth0_domain'  => 'example.us.auth0.com',
				'client_id'     => 'test-client-id',
				'client_secret' => 'test-client-secret',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_auth0_token_request_failed', $result->get_error_code() );

		// Clean up filter.
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test execution with Auth0 rejection.
	 */
	public function test_execute_handles_auth0_rejection() {
		wp_set_current_user( $this->admin_user_id );

		// Mock wp_remote_post to return a 401 error.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'oauth/token' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 401,
							'message' => 'Unauthorized',
						),
						'body'     => wp_json_encode(
							array(
								'error'             => 'access_denied',
								'error_description' => 'Invalid client credentials',
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'auth0_domain'  => 'example.us.auth0.com',
				'client_id'     => 'invalid-client-id',
				'client_secret' => 'invalid-client-secret',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_auth0_token_rejected', $result->get_error_code() );
		$this->assertStringContainsString( 'Invalid client credentials', $result->get_error_message() );

		// Clean up filter.
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test successful token generation.
	 */
	public function test_execute_generates_token_successfully() {
		wp_set_current_user( $this->admin_user_id );

		$mock_token = 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.test.token';

		// Mock wp_remote_post to return a successful response.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $mock_token ) {
				if ( strpos( $url, 'oauth/token' ) !== false ) {
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'access_token' => $mock_token,
								'token_type'   => 'Bearer',
								'expires_in'   => 86400,
								'scope'        => 'read:users',
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'auth0_domain'  => 'example.us.auth0.com',
				'client_id'     => 'valid-client-id',
				'client_secret' => 'valid-client-secret',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'access_token', $result );
		$this->assertArrayHasKey( 'token_type', $result );
		$this->assertArrayHasKey( 'expires_in', $result );
		$this->assertArrayHasKey( 'expires_at', $result );
		$this->assertArrayHasKey( 'scope', $result );
		$this->assertSame( $mock_token, $result['access_token'] );
		$this->assertSame( 'Bearer', $result['token_type'] );
		$this->assertSame( 86400, $result['expires_in'] );
		$this->assertSame( 'read:users', $result['scope'] );
		$this->assertNotNull( $result['expires_at'] );

		// Clean up filter.
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test custom audience parameter.
	 */
	public function test_execute_with_custom_audience() {
		wp_set_current_user( $this->admin_user_id );

		$mock_token      = 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.test.token';
		$custom_audience = 'https://api.example.com';

		// Capture the request body to verify audience.
		$captured_body = null;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $mock_token, &$captured_body ) {
				if ( strpos( $url, 'oauth/token' ) !== false ) {
					$captured_body = isset( $args['body'] ) ? $args['body'] : null;
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'access_token' => $mock_token,
								'token_type'   => 'Bearer',
								'expires_in'   => 86400,
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'auth0_domain'  => 'example.us.auth0.com',
				'client_id'     => 'valid-client-id',
				'client_secret' => 'valid-client-secret',
				'audience'      => $custom_audience,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertIsArray( $result );
		$this->assertNotNull( $captured_body );

		$request_data = json_decode( $captured_body, true );
		$this->assertArrayHasKey( 'audience', $request_data );
		$this->assertSame( $custom_audience, $request_data['audience'] );

		// Clean up filter.
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Test default audience (Management API).
	 */
	public function test_execute_with_default_audience() {
		wp_set_current_user( $this->admin_user_id );

		$mock_token   = 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.test.token';
		$auth0_domain = 'example.us.auth0.com';

		// Capture the request body to verify audience.
		$captured_body = null;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $mock_token, &$captured_body ) {
				if ( strpos( $url, 'oauth/token' ) !== false ) {
					$captured_body = isset( $args['body'] ) ? $args['body'] : null;
					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'access_token' => $mock_token,
								'token_type'   => 'Bearer',
								'expires_in'   => 86400,
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'auth0_domain'  => $auth0_domain,
				'client_id'     => 'valid-client-id',
				'client_secret' => 'valid-client-secret',
				// No audience parameter provided.
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertIsArray( $result );
		$this->assertNotNull( $captured_body );

		$request_data = json_decode( $captured_body, true );
		$this->assertArrayHasKey( 'audience', $request_data );
		$this->assertSame( 'https://' . $auth0_domain . '/api/v2/', $request_data['audience'] );

		// Clean up filter.
		remove_all_filters( 'pre_http_request' );
	}
}
