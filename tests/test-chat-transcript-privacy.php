<?php
/**
 * Tests for chat transcript recording with privacy controls.
 *
 * @package WP_MCP_AI
 */

/**
 * Test chat transcript recording respects privacy settings.
 */
class Test_Chat_Transcript_Privacy extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test user.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		// Create test assistant.
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Set current user.
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Test transcript recording respects user opt-out.
	 */
	public function test_transcript_recording_respects_opt_out() {
		// Opt out user.
		update_user_meta( $this->user_id, WP_MCP_AI_Privacy_Controls::META_OPT_OUT_TRANSCRIPTS, '1' );

		// Create mock request.
		$request = new WP_REST_Request( 'POST', '/wp-json/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'messages', array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		) );

		// Prepare test data.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);
		$options  = array();
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Test response',
					),
				),
			),
		);
		$context  = array(
			'session_key' => 'test-session',
		);

		// Use reflection to test protected method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Chat_Transcript_Recorder' );
		$method     = $reflection->getMethod( 'should_record' );
		$method->setAccessible( true );

		// Test that recording should be disabled due to opt-out.
		$result = $method->invokeArgs( null, array(
			$this->assistant_id,
			$messages,
			$options,
			$response,
			$request,
			$context,
		) );

		$this->assertFalse( $result, 'Transcript should not be recorded when user has opted out' );
	}

	/**
	 * Test transcript recording allowed when user has not opted out.
	 */
	public function test_transcript_recording_allowed_when_not_opted_out() {
		// Ensure user is not opted out (default state).
		delete_user_meta( $this->user_id, WP_MCP_AI_Privacy_Controls::META_OPT_OUT_TRANSCRIPTS );

		// Create mock request.
		$request = new WP_REST_Request( 'POST', '/wp-json/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'save_transcript', true );

		// Prepare test data.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);
		$options  = array();
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Test response',
					),
				),
			),
		);
		$context  = array(
			'session_key' => 'test-session',
		);

		// Use reflection to test protected method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Chat_Transcript_Recorder' );
		$method     = $reflection->getMethod( 'should_record' );
		$method->setAccessible( true );

		// Test that recording should be allowed.
		$result = $method->invokeArgs( null, array(
			$this->assistant_id,
			$messages,
			$options,
			$response,
			$request,
			$context,
		) );

		$this->assertTrue( $result, 'Transcript should be recorded when user has not opted out' );
	}

	/**
	 * Test explicit save_transcript parameter overrides default but not opt-out.
	 */
	public function test_explicit_save_transcript_parameter() {
		// Opt out user.
		update_user_meta( $this->user_id, WP_MCP_AI_Privacy_Controls::META_OPT_OUT_TRANSCRIPTS, '1' );

		// Create mock request with explicit save_transcript=true.
		$request = new WP_REST_Request( 'POST', '/wp-json/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'save_transcript', true );

		// Prepare test data.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);
		$options  = array();
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Test response',
					),
				),
			),
		);
		$context  = array(
			'session_key' => 'test-session',
		);

		// Use reflection to test protected method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Chat_Transcript_Recorder' );
		$method     = $reflection->getMethod( 'should_record' );
		$method->setAccessible( true );

		// Even with explicit save_transcript=true, user opt-out should take precedence.
		$result = $method->invokeArgs( null, array(
			$this->assistant_id,
			$messages,
			$options,
			$response,
			$request,
			$context,
		) );

		$this->assertFalse( $result, 'User opt-out should take precedence over explicit save_transcript parameter' );
	}

	/**
	 * Test save_transcript=false prevents recording even without opt-out.
	 */
	public function test_save_transcript_false_prevents_recording() {
		// Ensure user is not opted out.
		delete_user_meta( $this->user_id, WP_MCP_AI_Privacy_Controls::META_OPT_OUT_TRANSCRIPTS );

		// Create mock request with explicit save_transcript=false.
		$request = new WP_REST_Request( 'POST', '/wp-json/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'save_transcript', false );

		// Prepare test data.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);
		$options  = array();
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Test response',
					),
				),
			),
		);
		$context  = array(
			'session_key' => 'test-session',
		);

		// Use reflection to test protected method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Chat_Transcript_Recorder' );
		$method     = $reflection->getMethod( 'should_record' );
		$method->setAccessible( true );

		// Test that recording should not occur.
		$result = $method->invokeArgs( null, array(
			$this->assistant_id,
			$messages,
			$options,
			$response,
			$request,
			$context,
		) );

		$this->assertFalse( $result, 'save_transcript=false should prevent recording' );
	}

	/**
	 * Test filter can override privacy settings.
	 */
	public function test_filter_can_override_privacy_settings() {
		// Opt out user.
		update_user_meta( $this->user_id, WP_MCP_AI_Privacy_Controls::META_OPT_OUT_TRANSCRIPTS, '1' );

		// Add filter to force recording.
		add_filter( 'wp_mcp_ai_save_chat_transcript', '__return_true' );

		// Create mock request.
		$request = new WP_REST_Request( 'POST', '/wp-json/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );

		// Prepare test data.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);
		$options  = array();
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Test response',
					),
				),
			),
		);
		$context  = array(
			'session_key' => 'test-session',
		);

		// Use reflection to test protected method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Chat_Transcript_Recorder' );
		$method     = $reflection->getMethod( 'should_record' );
		$method->setAccessible( true );

		// Filter should override opt-out.
		$result = $method->invokeArgs( null, array(
			$this->assistant_id,
			$messages,
			$options,
			$response,
			$request,
			$context,
		) );

		$this->assertTrue( $result, 'Filter should be able to override privacy settings when necessary' );

		// Clean up filter.
		remove_filter( 'wp_mcp_ai_save_chat_transcript', '__return_true' );
	}

	/**
	 * Test non-logged-in user (guest) recording behavior.
	 */
	public function test_guest_user_recording() {
		// Log out.
		wp_set_current_user( 0 );

		// Create mock request.
		$request = new WP_REST_Request( 'POST', '/wp-json/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'save_transcript', true );

		// Prepare test data.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);
		$options  = array();
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Test response',
					),
				),
			),
		);
		$context  = array(
			'session_key' => 'test-session',
		);

		// Use reflection to test protected method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Chat_Transcript_Recorder' );
		$method     = $reflection->getMethod( 'should_record' );
		$method->setAccessible( true );

		// Guest users have no opt-out setting, so should follow save_transcript parameter.
		$result = $method->invokeArgs( null, array(
			$this->assistant_id,
			$messages,
			$options,
			$response,
			$request,
			$context,
		) );

		$this->assertTrue( $result, 'Guest users should be able to save transcripts when save_transcript=true' );

		// Restore user.
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
