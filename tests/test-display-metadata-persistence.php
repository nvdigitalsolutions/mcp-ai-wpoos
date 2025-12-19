<?php
/**
 * Test display metadata persistence for all message types.
 *
 * Verifies that display metadata (bubbleType, chartHtml, tool_calls, etc.)
 * is properly preserved when saving and retrieving conversations.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for display metadata persistence.
 */
class Test_Display_Metadata_Persistence extends WP_UnitTestCase {
	/**
	 * Administrator user ID for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Assistant post ID used in requests.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Mock transcript handler that captures stored records.
	 *
	 * @var object
	 */
	protected $transcript_handler;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant for Display Metadata',
			)
		);

		// Set up assistant configuration.
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_provider', 'openai' );

		rest_get_server();
		do_action( 'init' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );
		wp_set_current_user( 0 );
		$this->transcript_handler = null;
		parent::tearDown();
	}

	/**
	 * Provide a mock handler that captures transcript records without requiring JetEngine.
	 *
	 * @return object Mock handler instance.
	 */
	public function provide_transcript_handler() {
		if ( ! $this->transcript_handler ) {
			$this->transcript_handler = new class() {
				public $records = array();

				public function update_item( $record ) {
					$session_key = isset( $record['session_key'] ) ? $record['session_key'] : '';
					$user_id     = isset( $record['cct_author_id'] ) ? $record['cct_author_id'] : 0;

					if ( '' === $session_key || 0 === $user_id ) {
						return new WP_Error( 'invalid_record', 'Invalid session_key or user_id' );
					}

					$key                   = $session_key . '_' . $user_id;
					$this->records[ $key ] = $record;

					return true;
				}

				public function get_records( $session_key, $user_id ) {
					$key = $session_key . '_' . $user_id;
					if ( isset( $this->records[ $key ] ) ) {
						return array( $this->records[ $key ] );
					}
					return array();
				}
			};
		}

		return $this->transcript_handler;
	}

	/**
	 * Test that truncated response bubbleType is preserved.
	 */
	public function test_truncated_response_bubble_type_preserved() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$session_key = 'test-truncated-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Generate a large response',
			),
			array(
				'role'    => 'assistant',
				'content' => 'This is a truncated response. [... Result truncated by orchestration layer to fit within budget constraints ...]',
				'display' => array(
					'bubbleType' => 'truncated',
					'text'       => 'This is a truncated response. [... Result truncated by orchestration layer to fit within budget constraints ...]',
				),
			),
		);

		$save_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request->set_header( 'Content-Type', 'application/json' );
		$save_request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
				)
			)
		);

		$save_response = rest_get_server()->dispatch( $save_request );

		$this->assertEquals( 200, $save_response->get_status(), 'Save request should succeed' );

		// Verify the stored record contains display metadata with bubbleType.
		$handler        = $this->provide_transcript_handler();
		$stored_records = $handler->get_records( $session_key, $this->admin_id );
		$this->assertNotEmpty( $stored_records, 'Should have stored record' );

		$stored_record   = $stored_records[0];
		$request_payload = json_decode( $stored_record['request_payload'], true );
		$this->assertArrayHasKey( 'messages', $request_payload );
		$this->assertCount( 2, $request_payload['messages'] );

		$assistant_msg = $request_payload['messages'][1];
		$this->assertEquals( 'assistant', $assistant_msg['role'] );
		$this->assertArrayHasKey( 'display', $assistant_msg, 'Assistant message should have display metadata' );
		$this->assertEquals( 'truncated', $assistant_msg['display']['bubbleType'], 'bubbleType should be "truncated"' );
	}

	/**
	 * Test that chart HTML is preserved in display metadata.
	 */
	public function test_chart_html_preserved() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$session_key = 'test-chart-' . wp_generate_uuid4();
		$chart_html  = '<html><body><canvas id="chart"></canvas></body></html>';
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Create a chart',
			),
			array(
				'role'         => 'tool',
				'content'      => '{"result":"Chart created"}',
				'display'      => array(
					'text'        => '✓ Chart created',
					'chartHtml'   => $chart_html,
					'chartWidth'  => 800,
					'chartHeight' => 400,
					'bubbleType'  => 'tool',
				),
				'tool_call_id' => 'call_123',
			),
		);

		$save_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request->set_header( 'Content-Type', 'application/json' );
		$save_request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
				)
			)
		);

		$save_response = rest_get_server()->dispatch( $save_request );

		$this->assertEquals( 200, $save_response->get_status(), 'Save request should succeed' );

		// Verify chart HTML is preserved.
		$handler        = $this->provide_transcript_handler();
		$stored_records = $handler->get_records( $session_key, $this->admin_id );
		$this->assertNotEmpty( $stored_records );

		$stored_record   = $stored_records[0];
		$request_payload = json_decode( $stored_record['request_payload'], true );
		$tool_msg        = $request_payload['messages'][1];

		$this->assertEquals( 'tool', $tool_msg['role'] );
		$this->assertArrayHasKey( 'display', $tool_msg );
		$this->assertArrayHasKey( 'chartHtml', $tool_msg['display'] );
		$this->assertEquals( $chart_html, $tool_msg['display']['chartHtml'] );
		$this->assertEquals( 800, $tool_msg['display']['chartWidth'] );
		$this->assertEquals( 400, $tool_msg['display']['chartHeight'] );
	}

	/**
	 * Test that tool_calls are preserved in display metadata.
	 */
	public function test_tool_calls_preserved() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$session_key = 'test-toolcalls-' . wp_generate_uuid4();
		$tool_calls  = array(
			array(
				'id'       => 'call_abc123',
				'type'     => 'function',
				'function' => array(
					'name'      => 'get_weather',
					'arguments' => '{"location":"San Francisco"}',
				),
			),
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is the weather?',
			),
			array(
				'role'       => 'assistant',
				'content'    => null,
				'tool_calls' => $tool_calls,
				'display'    => array(
					'bubbleType' => 'assistant',
					'text'       => '',
					'tool_calls' => $tool_calls,
				),
			),
		);

		$save_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request->set_header( 'Content-Type', 'application/json' );
		$save_request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
				)
			)
		);

		$save_response = rest_get_server()->dispatch( $save_request );

		$this->assertEquals( 200, $save_response->get_status(), 'Save request should succeed' );

		// Verify tool_calls are preserved.
		$handler        = $this->provide_transcript_handler();
		$stored_records = $handler->get_records( $session_key, $this->admin_id );
		$this->assertNotEmpty( $stored_records );

		$stored_record   = $stored_records[0];
		$request_payload = json_decode( $stored_record['request_payload'], true );
		$assistant_msg   = $request_payload['messages'][1];

		$this->assertEquals( 'assistant', $assistant_msg['role'] );
		$this->assertArrayHasKey( 'tool_calls', $assistant_msg );
		$this->assertCount( 1, $assistant_msg['tool_calls'] );
		$this->assertEquals( 'call_abc123', $assistant_msg['tool_calls'][0]['id'] );

		// Verify display metadata also has tool_calls.
		$this->assertArrayHasKey( 'display', $assistant_msg );
		$this->assertArrayHasKey( 'tool_calls', $assistant_msg['display'] );
		$this->assertCount( 1, $assistant_msg['display']['tool_calls'] );
	}

	/**
	 * Test that all badge data (usage, cost, capabilityFlags) is preserved.
	 */
	public function test_badge_data_preserved() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$session_key = 'test-badges-' . wp_generate_uuid4();
		$messages    = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Response with badges',
				'display' => array(
					'bubbleType'      => 'assistant',
					'text'            => 'Response with badges',
					'usage'           => array(
						'prompt_tokens'     => 10,
						'completion_tokens' => 20,
						'total_tokens'      => 30,
					),
					'cost'            => array(
						'input_cost'  => 0.001,
						'output_cost' => 0.002,
						'total_cost'  => 0.003,
					),
					'capabilityFlags' => array( 'vision', 'function_calling' ),
				),
			),
		);

		$save_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$save_request->set_header( 'Content-Type', 'application/json' );
		$save_request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => $messages,
				)
			)
		);

		$save_response = rest_get_server()->dispatch( $save_request );

		$this->assertEquals( 200, $save_response->get_status(), 'Save request should succeed' );

		// Verify badge data is preserved.
		$handler        = $this->provide_transcript_handler();
		$stored_records = $handler->get_records( $session_key, $this->admin_id );
		$this->assertNotEmpty( $stored_records );

		$stored_record   = $stored_records[0];
		$request_payload = json_decode( $stored_record['request_payload'], true );
		$assistant_msg   = $request_payload['messages'][1];

		$this->assertArrayHasKey( 'display', $assistant_msg );
		$this->assertArrayHasKey( 'usage', $assistant_msg['display'] );
		$this->assertEquals( 30, $assistant_msg['display']['usage']['total_tokens'] );

		$this->assertArrayHasKey( 'cost', $assistant_msg['display'] );
		$this->assertEquals( 0.003, $assistant_msg['display']['cost']['total_cost'] );

		$this->assertArrayHasKey( 'capabilityFlags', $assistant_msg['display'] );
		$this->assertContains( 'vision', $assistant_msg['display']['capabilityFlags'] );
		$this->assertContains( 'function_calling', $assistant_msg['display']['capabilityFlags'] );
	}
}
