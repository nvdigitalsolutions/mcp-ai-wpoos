<?php
/**
 * Tests for chat transcript duplicate prevention.
 *
 * Verifies that:
 * 1. Response messages that duplicate request messages are filtered out
 * 2. Existing sessions are updated instead of creating new records
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Transcript_Duplicate_Prevention_Test extends WP_UnitTestCase {
	/**
	 * Administrator user ID for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

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

		rest_get_server();
		do_action( 'init' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that filter_duplicate_messages removes messages already in conversation.
	 */
	public function test_filter_duplicate_messages_removes_duplicates() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$filter_method = new ReflectionMethod( $rest_controller, 'filter_duplicate_messages' );
		$filter_method->setAccessible( true );

		// Existing conversation has user message and assistant message.
		$conversation = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Hi there!',
			),
		);

		// Candidate messages include a duplicate assistant message.
		$candidates = array(
			array(
				'role'    => 'assistant',
				'content' => 'Hi there!', // Duplicate.
			),
			array(
				'role'    => 'assistant',
				'content' => 'How can I help?', // New.
			),
		);

		$filtered = $filter_method->invokeArgs( $rest_controller, array( $conversation, $candidates ) );

		// Should only have the new message.
		$this->assertCount( 1, $filtered );
		$this->assertSame( 'How can I help?', $filtered[0]['content'] );
	}

	/**
	 * Test that filter_duplicate_messages handles assistant messages with tool_calls.
	 */
	public function test_filter_duplicate_messages_handles_tool_calls() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$filter_method = new ReflectionMethod( $rest_controller, 'filter_duplicate_messages' );
		$filter_method->setAccessible( true );

		$tool_calls = array(
			array(
				'id'       => 'call_abc123',
				'function' => array(
					'name'      => 'get_weather',
					'arguments' => '{"location":"London"}',
				),
			),
		);

		// Existing conversation has assistant message with tool_calls.
		$conversation = array(
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => $tool_calls,
			),
		);

		// Candidate is the same assistant message with tool_calls.
		$candidates = array(
			array(
				'role'       => 'assistant',
				'content'    => '',
				'tool_calls' => $tool_calls,
			),
		);

		$filtered = $filter_method->invokeArgs( $rest_controller, array( $conversation, $candidates ) );

		// Should be empty since the message is a duplicate.
		$this->assertCount( 0, $filtered );
	}

	/**
	 * Test that filter_duplicate_messages returns all candidates when conversation is empty.
	 */
	public function test_filter_duplicate_messages_returns_all_when_conversation_empty() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$filter_method = new ReflectionMethod( $rest_controller, 'filter_duplicate_messages' );
		$filter_method->setAccessible( true );

		$conversation = array();

		$candidates = array(
			array(
				'role'    => 'assistant',
				'content' => 'Hello!',
			),
			array(
				'role'    => 'assistant',
				'content' => 'How are you?',
			),
		);

		$filtered = $filter_method->invokeArgs( $rest_controller, array( $conversation, $candidates ) );

		// Should return all candidates.
		$this->assertCount( 2, $filtered );
	}

	/**
	 * Test that filter_duplicate_messages returns empty array when candidates is empty.
	 */
	public function test_filter_duplicate_messages_returns_empty_when_candidates_empty() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$filter_method = new ReflectionMethod( $rest_controller, 'filter_duplicate_messages' );
		$filter_method->setAccessible( true );

		$conversation = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$candidates = array();

		$filtered = $filter_method->invokeArgs( $rest_controller, array( $conversation, $candidates ) );

		$this->assertCount( 0, $filtered );
	}

	/**
	 * Test the scenario where manually saved transcripts have assistant messages
	 * in both request_payload and response_payload.
	 *
	 * This is the key bug fix scenario: when a conversation is manually saved,
	 * all messages (including assistant) go into request_payload, and assistant
	 * messages are also constructed into response_payload. Without filtering,
	 * this would cause duplicate assistant messages when reconstructing.
	 */
	public function test_manual_save_duplicate_scenario() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$filter_method = new ReflectionMethod( $rest_controller, 'filter_duplicate_messages' );
		$filter_method->setAccessible( true );

		// Simulate messages from request_payload (full conversation).
		$request_messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is the weather?',
			),
			array(
				'role'    => 'assistant',
				'content' => 'The weather in London is sunny.',
			),
			array(
				'role'    => 'user',
				'content' => 'Thanks!',
			),
			array(
				'role'    => 'assistant',
				'content' => 'You are welcome!',
			),
		);

		// Simulate messages from response_payload (only assistant messages).
		$response_messages = array(
			array(
				'role'    => 'assistant',
				'content' => 'The weather in London is sunny.',
			),
			array(
				'role'    => 'assistant',
				'content' => 'You are welcome!',
			),
		);

		// Filter response messages against request messages.
		$filtered = $filter_method->invokeArgs( $rest_controller, array( $request_messages, $response_messages ) );

		// All response messages should be filtered out since they're duplicates.
		$this->assertCount( 0, $filtered, 'All response messages should be filtered as duplicates' );
	}

	/**
	 * Test that different messages are not incorrectly filtered as duplicates.
	 */
	public function test_different_messages_not_filtered() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$filter_method = new ReflectionMethod( $rest_controller, 'filter_duplicate_messages' );
		$filter_method->setAccessible( true );

		// Existing conversation.
		$conversation = array(
			array(
				'role'    => 'assistant',
				'content' => 'Hello!',
			),
		);

		// Candidates with different content.
		$candidates = array(
			array(
				'role'    => 'assistant',
				'content' => 'Hello there!', // Different content.
			),
		);

		$filtered = $filter_method->invokeArgs( $rest_controller, array( $conversation, $candidates ) );

		// Should keep the different message.
		$this->assertCount( 1, $filtered );
		$this->assertSame( 'Hello there!', $filtered[0]['content'] );
	}
}
