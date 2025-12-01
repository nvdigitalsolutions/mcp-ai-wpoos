<?php
/**
 * Tests for WP_MCP_AI_Tool_Generic_REST class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test cases for the Generic REST API tool.
 *
 * @group tools
 * @group generic-rest
 */
class WP_MCP_AI_Tool_Generic_REST_Test extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Generic_REST
	 */
	protected $tool;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected $subscriber_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generic-rest.php';

		$this->tool = new WP_MCP_AI_Tool_Generic_REST();

		$this->admin_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Test tool slug.
	 */
	public function test_get_slug() {
		$this->assertEquals( 'generic_rest', $this->tool->get_slug() );
	}

	/**
	 * Test tool name.
	 */
	public function test_get_name() {
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertIsString( $this->tool->get_name() );
	}

	/**
	 * Test tool description.
	 */
	public function test_get_description() {
		$this->assertNotEmpty( $this->tool->get_description() );
		$this->assertIsString( $this->tool->get_description() );
	}

	/**
	 * Test parameters schema contains required properties.
	 */
	public function test_get_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'url', $schema['properties'] );
		$this->assertArrayHasKey( 'method', $schema['properties'] );
		$this->assertArrayHasKey( 'headers', $schema['properties'] );
		$this->assertArrayHasKey( 'body', $schema['properties'] );
		$this->assertArrayHasKey( 'query_params', $schema['properties'] );
		$this->assertArrayHasKey( 'timeout', $schema['properties'] );
		$this->assertArrayHasKey( 'auth_type', $schema['properties'] );
		$this->assertArrayHasKey( 'auth_value', $schema['properties'] );
		$this->assertContains( 'url', $schema['required'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_get_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'network-dependent', $flags );
	}

	/**
	 * Test execute requires manage_options capability.
	 */
	public function test_execute_requires_manage_options() {
		$result = $this->tool->execute(
			array( 'url' => 'https://example.com/api' ),
			array( 'user_id' => $this->subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execute requires URL parameter.
	 */
	public function test_execute_requires_url() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_url', $result->get_error_code() );
	}

	/**
	 * Test execute validates URL format.
	 */
	public function test_execute_validates_url_format() {
		$result = $this->tool->execute(
			array( 'url' => 'not-a-valid-url' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_url', $result->get_error_code() );
	}

	/**
	 * Test execute only allows HTTP and HTTPS.
	 */
	public function test_execute_blocks_non_http_schemes() {
		$result = $this->tool->execute(
			array( 'url' => 'ftp://example.com/file' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_scheme', $result->get_error_code() );
	}

	/**
	 * Test execute blocks localhost by default.
	 */
	public function test_execute_blocks_localhost() {
		$result = $this->tool->execute(
			array( 'url' => 'http://localhost/api' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_blocked_host', $result->get_error_code() );
	}

	/**
	 * Test execute blocks 127.0.0.1 by default.
	 */
	public function test_execute_blocks_loopback_ip() {
		$result = $this->tool->execute(
			array( 'url' => 'http://127.0.0.1/api' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_blocked_host', $result->get_error_code() );
	}

	/**
	 * Test execute validates HTTP method.
	 */
	public function test_execute_validates_http_method() {
		$result = $this->tool->execute(
			array(
				'url'    => 'https://example.com/api',
				'method' => 'INVALID',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_method', $result->get_error_code() );
	}

	/**
	 * Test execute requires auth_value when auth_type is not none.
	 */
	public function test_execute_requires_auth_value_for_auth_type() {
		add_filter( 'pre_http_request', '__return_empty_array' );

		$result = $this->tool->execute(
			array(
				'url'       => 'https://example.com/api',
				'auth_type' => 'bearer',
			),
			array( 'user_id' => $this->admin_id )
		);

		remove_filter( 'pre_http_request', '__return_empty_array' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_auth', $result->get_error_code() );
	}

	/**
	 * Test filter allows internal requests.
	 */
	public function test_filter_allows_internal_requests() {
		add_filter( 'wp_mcp_ai_generic_rest_allow_internal', '__return_true' );

		// Mock HTTP request to prevent actual network call.
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"success": true}',
				);
			}
		);

		$result = $this->tool->execute(
			array( 'url' => 'http://localhost/api/test' ),
			array( 'user_id' => $this->admin_id )
		);

		remove_all_filters( 'wp_mcp_ai_generic_rest_allow_internal' );
		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * Test successful GET request.
	 */
	public function test_execute_get_request_success() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, 'example.com' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'id'   => 1,
								'name' => 'Test Item',
							)
						),
						'headers'  => array( 'content-type' => 'application/json' ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array( 'url' => 'https://example.com/api/items/1' ),
			array( 'user_id' => $this->admin_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 200, $result['status_code'] );
		$this->assertEquals( 'json', $result['body_type'] );
		$this->assertEquals( 1, $result['body']['id'] );
		$this->assertEquals( 'Test Item', $result['body']['name'] );
	}

	/**
	 * Test POST request with JSON body.
	 */
	public function test_execute_post_request_with_json_body() {
		$captured_request = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				$captured_request = $args;
				return array(
					'response' => array( 'code' => 201 ),
					'body'     => wp_json_encode(
						array(
							'id'      => 123,
							'created' => true,
						)
					),
					'headers'  => array( 'content-type' => 'application/json' ),
				);
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'url'    => 'https://example.com/api/items',
				'method' => 'POST',
				'body'   => array(
					'name'  => 'New Item',
					'price' => 99.99,
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 201, $result['status_code'] );
		$this->assertEquals( 'POST', $result['method'] );

		// Verify request body was JSON encoded.
		$this->assertNotNull( $captured_request );
		$this->assertEquals( 'application/json', $captured_request['headers']['Content-Type'] );
		$this->assertStringContainsString( 'New Item', $captured_request['body'] );
	}

	/**
	 * Test bearer authentication.
	 */
	public function test_execute_with_bearer_auth() {
		$captured_request = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				$captured_request = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"authenticated": true}',
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'url'        => 'https://api.example.com/protected',
				'auth_type'  => 'bearer',
				'auth_value' => 'my-secret-token',
			),
			array( 'user_id' => $this->admin_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'Authorization', $captured_request['headers'] );
		$this->assertEquals( 'Bearer my-secret-token', $captured_request['headers']['Authorization'] );
	}

	/**
	 * Test custom header authentication.
	 */
	public function test_execute_with_header_auth() {
		$captured_request = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				$captured_request = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'url'         => 'https://api.example.com/endpoint',
				'auth_type'   => 'header',
				'auth_header' => 'X-Custom-Auth',
				'auth_value'  => 'custom-key-123',
			),
			array( 'user_id' => $this->admin_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'X-Custom-Auth', $captured_request['headers'] );
		$this->assertEquals( 'custom-key-123', $captured_request['headers']['X-Custom-Auth'] );
	}

	/**
	 * Test query parameters are added to URL.
	 */
	public function test_execute_with_query_params() {
		$captured_url = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_url ) {
				$captured_url = $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'url'          => 'https://api.example.com/search',
				'query_params' => array(
					'page'  => 2,
					'limit' => 10,
					'q'     => 'test query',
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'page=2', $captured_url );
		$this->assertStringContainsString( 'limit=10', $captured_url );
		$this->assertStringContainsString( 'q=test', $captured_url );
	}

	/**
	 * Test HTTP error handling.
	 */
	public function test_execute_handles_http_error() {
		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'Connection timed out' );
			}
		);

		$result = $this->tool->execute(
			array( 'url' => 'https://example.com/api' ),
			array( 'user_id' => $this->admin_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_request_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Connection timed out', $result->get_error_message() );
	}

	/**
	 * Test 4xx response handling.
	 */
	public function test_execute_handles_4xx_response() {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 404 ),
					'body'     => '{"error": "Not found"}',
					'headers'  => array( 'content-type' => 'application/json' ),
				);
			}
		);

		$result = $this->tool->execute(
			array( 'url' => 'https://example.com/api/missing' ),
			array( 'user_id' => $this->admin_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertEquals( 404, $result['status_code'] );
	}

	/**
	 * Test 5xx response handling.
	 */
	public function test_execute_handles_5xx_response() {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 500 ),
					'body'     => 'Internal Server Error',
					'headers'  => array(),
				);
			}
		);

		$result = $this->tool->execute(
			array( 'url' => 'https://example.com/api/error' ),
			array( 'user_id' => $this->admin_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertEquals( 500, $result['status_code'] );
		$this->assertEquals( 'raw', $result['body_type'] );
	}

	/**
	 * Test custom headers are included in request.
	 */
	public function test_execute_with_custom_headers() {
		$captured_request = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				$captured_request = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'url'     => 'https://api.example.com/endpoint',
				'headers' => array(
					'X-Custom-Header' => 'custom-value',
					'Accept-Language' => 'en-US',
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $result );
		$this->assertEquals( 'custom-value', $captured_request['headers']['X-Custom-Header'] );
		$this->assertEquals( 'en-US', $captured_request['headers']['Accept-Language'] );
	}

	/**
	 * Test timeout parameter is respected.
	 */
	public function test_execute_respects_timeout() {
		$captured_request = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				$captured_request = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'url'     => 'https://api.example.com/slow-endpoint',
				'timeout' => 60,
			),
			array( 'user_id' => $this->admin_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertEquals( 60, $captured_request['timeout'] );
	}

	/**
	 * Test timeout max limit is enforced.
	 */
	public function test_execute_enforces_max_timeout() {
		$captured_request = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				$captured_request = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'url'     => 'https://api.example.com/endpoint',
				'timeout' => 999,
			),
			array( 'user_id' => $this->admin_id )
		);

		remove_all_filters( 'pre_http_request' );

		// Max timeout should be 120.
		$this->assertEquals( 120, $captured_request['timeout'] );
	}

	/**
	 * Test tool is registered in registry.
	 */
	public function test_tool_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'generic_rest' );

		$this->assertNotNull( $tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Generic_REST', $tool );
	}

	/**
	 * Test tool is in external-tools group.
	 */
	public function test_tool_is_in_external_tools_group() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$group_map = $registry->get_tool_group_map();

		$this->assertArrayHasKey( 'generic_rest', $group_map );
		$this->assertEquals( 'external-tools', $group_map['generic_rest'] );
	}

	/**
	 * Test PUT method is allowed.
	 */
	public function test_execute_put_method() {
		$captured_request = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				$captured_request = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"updated": true}',
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'url'    => 'https://api.example.com/items/1',
				'method' => 'PUT',
				'body'   => array( 'name' => 'Updated Name' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $result );
		$this->assertEquals( 'PUT', $captured_request['method'] );
	}

	/**
	 * Test PATCH method is allowed.
	 */
	public function test_execute_patch_method() {
		$captured_request = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				$captured_request = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"patched": true}',
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'url'    => 'https://api.example.com/items/1',
				'method' => 'PATCH',
				'body'   => array( 'status' => 'active' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $result );
		$this->assertEquals( 'PATCH', $captured_request['method'] );
	}

	/**
	 * Test DELETE method is allowed.
	 */
	public function test_execute_delete_method() {
		$captured_request = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				$captured_request = $args;
				return array(
					'response' => array( 'code' => 204 ),
					'body'     => '',
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'url'    => 'https://api.example.com/items/1',
				'method' => 'DELETE',
			),
			array( 'user_id' => $this->admin_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $result );
		$this->assertEquals( 'DELETE', $captured_request['method'] );
	}

	/**
	 * Test basic auth encoding.
	 */
	public function test_execute_with_basic_auth() {
		$captured_request = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				$captured_request = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'url'        => 'https://api.example.com/basic-auth',
				'auth_type'  => 'basic',
				'auth_value' => 'username:password',
			),
			array( 'user_id' => $this->admin_id )
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'Authorization', $captured_request['headers'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode.
		$expected = 'Basic ' . base64_encode( 'username:password' );
		$this->assertEquals( $expected, $captured_request['headers']['Authorization'] );
	}

	/**
	 * Test request args filter.
	 */
	public function test_request_args_filter() {
		$filter_called = false;

		add_filter(
			'wp_mcp_ai_generic_rest_request_args',
			function ( $args, $url, $arguments, $context ) use ( &$filter_called ) {
				$filter_called             = true;
				$args['headers']['X-Test'] = 'filter-added';
				return $args;
			},
			10,
			4
		);

		$captured_request = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_request ) {
				$captured_request = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array( 'url' => 'https://api.example.com/filtered' ),
			array( 'user_id' => $this->admin_id )
		);

		remove_all_filters( 'wp_mcp_ai_generic_rest_request_args' );
		remove_all_filters( 'pre_http_request' );

		$this->assertTrue( $filter_called );
		$this->assertEquals( 'filter-added', $captured_request['headers']['X-Test'] );
	}
}
