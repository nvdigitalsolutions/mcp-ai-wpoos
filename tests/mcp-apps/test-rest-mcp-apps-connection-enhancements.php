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

	/**
	 * Re-saving an app whose masked token was echoed back keeps the stored
	 * credential (090-B console write path).
	 */
	public function test_update_preserves_stored_token_when_masked_echo_is_sent() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'      => 'Saved App',
					'server_url' => 'https://example.com/mcp',
					'auth_type'  => 'bearer',
					'token'      => 'stored-secret',
					'enabled'    => true,
				),
			)
		);

		$request = new WP_REST_Request( 'PUT', '/mcp-ai/v1/mcp-apps/' . $this->assistant_id );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param(
			'apps',
			array(
				array(
					'label'      => 'Saved App',
					'server_url' => 'https://example.com/mcp',
					'auth_type'  => 'bearer',
					'token'      => '••••••••cret',
					'enabled'    => true,
				),
			)
		);

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$response   = $controller->update_assistant_apps( $request );

		$this->assertNotWPError( $response );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 1, $data['app_count'] );

		// The response never echoes the real secret.
		$this->assertStringStartsWith( '••', $data['apps'][0]['token'] );

		// …and the stored credential survives the masked round-trip.
		$stored = $registry->get_apps( $this->assistant_id );
		$this->assertSame( 'stored-secret', $stored[0]['token'] );
	}

	/**
	 * A connection-backed entry needs no inline URL: the reference resolves the
	 * endpoint at chat time and no secret is stored here.
	 */
	public function test_update_accepts_connection_ref_only_entry() {
		$request = new WP_REST_Request( 'PUT', '/mcp-ai/v1/mcp-apps/' . $this->assistant_id );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param(
			'apps',
			array(
				array(
					'label'          => 'Elementor Cloud',
					'connection_ref' => 'conn-1',
					'auth_type'      => 'none',
					'enabled'        => true,
				),
			)
		);

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$response   = $controller->update_assistant_apps( $request );

		$this->assertNotWPError( $response );

		$data = $response->get_data();
		$this->assertSame( 1, $data['app_count'] );
		$this->assertSame( 'conn-1', $data['apps'][0]['connection_ref'] );
		$this->assertSame( '', $data['apps'][0]['server_url'] );
		$this->assertEmpty( $data['apps'][0]['token'] );
	}

	/**
	 * Entries with neither an endpoint nor a connection reference are dropped,
	 * matching the metabox save rule.
	 */
	public function test_update_drops_entries_without_endpoint_or_reference() {
		$request = new WP_REST_Request( 'PUT', '/mcp-ai/v1/mcp-apps/' . $this->assistant_id );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param(
			'apps',
			array(
				array( 'label' => 'No target' ),
				array(
					'label'          => 'Elementor Cloud',
					'connection_ref' => 'conn-1',
				),
			)
		);

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$response   = $controller->update_assistant_apps( $request );

		$this->assertNotWPError( $response );
		$this->assertSame( 1, $response->get_data()['app_count'] );
	}

	/**
	 * Re-saving an OAuth app keeps the stored token blob when the masked echo
	 * is sent back.
	 */
	public function test_update_preserves_oauth_data_when_masked_echo_is_sent() {
		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'      => 'OAuth App',
					'server_url' => 'https://example.com/mcp',
					'auth_type'  => 'oauth',
					'oauth_data' => array(
						'access_token'  => 'access-123',
						'refresh_token' => 'refresh-456',
						'token_type'    => 'Bearer',
					),
					'enabled'    => true,
				),
			)
		);

		$request = new WP_REST_Request( 'PUT', '/mcp-ai/v1/mcp-apps/' . $this->assistant_id );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param(
			'apps',
			array(
				array(
					'label'      => 'OAuth App',
					'server_url' => 'https://example.com/mcp',
					'auth_type'  => 'oauth',
					'oauth_data' => array(
						'access_token'  => '••••••••',
						'refresh_token' => '••••••••',
					),
					'enabled'    => true,
				),
			)
		);

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$response   = $controller->update_assistant_apps( $request );

		$this->assertNotWPError( $response );

		$data = $response->get_data();
		$this->assertSame( '••••••••', $data['apps'][0]['oauth_data']['access_token'] );

		$stored = $registry->get_apps( $this->assistant_id );
		$this->assertSame( 'access-123', $stored[0]['oauth_data']['access_token'] );
		$this->assertSame( 'refresh-456', $stored[0]['oauth_data']['refresh_token'] );
	}

	/**
	 * Unknown assistant IDs are a 404, not a silent write.
	 */
	public function test_update_rejects_unknown_assistant() {
		$request = new WP_REST_Request( 'PUT', '/mcp-ai/v1/mcp-apps/999999' );
		$request->set_param( 'assistant_id', 999999 );
		$request->set_param( 'apps', array() );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$result     = $controller->update_assistant_apps( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * The registry cap still applies to the console write path.
	 */
	public function test_update_enforces_the_per_assistant_cap() {
		$apps = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$apps[] = array(
				'label'          => 'App ' . $i,
				'connection_ref' => 'conn-' . $i,
			);
		}

		$request = new WP_REST_Request( 'PUT', '/mcp-ai/v1/mcp-apps/' . $this->assistant_id );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'apps', $apps );

		$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
		$response   = $controller->update_assistant_apps( $request );

		$this->assertNotWPError( $response );
		$this->assertSame(
			WP_MCP_AI_MCP_App_Registry::MAX_APPS_PER_ASSISTANT,
			$response->get_data()['app_count']
		);
	}
}
