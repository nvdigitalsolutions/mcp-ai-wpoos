<?php
/**
 * Tests for the MCP Apps REST controller connection enhancements.
 *
 * Covers the /test and /discover handlers: stored-token resolution for
 * masked metabox credentials and per-app status persistence.
 *
 * @package WP_MCP_AI
 * @since   1.9.1
 */

/**
 * Tests for the MCP Apps REST controller enhancements.
 */
class Test_REST_MCP_Apps_Connection_Enhancements extends WP_UnitTestCase {

	/**
	 * Test assistant post ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-registry.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_REST_MCP_Apps_Controller' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-rest-mcp-apps-controller.php';
		}

		$this->assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Allowlist the test host so the SSRF URL guard passes without DNS.
		add_filter(
			'wp_mcp_ai_http_allowed_host',
			function ( $hosts, $host, $url ) {
				unset( $host, $url );
				$hosts[] = 'example.com';
				return $hosts;
			},
			10,
			3
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_http_allowed_host' );
		parent::tearDown();
	}

	/**
	 * Install a pre_http_request mock that records requests and serves a
	 * successful stateless discover + tools/list exchange.
	 *
	 * @param array $captured Reference for captured request args.
	 * @return void
	 */
	protected function install_success_mock( &$captured ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured ) {
				unset( $pre, $url );
				$captured[] = $args;

				$payload = json_decode( isset( $args['body'] ) ? $args['body'] : '', true );
				$method  = is_array( $payload ) && isset( $payload['method'] ) ? $payload['method'] : '';

				$result = array( 'serverInfo' => array( 'name' => 'Modern MCP' ) );
				if ( 'tools/list' === $method ) {
					$result = array( 'tools' => array( array( 'name' => 'ping' ) ) );
				}

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'jsonrpc' => '2.0',
							'id'      => 1,
							'result'  => $result,
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * Test the /test handler resolves a masked (omitted) token from the
	 * assistant's saved apps and persists a success status.
	 */
	public function test_test_handler_resolves_stored_token_and_persists_status() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'       => 'Saved App',
					'server_url'  => 'https://example.com/mcp',
					'auth_type'   => 'header',
					'token'       => 'stored-secret',
					'header_name' => 'X-API-Key',
					'enabled'     => true,
				),
			)
		);

		$captured = array();
		$this->install_success_mock( $captured );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp-apps/test' );
		$request->set_param( 'server_url', 'https://example.com/mcp' );
		$request->set_param( 'auth_type', 'header' );
		$request->set_param( 'token', '' ); // Masked in the metabox.
		$request->set_param( 'header_name', 'X-API-Key' );
		$request->set_param( 'assistant_id', $this->assistant_id );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$response   = $controller->test_connection( $request );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'Modern MCP', $data['server_info']['name'] );

		// The stored credential must have been attached to the outbound request.
		$this->assertNotEmpty( $captured );
		$this->assertSame( 'stored-secret', $captured[0]['headers']['X-API-Key'] );

		// Status must be persisted for the metabox badge.
		$statuses = $registry->get_app_status( $this->assistant_id );
		$key      = $registry->get_app_status_key(
			array(
				'server_url'  => 'https://example.com/mcp',
				'auth_type'   => 'header',
				'header_name' => 'X-API-Key',
			)
		);
		$this->assertArrayHasKey( $key, $statuses );
		$this->assertEquals( 'ok', $statuses[ $key ]['last_status'] );
		$this->assertEquals( 1, $statuses[ $key ]['tool_count'] );
	}

	/**
	 * Test the /test handler records an error status when the connection fails.
	 */
	public function test_test_handler_records_error_status() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				unset( $pre, $args, $url );
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'jsonrpc' => '2.0',
							'id'      => 1,
							'error'   => array(
								'code'    => -32600,
								'message' => 'Invalid Request: Missing Mcp-Session-Id header',
							),
						)
					),
					'response' => array(
						'code'    => 400,
						'message' => 'Bad Request',
					),
				);
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp-apps/test' );
		$request->set_param( 'server_url', 'https://example.com/mcp' );
		$request->set_param( 'auth_type', 'none' );
		$request->set_param( 'assistant_id', $this->assistant_id );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$response   = $controller->test_connection( $request );

		$this->assertWPError( $response );

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$statuses = $registry->get_app_status( $this->assistant_id );

		$this->assertNotEmpty( $statuses );
		$entry = reset( $statuses );
		$this->assertEquals( 'error', $entry['last_status'] );
		$this->assertStringContainsString( 'Missing Mcp-Session-Id', $entry['last_error'] );
	}

	/**
	 * Test the /discover handler returns formatted tools and persists status.
	 */
	public function test_discover_handler_returns_tools_and_persists_status() {
		$captured = array();
		$this->install_success_mock( $captured );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp-apps/discover' );
		$request->set_param( 'server_url', 'https://example.com/mcp' );
		$request->set_param( 'auth_type', 'none' );
		$request->set_param( 'refresh', true );
		$request->set_param( 'assistant_id', $this->assistant_id );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$response   = $controller->discover_tools( $request );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 1, $data['tool_count'] );
		$this->assertEquals( 'ping', $data['tools'][0]['name'] );

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$statuses = $registry->get_app_status( $this->assistant_id );
		$this->assertNotEmpty( $statuses );
		$entry = reset( $statuses );
		$this->assertEquals( 'ok', $entry['last_status'] );
		$this->assertEquals( 1, $entry['tool_count'] );
	}
}
