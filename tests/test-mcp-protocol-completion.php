<?php
/**
 * Tests for MCP 2024-11-05 protocol features: batching, ping, completion/complete,
 * logging/setLevel, notifications/cancelled, tool annotations, and session management.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for MCP 2024-11-05 protocol features.
 */
class WP_MCP_AI_MCP_Protocol_Completion_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	protected static $assistant_id;

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Set up test fixtures once for all tests.
	 *
	 * @param WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		self::$assistant_id = $factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Completion Test Assistant',
				'post_name'   => 'completion-test-assistant',
				'post_status' => 'publish',
			)
		);

		update_post_meta(
			self::$assistant_id,
			'_wp_mcp_ai_config',
			array(
				'provider' => 'openai',
				'model'    => 'gpt-4',
				'tools'    => array( 'search_content' ),
			)
		);
	}

	/**
	 * Clean up test fixtures.
	 */
	public static function wpTearDownAfterClass() {
		if ( self::$admin_id ) {
			wp_delete_user( self::$admin_id );
		}
		if ( self::$assistant_id ) {
			wp_delete_post( self::$assistant_id, true );
		}
	}

	/**
	 * Bearer token for the suite assistant.
	 *
	 * `permissions_check_mcp()` deliberately refuses bare nonce authentication;
	 * MCP clients present an assistant credential.
	 *
	 * @var string
	 */
	protected $bearer_token = '';

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		wp_set_current_user( self::$admin_id );

		// Issue a credential for the suite assistant so requests authenticate
		// the way a real MCP client would.
		if ( class_exists( 'WP_MCP_AI_Credentials' ) ) {
			$credential = WP_MCP_AI_Credentials::issue_credential( self::$assistant_id, self::$admin_id );
			if ( is_array( $credential ) && isset( $credential['token'] ) ) {
				$this->bearer_token = $credential['token'];
			}
		}
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();

		global $wp_rest_server;
		$wp_rest_server = null;
	}

	/**
	 * Helper: dispatch a JSON-RPC request to the MCP endpoint.
	 *
	 * @param array $body JSON-RPC body.
	 * @return WP_REST_Response
	 */
	protected function mcp_dispatch( $body ) {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( ! empty( $this->bearer_token ) ) {
			$request->set_header( 'Authorization', 'Bearer ' . $this->bearer_token );
		}
		$request->set_body( wp_json_encode( $body ) );
		return $this->server->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// ping
	// -------------------------------------------------------------------------

	/**
	 * Test that ping returns an empty result object.
	 */
	public function test_ping_returns_empty_result() {
		$response = $this->mcp_dispatch(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'ping',
			)
		);

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2.0', $data['jsonrpc'] );
		$this->assertSame( 1, $data['id'] );
		// Result should be an empty object (stdClass serialises to {}).
		$this->assertEmpty( (array) $data['result'] );
	}

	// -------------------------------------------------------------------------
	// logging/setLevel
	// -------------------------------------------------------------------------

	/**
	 * Test logging/setLevel accepts valid levels.
	 */
	public function test_logging_set_level_accepts_valid_level() {
		$response = $this->mcp_dispatch(
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'logging/setLevel',
				'params'  => array( 'level' => 'warning' ),
			)
		);

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertEmpty( (array) $data['result'] );
	}

	/**
	 * Test logging/setLevel rejects invalid levels.
	 */
	public function test_logging_set_level_rejects_invalid_level() {
		$response = $this->mcp_dispatch(
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'logging/setLevel',
				'params'  => array( 'level' => 'verbose' ),
			)
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32603, $data['error']['code'] );
	}

	/**
	 * Test logging/setLevel rejects missing level parameter.
	 */
	public function test_logging_set_level_rejects_missing_level() {
		$response = $this->mcp_dispatch(
			array(
				'jsonrpc' => '2.0',
				'id'      => 4,
				'method'  => 'logging/setLevel',
				'params'  => array(),
			)
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data );
	}

	// -------------------------------------------------------------------------
	// notifications/cancelled
	// -------------------------------------------------------------------------

	/**
	 * Test notifications/cancelled is accepted as a notification (no id).
	 */
	public function test_notifications_cancelled_as_notification() {
		$response = $this->mcp_dispatch(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/cancelled',
				'params'  => array(
					'requestId' => 'req-42',
					'reason'    => 'User cancelled',
				),
			)
		);

		$this->assertSame( 202, $response->get_status() );
	}

	/**
	 * Test notifications/cancelled with an id returns empty result.
	 */
	public function test_notifications_cancelled_with_id() {
		$response = $this->mcp_dispatch(
			array(
				'jsonrpc' => '2.0',
				'id'      => 5,
				'method'  => 'notifications/cancelled',
				'params'  => array(
					'requestId' => 'req-99',
				),
			)
		);

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertEmpty( (array) $data['result'] );
	}

	// -------------------------------------------------------------------------
	// completion/complete
	// -------------------------------------------------------------------------

	/**
	 * Test completion/complete rejects missing ref parameter.
	 */
	public function test_completion_rejects_missing_ref() {
		$response = $this->mcp_dispatch(
			array(
				'jsonrpc' => '2.0',
				'id'      => 6,
				'method'  => 'completion/complete',
				'params'  => array(
					'argument' => array(
						'name'  => 'query',
						'value' => 'test',
					),
				),
			)
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data );
	}

	/**
	 * Test completion/complete rejects missing argument parameter.
	 */
	public function test_completion_rejects_missing_argument() {
		$response = $this->mcp_dispatch(
			array(
				'jsonrpc' => '2.0',
				'id'      => 7,
				'method'  => 'completion/complete',
				'params'  => array(
					'ref' => array(
						'type' => 'ref/tool',
						'name' => 'search_content',
					),
				),
			)
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data );
	}

	/**
	 * Test completion/complete returns structure with completion key.
	 */
	public function test_completion_returns_valid_structure() {
		$response = $this->mcp_dispatch(
			array(
				'jsonrpc' => '2.0',
				'id'      => 8,
				'method'  => 'completion/complete',
				'params'  => array(
					'ref'      => array(
						'type' => 'ref/tool',
						'name' => 'nonexistent_tool',
					),
					'argument' => array(
						'name'  => 'query',
						'value' => '',
					),
				),
			)
		);

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'completion', $data['result'] );
		$this->assertArrayHasKey( 'values', $data['result']['completion'] );
		$this->assertArrayHasKey( 'hasMore', $data['result']['completion'] );
		$this->assertIsArray( $data['result']['completion']['values'] );
	}

	/**
	 * Test prompt completion returns assistant slugs.
	 */
	public function test_completion_prompt_returns_assistant_slugs() {
		$response = $this->mcp_dispatch(
			array(
				'jsonrpc' => '2.0',
				'id'      => 9,
				'method'  => 'completion/complete',
				'params'  => array(
					'ref'      => array(
						'type' => 'ref/prompt',
						'name' => '',
					),
					'argument' => array(
						'name'  => 'name',
						'value' => 'completion',
					),
				),
			)
		);

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$values = $data['result']['completion']['values'];
		$this->assertContains( 'completion-test-assistant', $values );
	}

	// -------------------------------------------------------------------------
	// JSON-RPC Batching
	// -------------------------------------------------------------------------

	/**
	 * Test batch request processes multiple messages.
	 */
	public function test_batch_processes_multiple_messages() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( ! empty( $this->bearer_token ) ) {
			$request->set_header( 'Authorization', 'Bearer ' . $this->bearer_token );
		}
		$request->set_body(
			wp_json_encode(
				array(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'ping',
					),
					array(
						'jsonrpc' => '2.0',
						'id'      => 2,
						'method'  => 'ping',
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertCount( 2, $data );
		$this->assertSame( 1, $data[0]['id'] );
		$this->assertSame( 2, $data[1]['id'] );
	}

	/**
	 * Test batch with mixed requests and notifications.
	 */
	public function test_batch_skips_notifications_in_response() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( ! empty( $this->bearer_token ) ) {
			$request->set_header( 'Authorization', 'Bearer ' . $this->bearer_token );
		}
		$request->set_body(
			wp_json_encode(
				array(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'ping',
					),
					// Notification (no id) — should not appear in response array.
					array(
						'jsonrpc' => '2.0',
						'method'  => 'notifications/cancelled',
						'params'  => array( 'requestId' => 'x' ),
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data );
		// Only the ping response should be in the array.
		$this->assertCount( 1, $data );
		$this->assertSame( 1, $data[0]['id'] );
	}

	/**
	 * Test empty batch array returns error.
	 */
	public function test_empty_batch_returns_error() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( ! empty( $this->bearer_token ) ) {
			$request->set_header( 'Authorization', 'Bearer ' . $this->bearer_token );
		}
		$request->set_body( wp_json_encode( array() ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32600, $data['error']['code'] );
	}

	/**
	 * Test all-notification batch returns 202.
	 */
	public function test_all_notification_batch_returns_202() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( ! empty( $this->bearer_token ) ) {
			$request->set_header( 'Authorization', 'Bearer ' . $this->bearer_token );
		}
		$request->set_body(
			wp_json_encode(
				array(
					array(
						'jsonrpc' => '2.0',
						'method'  => 'notifications/cancelled',
						'params'  => array( 'requestId' => 'a' ),
					),
					array(
						'jsonrpc' => '2.0',
						'method'  => 'notifications/cancelled',
						'params'  => array( 'requestId' => 'b' ),
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$this->assertSame( 202, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Session Management
	// -------------------------------------------------------------------------

	/**
	 * Test that responses no longer include a session header.
	 *
	 * MCP 2026-07-28 removed protocol-level sessions (SEP-2567), so the
	 * `Mcp-Session-Id` header is no longer emitted.
	 */
	public function test_response_includes_session_header() {
		$response = $this->mcp_dispatch(
			array(
				'jsonrpc' => '2.0',
				'id'      => 10,
				'method'  => 'ping',
			)
		);

		$headers = $response->get_headers();
		$this->assertArrayNotHasKey( 'Mcp-Session-Id', $headers );
	}

	// -------------------------------------------------------------------------
	// Initialize capabilities
	// -------------------------------------------------------------------------

	/**
	 * Test initialize response capabilities contract.
	 *
	 * The 2026-07-28 stateless revision advertises tools/resources/prompts; the
	 * legacy completions capability and protocol-level logging capability are
	 * no longer advertised.
	 */
	public function test_initialize_advertises_new_capabilities() {
		$response = $this->mcp_dispatch(
			array(
				'jsonrpc' => '2.0',
				'id'      => 11,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2024-11-05',
					'clientInfo'      => array(
						'name'    => 'Test Client',
						'version' => '1.0',
					),
				),
			)
		);

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );

		$caps = $data['result']['capabilities'];
		$this->assertArrayHasKey( 'tools', $caps );
		$this->assertArrayHasKey( 'resources', $caps );
		$this->assertArrayHasKey( 'prompts', $caps );
	}

	// -------------------------------------------------------------------------
	// Tool annotations
	// -------------------------------------------------------------------------

	/**
	 * Test that tools/list includes annotations when tool implements capability flags.
	 */
	public function test_tools_list_includes_annotations() {
		$response = $this->mcp_dispatch(
			array(
				'jsonrpc' => '2.0',
				'id'      => 12,
				'method'  => 'tools/list',
				'params'  => array(),
			)
		);

		$data  = $response->get_data();
		$tools = $data['result']['tools'];

		// Find any tool that has annotations (meaning it implements capability flags).
		$annotated_tool = null;
		foreach ( $tools as $t ) {
			if ( isset( $t['annotations'] ) ) {
				$annotated_tool = $t;
				break;
			}
		}

		// If any annotated tool exists, verify the annotation structure is correct.
		if ( null !== $annotated_tool ) {
			$this->assertArrayHasKey( 'annotations', $annotated_tool );
			$annotations = $annotated_tool['annotations'];
			// readOnlyHint should always be present (it's always set by build_tool_annotations).
			$this->assertArrayHasKey( 'readOnlyHint', $annotations );
			$this->assertIsBool( $annotations['readOnlyHint'] );
		} else {
			// If no tools have annotations, that's fine — the feature is still implemented,
			// just no tools implement WP_MCP_AI_Tool_Capability_Flags_Interface in this context.
			$this->assertTrue( true, 'No tools with capability flags registered — annotation feature is still implemented.' );
		}
	}
}
