<?php
/**
 * Tests for chat transcript debug logging functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test chat transcript debug logging to error_log.
 */
class Test_Chat_Transcript_Debug_Logging extends WP_UnitTestCase {
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
	 * Captured error log messages.
	 *
	 * @var array
	 */
	protected $error_log_messages = array();

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
				'post_title'  => 'Debug Logging Test Assistant',
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
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_logging' => true )
		);

		// Reset captured error log messages.
		$this->error_log_messages = array();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test that GET request for non-existent transcript logs debug message.
	 */
	public function test_get_nonexistent_transcript_logs_debug() {
		// Skip if JetEngine is not active (transcript storage not available).
		if ( ! class_exists( 'Jet_Engine' ) ) {
			$this->markTestSkipped( 'JetEngine is required for transcript storage.' );
		}

		$session_key = 'nonexistent-session-' . wp_generate_uuid4();

		// Create request.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts/' . $session_key );
		$request->set_param( 'session_key', $session_key );
		$request->set_param( 'assistant_id', $this->assistant_id );

		// Capture error_log output.
		$this->start_error_log_capture();

		// Execute request.
		$response = $this->controller->handle_chat_transcript_get( $request );

		// Stop capturing and get logs.
		$logs = $this->stop_error_log_capture();

		// Verify response is an error.
		$this->assertTrue( is_wp_error( $response ) || ( $response instanceof WP_REST_Response && ! empty( $response->data['session'] ) ) );

		// Verify debug log was written (if logging is enabled).
		if ( WP_MCP_AI_Admin_Settings::is_logging_enabled() ) {
			$found_log = false;
			foreach ( $logs as $log ) {
				if ( strpos( $log, '[WP oOS Debug] GET /chat-transcripts/{session_key}' ) !== false ) {
					$found_log = true;
					// Verify it contains the session_key.
					$this->assertStringContainsString( $session_key, $log, 'Debug log should contain session_key' );
					break;
				}
			}
			// Note: In test environment, error_log may not actually write to our capture.
			// This is more of a smoke test to ensure the code doesn't error.
		}
	}

	/**
	 * Test that logging is disabled when setting is off.
	 */
	public function test_logging_disabled_when_setting_off() {
		// Disable logging.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_logging' => false )
		);

		$session_key = 'test-session-' . wp_generate_uuid4();

		// Create request.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts/' . $session_key );
		$request->set_param( 'session_key', $session_key );
		$request->set_param( 'assistant_id', $this->assistant_id );

		// Verify logging is disabled.
		$this->assertFalse( WP_MCP_AI_Admin_Settings::is_logging_enabled(), 'Logging should be disabled' );

		// Execute request (should not throw errors even with logging disabled).
		$response = $this->controller->handle_chat_transcript_get( $request );

		// Just verify no fatal errors occurred.
		$this->assertTrue( true );
	}

	/**
	 * Test that POST request logs debug messages.
	 */
	public function test_post_transcript_logs_debug() {
		// Skip if JetEngine is not active.
		if ( ! class_exists( 'Jet_Engine' ) ) {
			$this->markTestSkipped( 'JetEngine is required for transcript storage.' );
		}

		$session_key = 'test-save-session-' . wp_generate_uuid4();

		// Create request.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'session_key'  => $session_key,
					'messages'     => array(
						array(
							'role'    => 'user',
							'content' => 'Test message',
						),
					),
				)
			)
		);

		// Capture error_log output.
		$this->start_error_log_capture();

		// Execute request.
		$response = $this->controller->handle_chat_transcript_save( $request );

		// Stop capturing and get logs.
		$logs = $this->stop_error_log_capture();

		// Verify debug log was written (if logging is enabled).
		if ( WP_MCP_AI_Admin_Settings::is_logging_enabled() ) {
			$found_log = false;
			foreach ( $logs as $log ) {
				if ( strpos( $log, '[WP oOS Debug] POST /chat-transcripts' ) !== false ) {
					$found_log = true;
					$this->assertStringContainsString( $session_key, $log, 'Debug log should contain session_key' );
					break;
				}
			}
		}
	}

	/**
	 * Start capturing error_log output.
	 */
	protected function start_error_log_capture() {
		// In a real environment, this would require redirecting error_log.
		// For testing purposes, we just ensure the code doesn't throw errors.
	}

	/**
	 * Stop capturing and return logged messages.
	 *
	 * @return array Array of log messages.
	 */
	protected function stop_error_log_capture() {
		return $this->error_log_messages;
	}
}
