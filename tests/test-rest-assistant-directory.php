<?php
/**
 * Tests for the assistant directory REST endpoint.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_REST_Assistant_Directory_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID used for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Ensure the directory returns published assistants and marks the default.
	 */
	public function test_directory_returns_accessible_assistants_with_metadata() {
		$first_assistant  = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Alpha Assistant',
			)
		);
		$second_assistant = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Beta Assistant',
			)
		);

		$settings                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['default_assistant'] = $first_assistant;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'assistants', $data );
		$this->assertCount( 2, $data['assistants'] );

		$ids = wp_list_pluck( $data['assistants'], 'id' );
		sort( $ids );
		$this->assertSame( array( $first_assistant, $second_assistant ), $ids );

		$this->assertSame( $first_assistant, $data['default_assistant'] );
		$this->assertArrayHasKey( 'rest', $data );
		$this->assertArrayHasKey( 'chat', $data['rest'] );
		$this->assertArrayHasKey( 'capabilities', $data );
		$this->assertArrayHasKey( 'implementation', $data );
		$this->assertArrayHasKey( 'name', $data['implementation'] );
		$this->assertArrayHasKey( 'version', $data['implementation'] );
		$this->assertArrayHasKey( 'tools', $data['capabilities'] );
		$this->assertArrayHasKey( 'resources', $data['capabilities'] );

		$assistants_by_id = array();
		foreach ( $data['assistants'] as $assistant ) {
			$assistants_by_id[ $assistant['id'] ] = $assistant;
		}

		$this->assertTrue( $assistants_by_id[ $first_assistant ]['is_default'] );
		$this->assertFalse( $assistants_by_id[ $second_assistant ]['is_default'] );
		$this->assertIsArray( $assistants_by_id[ $first_assistant ]['tools'] );
	}

	/**
	 * Ensure the directory can stream results when clients request Server-Sent Events.
	 */
	public function test_directory_streams_response_when_accept_header_requests_event_stream() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Streamed Directory Assistant',
			)
		);

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$existing_keys = array();
		if ( isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook ) {
			$existing_keys = array_keys( $GLOBALS['wp_filter']['rest_pre_serve_request']->callbacks[999] ?? array() );
		}

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'text/event-stream' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$headers = $response->get_headers();

		$this->assertStringStartsWith( 'text/event-stream', $headers['Content-Type'] ?? '' );
		$this->assertSame( '*', $headers['Access-Control-Allow-Origin'] ?? '' );
		$this->assertSame( 'Authorization, Content-Type, X-WP-Nonce', $headers['Access-Control-Allow-Headers'] ?? '' );
		$this->assertSame( 'GET, POST, OPTIONS', $headers['Access-Control-Allow-Methods'] ?? '' );
		$this->assertSame( 'Accept, Authorization', $headers['Vary'] ?? '' );

		$hook = isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook
			? $GLOBALS['wp_filter']['rest_pre_serve_request']
			: null;

		$this->assertInstanceOf( WP_Hook::class, $hook );

		$current_keys = array_keys( $hook->callbacks[999] ?? array() );
		$added_keys   = array_diff( $current_keys, $existing_keys );

		$this->assertNotEmpty( $added_keys );

		$closure_key = array_pop( $added_keys );
		$closure     = $hook->callbacks[999][ $closure_key ]['function'];

		$output = $this->extract_event_stream_frames( $closure );
		$served = $this->safely_invoke_event_stream_callback( $closure, $response, $request );

		$this->assertTrue( $served );
		$this->assertStringContainsString( 'event: directory', $output );
		$this->assertStringContainsString( 'data: {', $output );
		$this->assertStringContainsString( 'data: [DONE]', $output );

		if ( isset( $hook->callbacks[999][ $closure_key ] ) ) {
			unset( $hook->callbacks[999][ $closure_key ] );
		}

		$payload_lines = array();
		foreach ( explode( "\n", $output ) as $line ) {
			if ( 0 === strpos( $line, 'data: ' ) ) {
				$payload_lines[] = substr( $line, 6 );
			}

			if ( '' === trim( $line ) && ! empty( $payload_lines ) ) {
				break;
			}
		}

		$payload_json = implode( "\n", $payload_lines );
		$decoded      = json_decode( $payload_json, true );

		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'assistants', $decoded );
		$this->assertSame( array( $assistant_id ), wp_list_pluck( $decoded['assistants'], 'id' ) );
	}

	/**
	 * Ensure mixed Accept headers continue to stream the directory payload.
	 */
	public function test_directory_streams_with_mixed_accept_header_values() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Streamed Directory Assistant',
			)
		);

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'text/html;q=0.1, text/event-stream, application/json' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$headers = $response->get_headers();

		$this->assertStringStartsWith( 'text/event-stream', $headers['Content-Type'] ?? '' );
		$this->assertSame( '*', $headers['Access-Control-Allow-Origin'] ?? '' );
		$this->assertSame( 'Authorization, Content-Type, X-WP-Nonce', $headers['Access-Control-Allow-Headers'] ?? '' );
		$this->assertSame( 'GET, POST, OPTIONS', $headers['Access-Control-Allow-Methods'] ?? '' );
		$this->assertSame( 'Accept, Authorization', $headers['Vary'] ?? '' );
	}

	/**
	 * Ensure the dedicated /sse endpoint streams the directory even without Accept headers.
	 */
	public function test_sse_endpoint_streams_directory_without_accept_header() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'SSE Directory Assistant',
			)
		);

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/sse' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$headers = $response->get_headers();

		$this->assertStringStartsWith( 'text/event-stream', $headers['Content-Type'] ?? '' );
		$this->assertSame( '*', $headers['Access-Control-Allow-Origin'] ?? '' );
		$this->assertSame( 'Authorization, Content-Type, X-WP-Nonce', $headers['Access-Control-Allow-Headers'] ?? '' );
		$this->assertSame( 'GET, POST, OPTIONS', $headers['Access-Control-Allow-Methods'] ?? '' );
		$this->assertSame( 'Accept, Authorization', $headers['Vary'] ?? '' );

		$hook = isset( $GLOBALS['wp_filter']['rest_pre_serve_request'] ) && $GLOBALS['wp_filter']['rest_pre_serve_request'] instanceof WP_Hook
			? $GLOBALS['wp_filter']['rest_pre_serve_request']
			: null;

		$this->assertInstanceOf( WP_Hook::class, $hook );

		$current_keys = array_keys( $hook->callbacks[999] ?? array() );
		$this->assertNotEmpty( $current_keys );

		$closure_key = array_pop( $current_keys );
		$closure     = $hook->callbacks[999][ $closure_key ]['function'];

		$output = $this->extract_event_stream_frames( $closure );
		$served = $this->safely_invoke_event_stream_callback( $closure, $response, $request );

		$this->assertTrue( $served );
		$this->assertStringContainsString( 'event: directory', $output );
		$this->assertStringContainsString( 'data: {', $output );
		$this->assertStringContainsString( 'data: [DONE]', $output );

		if ( isset( $hook->callbacks[999][ $closure_key ] ) ) {
			unset( $hook->callbacks[999][ $closure_key ] );
		}

		$payload_lines = array();
		foreach ( explode( "\n", $output ) as $line ) {
			if ( 0 === strpos( $line, 'data: ' ) ) {
				$payload_lines[] = substr( $line, 6 );
			}

			if ( '' === trim( $line ) && ! empty( $payload_lines ) ) {
				break;
			}
		}

		$payload_json = implode( "\n", $payload_lines );
		$decoded      = json_decode( $payload_json, true );

		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'assistants', $decoded );
		$this->assertSame( array( $assistant_id ), wp_list_pluck( $decoded['assistants'], 'id' ) );
	}

	/**
	 * Ensure assistant-issued credentials scope the directory to a single assistant.
	 */
	public function test_directory_scopes_results_for_local_token() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'Scoped Assistant',
			)
		);

		$issuer_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $issuer_id );
		$issued = WP_MCP_AI_Credentials::issue_credential( $assistant_id, $issuer_id );

		wp_set_current_user( 0 );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'Authorization', 'Bearer ' . $issued['token'] );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertCount( 1, $data['assistants'] );
		$this->assertSame( $assistant_id, $data['assistants'][0]['id'] );
		$this->assertSame( $assistant_id, $data['default_assistant'] );
		$this->assertArrayHasKey( 'token_scope', $data );
		$this->assertSame( 'local_token', $data['token_scope']['type'] );
		$this->assertSame( $assistant_id, $data['token_scope']['assistant_id'] );
		$this->assertArrayHasKey( 'rest', $data );
		$this->assertArrayHasKey( 'chat', $data['rest'] );
		$this->assertArrayHasKey( 'capabilities', $data );
		$this->assertArrayHasKey( 'implementation', $data );
	}

	/**
	 * Ensure the directory endpoint accepts POST requests for connectivity checks.
	 */
	public function test_directory_accepts_post_requests() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'POST Directory Assistant',
			)
		);

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'assistants', $data );
		$this->assertCount( 1, $data['assistants'] );
		$this->assertSame( $assistant_id, $data['assistants'][0]['id'] );
		$this->assertSame( $assistant_id, $data['default_assistant'] );
		$this->assertArrayHasKey( 'capabilities', $data );
		$this->assertArrayHasKey( 'implementation', $data );
	}

	/**
	 * Ensure public capability overrides still respect publication status.
	 */
	public function test_directory_respects_public_capability_and_omits_unpublished() {
		$published = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Public Directory Assistant',
			)
		);
		wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'Hidden Directory Assistant',
			)
		);

		$public_filter = function ( $capability, $assistant_id, $context ) {
			if ( 'rest' === $context ) {
				return 'public';
			}

			return $capability;
		};

		add_filter( 'wp_mcp_ai_chat_capability', $public_filter, 10, 3 );

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$response = rest_get_server()->dispatch( $request );

		remove_filter( 'wp_mcp_ai_chat_capability', $public_filter, 10 );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertCount( 1, $data['assistants'] );
		$this->assertSame( $published, $data['assistants'][0]['id'] );
	}

	/**
	 * Extract the raw Server-Sent Events frames from a rest_pre_serve_request callback.
	 *
	 * @param callable $callback Stream callback registered with rest_pre_serve_request.
	 * @return string
	 */
	protected function extract_event_stream_frames( $callback ) {
		if ( ! $callback instanceof \Closure ) {
			return '';
		}

		$reflection = new ReflectionFunction( $callback );
		$statics    = $reflection->getStaticVariables();

		return isset( $statics['frames'] ) ? (string) $statics['frames'] : '';
	}

	/**
	 * Invoke the stream callback without flushing PHPUnit's output buffers.
	 *
	 * @param callable         $callback Stream callback registered with rest_pre_serve_request.
	 * @param WP_REST_Response $response REST response instance.
	 * @param WP_REST_Request  $request  REST request instance.
	 * @return bool
	 */
	protected function safely_invoke_event_stream_callback( $callback, WP_REST_Response $response, WP_REST_Request $request ) {
		if ( ! $callback instanceof \Closure ) {
			return false;
		}

		return (bool) call_user_func( $callback, true, $response, $request, rest_get_server() );
	}

	/**
	 * Helper to bootstrap the REST controller with a mocked router.
	 *
	 * @param WP_MCP_AI_Language_Model_Router $client Router instance.
	 */
	protected function bootstrap_rest_controller( $client ) {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $client );

		rest_get_server();
		do_action( 'rest_api_init' );
	}
}
