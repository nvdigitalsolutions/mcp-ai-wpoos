<?php
/**
 * Tests for WP_MCP_AI_Chat_Transcript_Recorder in base mode with JetEngine
 *
 * Verifies that transcript recording works correctly in base-only mode
 * when JetEngine is available, with or without Pro addon.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Chat Transcript Recorder with JetEngine
 */
class Test_Transcript_Recorder_Base_JetEngine extends WP_UnitTestCase {

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
	 * Set up before each test
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
				'post_title'  => 'Transcript Test Assistant',
			)
		);

		// Configure assistant with basic settings.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_model', 'gpt-4o-mini' );
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_provider', 'openai' );

		rest_get_server();
		do_action( 'init' );

		// Set up mock handler.
		$this->setup_mock_handler();
	}

	/**
	 * Tear down after each test
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );
		wp_set_current_user( 0 );
		$this->transcript_handler = null;
		parent::tearDown();
	}

	/**
	 * Set up mock handler for transcript storage.
	 */
	protected function setup_mock_handler() {
		// Create a mock handler that captures the record.
		$this->transcript_handler = new class() {
			public $last_record = null;

			public function update_item( $record ) {
				$this->last_record = $record;
				return 12345; // Return a mock ID.
			}
		};

		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10, 7 );
	}

	/**
	 * Provide the mock transcript handler via filter.
	 *
	 * @return object Mock handler.
	 */
	public function provide_transcript_handler() {
		return $this->transcript_handler;
	}

	/**
	 * Test that transcript recording works when JetEngine handler is available.
	 *
	 * This test verifies that transcript recording works when a handler is available
	 * via the filter hook, simulating JetEngine CCT availability.
	 */
	public function test_transcript_recording_with_handler_available() {
		// This test works regardless of Pro addon status.
		// The mock handler simulates JetEngine CCT availability.

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Test response',
			),
		);

		$options = array(
			'model' => 'gpt-4o-mini',
		);

		$response = array(
			'id'      => 'chatcmpl-test123',
			'model'   => 'gpt-4o-mini',
			'choices' => array(
				array(
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Test response',
					),
					'finish_reason' => 'stop',
				),
			),
		);

		// Create a mock REST request.
		$request = new WP_REST_Request( 'POST', '/wp-json/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'messages', $messages );

		$context = array(
			'session_key'           => 'test-jetengine-session-123',
			'save_transcript'       => true,
			'request_started_at'    => microtime( true ),
			'response_completed_at' => microtime( true ),
		);

		// Record the transcript.
		$result = WP_MCP_AI_Chat_Transcript_Recorder::record(
			$this->assistant_id,
			$messages,
			$options,
			$response,
			$request,
			$this->admin_id,
			$context
		);

		// Verify that recording succeeded (returned session key, not null).
		$this->assertNotNull( $result, 'Transcript recording should succeed when handler is available' );
		$this->assertEquals( 'test-jetengine-session-123', $result, 'Should return the session key' );

		// Verify the handler received the correct data.
		$this->assertNotNull( $this->transcript_handler->last_record, 'Handler should receive a record' );
		$this->assertEquals( 'test-jetengine-session-123', $this->transcript_handler->last_record['session_key'] );
		$this->assertEquals( (string) $this->assistant_id, $this->transcript_handler->last_record['assistant_id'] );
		$this->assertEquals( $this->admin_id, $this->transcript_handler->last_record['user_id'] );
	}

	/**
	 * Test that transcript recording works in base mode when JetEngine CCT class is available.
	 *
	 * This test verifies that base-only mode with JetEngine supports transcript persistence.
	 */
	public function test_transcript_recording_in_base_mode_with_jetengine() {
		// Ensure Pro version is not defined for this test.
		// Note: We can't undefine constants, so this test assumes WP_MCP_AI_PRO_VERSION
		// is not defined at test start. If it is, skip this test.
		if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Cannot test pure base mode when Pro is already defined' );
		}

		// Using mock handler simulates having JetEngine CCT available.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);

		$options = array(
			'model' => 'gpt-4o-mini',
		);

		$response = array(
			'id'      => 'chatcmpl-test123',
			'model'   => 'gpt-4o-mini',
			'choices' => array(
				array(
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Test response',
					),
					'finish_reason' => 'stop',
				),
			),
		);

		$request = new WP_REST_Request( 'POST', '/wp-json/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'messages', $messages );

		$context = array(
			'session_key'           => 'test-base-jetengine-session-456',
			'save_transcript'       => true,
			'request_started_at'    => microtime( true ),
			'response_completed_at' => microtime( true ),
		);

		// In base mode with JetEngine available (simulated by mock handler),
		// recording should succeed.
		$result = WP_MCP_AI_Chat_Transcript_Recorder::record(
			$this->assistant_id,
			$messages,
			$options,
			$response,
			$request,
			$this->admin_id,
			$context
		);

		// With mock handler (simulating JetEngine availability), recording should succeed.
		$this->assertNotNull( $result, 'Transcript recording should succeed in base mode when JetEngine is available' );
		$this->assertEquals( 'test-base-jetengine-session-456', $result, 'Should return the session key' );

		// Verify the handler received the correct data.
		$this->assertNotNull( $this->transcript_handler->last_record, 'Handler should receive a record' );
		$this->assertEquals( 'test-base-jetengine-session-456', $this->transcript_handler->last_record['session_key'] );
	}

	/**
	 * Test resolve_handler method returns handler when mock filter is present.
	 *
	 * This test directly verifies that resolve_handler properly uses the filter hook.
	 */
	public function test_resolve_handler_with_mock_filter() {

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test',
			),
		);

		$options  = array( 'model' => 'gpt-4o-mini' );
		$response = array( 'id' => 'test' );
		$request  = new WP_REST_Request( 'POST', '/wp-json/mcp-ai/v1/chat' );
		$context  = array( 'session_key' => 'test-session' );

		// Use reflection to call the protected resolve_handler method.
		$recorder_class = new ReflectionClass( 'WP_MCP_AI_Chat_Transcript_Recorder' );
		$method         = $recorder_class->getMethod( 'resolve_handler' );
		$method->setAccessible( true );

		// Call resolve_handler.
		$handler = $method->invokeArgs(
			null,
			array(
				$this->assistant_id,
				$messages,
				$options,
				$response,
				$request,
				$context,
			)
		);

		// When we have a mock handler via filter (simulating JetEngine), should not be null.
		$this->assertNotNull( $handler, 'resolve_handler should return handler from filter hook' );
	}
}
