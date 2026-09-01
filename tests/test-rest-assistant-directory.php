<?php
/**
 * Tests for the assistant directory REST endpoint.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_REST_Assistant_Directory_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID used for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Ensure the directory returns published assistants and marks the default.
	 */
	public function test_directory_returns_accessible_assistants_with_metadata() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		// Bootstrap before creating fixtures: the rest_api_init re-fire runs
		// third-party temp-table DDL that implicitly commits the open
		// per-test transaction, leaking anything written beforehand.
		$this->bootstrap_rest_controller( $mock_client );

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
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		// Bootstrap before creating fixtures: see the metadata test.
		$this->bootstrap_rest_controller( $mock_client );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Streamed Directory Assistant',
			)
		);

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'text/event-stream' );

		list( $response, $captured ) = $this->dispatch_and_capture( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$this->assertStringContainsString( 'retry:', $captured );
		$this->assertStringContainsString( 'event: directory', $captured );
		$this->assertStringContainsString( 'data: {', $captured );
		$this->assertStringContainsString( 'data: [DONE]', $captured );

		$decoded = json_decode( $this->extract_first_data_payload( $captured ), true );

		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'assistants', $decoded );
		$this->assertSame( array( $assistant_id ), wp_list_pluck( $decoded['assistants'], 'id' ) );
	}

	/**
	 * Ensure mixed Accept headers continue to stream the directory payload.
	 */
	public function test_directory_streams_with_mixed_accept_header_values() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		// Bootstrap before creating fixtures: see the metadata test.
		$this->bootstrap_rest_controller( $mock_client );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Streamed Directory Assistant',
			)
		);

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'text/html;q=0.1, text/event-stream, application/json' );

		list( $response, $captured ) = $this->dispatch_and_capture( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$this->assertStringContainsString( 'retry:', $captured );
		$this->assertStringContainsString( 'event: directory', $captured );
		$this->assertStringContainsString( 'data: {', $captured );
		$this->assertStringContainsString( 'data: [DONE]', $captured );

		$decoded = json_decode( $this->extract_first_data_payload( $captured ), true );

		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'assistants', $decoded );
		$this->assertSame( array( $assistant_id ), wp_list_pluck( $decoded['assistants'], 'id' ) );
	}

	/**
	 * Ensure the dedicated /sse endpoint streams the directory even without Accept headers.
	 */
	public function test_sse_endpoint_streams_directory_without_accept_header() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		// Bootstrap before creating fixtures: see the metadata test.
		$this->bootstrap_rest_controller( $mock_client );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'SSE Directory Assistant',
			)
		);

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/sse' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		list( $response, $captured ) = $this->dispatch_and_capture( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$this->assertStringContainsString( 'retry:', $captured );
		$this->assertStringContainsString( 'event: directory', $captured );
		$this->assertStringContainsString( 'data: {', $captured );
		$this->assertStringContainsString( 'data: [DONE]', $captured );

		$decoded = json_decode( $this->extract_first_data_payload( $captured ), true );

		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'assistants', $decoded );
		$this->assertSame( array( $assistant_id ), wp_list_pluck( $decoded['assistants'], 'id' ) );
	}

	/**
	 * Ensure assistant-issued credentials scope the directory to a single assistant.
	 */
	public function test_directory_scopes_results_for_local_token() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		// Bootstrap before creating fixtures: see the metadata test.
		$this->bootstrap_rest_controller( $mock_client );

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
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		// Bootstrap before creating fixtures: see the metadata test.
		$this->bootstrap_rest_controller( $mock_client );

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'POST Directory Assistant',
			)
		);

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

		// Bootstrap before creating fixtures: see the metadata test.
		$this->bootstrap_rest_controller( $mock_client );

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
	 * Dispatch a directory request and capture the echoed SSE frames.
	 *
	 * The streaming path echoes frames directly and cleans output buffers
	 * inside send_sse_headers(), so capture requires a callback buffer that
	 * survives the handler's buffer cleanup. Existing buffers are flattened
	 * first and the original level restored afterwards so PHPUnit's output
	 * buffer tracking stays balanced.
	 *
	 * @param WP_REST_Request $request Request to dispatch.
	 * @return array{0: WP_REST_Response, 1: string} Response and captured output.
	 */
	protected function dispatch_and_capture( WP_REST_Request $request ) {
		$initial_level = ob_get_level();

		// Flatten all buffers so the handler's buffer cleanup (which keeps only
		// the outermost buffer alive) cannot destroy our capture buffer.
		while ( ob_get_level() > 0 ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Deliberate: end_clean may fail on restricted hosts; level is re-checked next iteration.
			@ob_end_clean();
		}

		$captured = '';
		ob_start(
			static function ( $chunk ) use ( &$captured ) {
				$captured .= $chunk;
				return '';
			}
		);

		$response = rest_get_server()->dispatch( $request );

		while ( ob_get_level() > 0 ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Deliberate: see above.
			@ob_end_clean();
		}

		// Restore the original buffer count so PHPUnit does not flag the test
		// as risky for leaving output buffers open.
		for ( $i = 0; $i < $initial_level; $i++ ) {
			ob_start();
		}

		return array( $response, $captured );
	}

	/**
	 * Extract the first SSE data payload from a captured event stream.
	 *
	 * Collects consecutive `data: ` lines until the first blank line, which
	 * delimits the end of the frame per the SSE specification.
	 *
	 * @param string $stream Captured SSE stream.
	 * @return string Concatenated data payload.
	 */
	protected function extract_first_data_payload( $stream ) {
		$payload_lines = array();

		foreach ( explode( "\n", $stream ) as $line ) {
			if ( 0 === strpos( $line, 'data: ' ) ) {
				$payload_lines[] = substr( $line, 6 );
			}

			if ( '' === trim( $line ) && ! empty( $payload_lines ) ) {
				break;
			}
		}

		return implode( "\n", $payload_lines );
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

		// WP 6.9+ requires routes to be registered on rest_api_init.
		// NOTE: tests must call this helper BEFORE creating any fixtures -
		// third-party plugins (e.g. Elementor) hook temporary-table DDL onto
		// this action, and ALTER/CREATE INDEX on temporary tables implicitly
		// commits the open per-test transaction, leaking earlier writes.
		do_action( 'rest_api_init' );
	}
}
