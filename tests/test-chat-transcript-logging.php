<?php
/**
 * Tests for chat transcript save/load/delete logging functionality.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test chat transcript logging.
 */
class Test_Chat_Transcript_Logging extends WP_UnitTestCase {
	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Assistant post ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Chat controller instance.
	 *
	 * @var WP_MCP_AI_REST_Chat_Controller
	 */
	protected $controller;

	/**
	 * Main REST controller instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $main_controller;

	/**
	 * Captured log events.
	 *
	 * @var array
	 */
	protected $log_events = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		// Create admin user.
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Create test assistant.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Logging Test Assistant',
			)
		);

		update_post_meta( $this->assistant_id, 'wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_provider', 'openai' );

		// Initialize controllers.
		$this->main_controller = WP_MCP_AI_REST::get_instance();
		$this->controller      = new WP_MCP_AI_REST_Chat_Controller( $this->main_controller );

		// Register routes.
		rest_get_server();
		do_action( 'init' );

		// Enable logging for tests.
		update_option( 'wp_mcp_ai_enable_logging', '1' );

		// Hook into log events to capture them.
		add_filter( 'wp_mcp_ai_log_entry', array( $this, 'capture_log_event' ), 10, 4 );

		// Reset captured events.
		$this->log_events = array();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_log_entry', array( $this, 'capture_log_event' ), 10 );
		wp_set_current_user( 0 );
		delete_option( 'wp_mcp_ai_enable_logging' );
		parent::tearDown();
	}

	/**
	 * Capture log events for testing.
	 *
	 * @param array  $entry   Log entry.
	 * @param string $type    Event type.
	 * @param string $message Log message.
	 * @param array  $context Context array.
	 * @return array Log entry (unchanged).
	 */
	public function capture_log_event( $entry, $type, $message, $context ) {
		$this->log_events[] = array(
			'entry'   => $entry,
			'type'    => $type,
			'message' => $message,
			'context' => $context,
		);
		return $entry;
	}

	/**
	 * Test that save transcript operation logs debug and info events.
	 */
	public function test_save_transcript_logging() {
		// Create a mock transcript handler.
		add_filter(
			'wp_mcp_ai_chat_transcript_handler',
			function () {
				return new class() {
					public function update_item( $record ) {
						return true;
					}
				};
			}
		);

		// Prepare request data.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => 'test-session-' . wp_generate_uuid4(),
					'messages'     => array(
						array(
							'role'    => 'user',
							'content' => 'Test message',
						),
						array(
							'role'    => 'assistant',
							'content' => 'Test response',
						),
					),
				)
			)
		);

		// Clear previous log events.
		$this->log_events = array();

		// Call the save handler.
		$response = $this->controller->handle_chat_transcript_save( $request );

		// Check response is successful.
		$this->assertNotInstanceOf( WP_Error::class, $response );

		// Verify debug log was captured.
		$debug_logs = array_filter(
			$this->log_events,
			function ( $event ) {
				return 'debug' === $event['type'] && false !== strpos( $event['message'], 'handle_chat_transcript_save: Saving transcript' );
			}
		);

		$this->assertNotEmpty( $debug_logs, 'Debug log for save start should be captured' );

		$debug_log = reset( $debug_logs );
		$this->assertArrayHasKey( 'session_key', $debug_log['context'], 'Debug log should include session_key' );
		$this->assertArrayHasKey( 'assistant_id', $debug_log['context'], 'Debug log should include assistant_id' );
		$this->assertArrayHasKey( 'user_id', $debug_log['context'], 'Debug log should include user_id' );
		$this->assertArrayHasKey( 'message_count', $debug_log['context'], 'Debug log should include message_count' );
		$this->assertArrayHasKey( 'source', $debug_log['context'], 'Debug log should include source' );
		$this->assertEquals( 'chat_client', $debug_log['context']['source'], 'Source should be chat_client' );
		$this->assertEquals( 2, $debug_log['context']['message_count'], 'Message count should be 2' );

		// Verify info log was captured.
		$info_logs = array_filter(
			$this->log_events,
			function ( $event ) {
				return 'info' === $event['type'] && false !== strpos( $event['message'], 'handle_chat_transcript_save: Transcript saved successfully' );
			}
		);

		$this->assertNotEmpty( $info_logs, 'Info log for save success should be captured' );

		$info_log = reset( $info_logs );
		$this->assertArrayHasKey( 'session_key', $info_log['context'], 'Info log should include session_key' );
		$this->assertArrayHasKey( 'assistant_id', $info_log['context'], 'Info log should include assistant_id' );
		$this->assertArrayHasKey( 'user_id', $info_log['context'], 'Info log should include user_id' );
		$this->assertArrayHasKey( 'message_count', $info_log['context'], 'Info log should include message_count' );
	}

	/**
	 * Test that delete transcript operation logs debug and info events.
	 */
	public function test_delete_transcript_logging() {
		// Create a mock repository.
		$mock_repository = new class() {
			public function get_table_name() {
				return 'wp_test_transcripts';
			}

			public function table_exists() {
				return true;
			}

			public function delete_transcript( $session_key, $user_id ) {
				return 3; // Simulate 3 rows deleted.
			}
		};

		// Mock the repository getter.
		add_filter(
			'wp_mcp_ai_transcript_repository',
			function () use ( $mock_repository ) {
				return $mock_repository;
			}
		);

		// Prepare request.
		$session_key = 'test-session-' . wp_generate_uuid4();
		$request     = new WP_REST_Request( 'DELETE', '/mcp-ai/v1/chat-transcripts/' . $session_key );
		$request->set_param( 'session_key', $session_key );

		// Clear previous log events.
		$this->log_events = array();

		// Call the delete handler.
		$response = $this->controller->handle_chat_transcript_delete( $request );

		// Check response is successful.
		$this->assertNotInstanceOf( WP_Error::class, $response );

		// Verify debug log was captured.
		$debug_logs = array_filter(
			$this->log_events,
			function ( $event ) {
				return 'debug' === $event['type'] && false !== strpos( $event['message'], 'handle_chat_transcript_delete: Deleting transcript' );
			}
		);

		$this->assertNotEmpty( $debug_logs, 'Debug log for delete start should be captured' );

		$debug_log = reset( $debug_logs );
		$this->assertArrayHasKey( 'session_key', $debug_log['context'], 'Debug log should include session_key' );
		$this->assertArrayHasKey( 'user_id', $debug_log['context'], 'Debug log should include user_id' );
		$this->assertArrayHasKey( 'source', $debug_log['context'], 'Debug log should include source' );
		$this->assertEquals( 'chat_client', $debug_log['context']['source'], 'Source should be chat_client' );

		// Verify info log was captured.
		$info_logs = array_filter(
			$this->log_events,
			function ( $event ) {
				return 'info' === $event['type'] && false !== strpos( $event['message'], 'handle_chat_transcript_delete: Transcript deleted successfully' );
			}
		);

		$this->assertNotEmpty( $info_logs, 'Info log for delete success should be captured' );

		$info_log = reset( $info_logs );
		$this->assertArrayHasKey( 'session_key', $info_log['context'], 'Info log should include session_key' );
		$this->assertArrayHasKey( 'user_id', $info_log['context'], 'Info log should include user_id' );
		$this->assertArrayHasKey( 'deleted_rows', $info_log['context'], 'Info log should include deleted_rows' );
		$this->assertEquals( 3, $info_log['context']['deleted_rows'], 'Deleted rows should be 3' );
	}

	/**
	 * Test that delete failure logs error event.
	 */
	public function test_delete_transcript_failure_logging() {
		// Create a mock repository that fails.
		$mock_repository = new class() {
			public function get_table_name() {
				return 'wp_test_transcripts';
			}

			public function table_exists() {
				return true;
			}

			public function delete_transcript( $session_key, $user_id ) {
				return false; // Simulate deletion failure.
			}
		};

		// Mock the repository getter.
		add_filter(
			'wp_mcp_ai_transcript_repository',
			function () use ( $mock_repository ) {
				return $mock_repository;
			}
		);

		// Prepare request.
		$session_key = 'test-session-' . wp_generate_uuid4();
		$request     = new WP_REST_Request( 'DELETE', '/mcp-ai/v1/chat-transcripts/' . $session_key );
		$request->set_param( 'session_key', $session_key );

		// Clear previous log events.
		$this->log_events = array();

		// Call the delete handler.
		$response = $this->controller->handle_chat_transcript_delete( $request );

		// Check response is an error.
		$this->assertInstanceOf( WP_Error::class, $response );

		// Verify error log was captured.
		$error_logs = array_filter(
			$this->log_events,
			function ( $event ) {
				return 'error' === $event['type'] && false !== strpos( $event['message'], 'handle_chat_transcript_delete: Failed to delete transcript' );
			}
		);

		$this->assertNotEmpty( $error_logs, 'Error log for delete failure should be captured' );

		$error_log = reset( $error_logs );
		$this->assertArrayHasKey( 'session_key', $error_log['context'], 'Error log should include session_key' );
		$this->assertArrayHasKey( 'user_id', $error_log['context'], 'Error log should include user_id' );
	}

	/**
	 * Test that logging respects the logging enabled setting.
	 */
	public function test_logging_respects_enabled_setting() {
		// Disable logging.
		update_option( 'wp_mcp_ai_enable_logging', '0' );

		// Create a mock transcript handler.
		add_filter(
			'wp_mcp_ai_chat_transcript_handler',
			function () {
				return new class() {
					public function update_item( $record ) {
						return true;
					}
				};
			}
		);

		// Prepare request data.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => 'test-session-' . wp_generate_uuid4(),
					'messages'     => array(
						array(
							'role'    => 'user',
							'content' => 'Test message',
						),
					),
				)
			)
		);

		// Clear previous log events.
		$this->log_events = array();

		// Call the save handler.
		$response = $this->controller->handle_chat_transcript_save( $request );

		// Verify no logs were captured when logging is disabled.
		$this->assertEmpty( $this->log_events, 'No logs should be captured when logging is disabled' );

		// Re-enable logging for other tests.
		update_option( 'wp_mcp_ai_enable_logging', '1' );
	}

	/**
	 * Test that load transcript operation logs in handle_chat_transcripts.
	 */
	public function test_load_transcript_logging() {
		// Clear previous log events.
		$this->log_events = array();

		// Prepare request to get transcripts.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', $this->admin_id );

		// Call the handler.
		$response = $this->controller->handle_chat_transcripts( $request );

		// Verify debug log was captured.
		$debug_logs = array_filter(
			$this->log_events,
			function ( $event ) {
				return 'debug' === $event['type'] && false !== strpos( $event['message'], 'handle_chat_transcripts: Request parameters' );
			}
		);

		$this->assertNotEmpty( $debug_logs, 'Debug log for load transcripts should be captured' );

		$debug_log = reset( $debug_logs );
		$this->assertArrayHasKey( 'user_id', $debug_log['context'], 'Debug log should include user_id' );
	}
}
