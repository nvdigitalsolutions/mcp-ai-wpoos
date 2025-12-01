<?php
/**
 * Tests for the explicit transcript save endpoint (POST /chat-transcripts).
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Chat_Transcript_Save_Endpoint_Test extends WP_UnitTestCase {
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
				'post_title'  => 'Transcript Save Test Assistant',
			)
		);

		// Set up assistant configuration.
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_provider', 'openai' );

		rest_get_server();
		do_action( 'init' );
	}

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
					$this->records[] = $record;
					return true;
				}
			};
		}

		return $this->transcript_handler;
	}

	/**
	 * Test that the POST /chat-transcripts endpoint is registered.
	 */
	public function test_chat_transcripts_post_endpoint_is_registered() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/mcp-ai/v1/chat-transcripts', $routes, 'Chat transcripts endpoint should be registered' );

		$route_definition = $routes['/mcp-ai/v1/chat-transcripts'];
		$this->assertIsArray( $route_definition, 'Route definition should be an array' );

		// Check that POST method is registered.
		$has_post = false;
		foreach ( $route_definition as $handler ) {
			if ( isset( $handler['methods'] ) && in_array( 'POST', (array) $handler['methods'], true ) ) {
				$has_post = true;
				break;
			}
		}

		$this->assertTrue( $has_post, 'POST method should be registered for chat-transcripts endpoint' );
	}

	/**
	 * Test that the endpoint requires assistant_id parameter.
	 */
	public function test_save_endpoint_requires_assistant_id() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'session_key', 'test-session-123' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 400, $response->get_status(), 'Should return 400 when assistant_id is missing' );
		$this->assertArrayHasKey( 'code', $data, 'Error response should include code' );
		$this->assertEquals( 'wp_mcp_ai_transcripts_missing_assistant', $data['code'], 'Should return missing assistant error' );
	}

	/**
	 * Test that the endpoint requires session_key parameter.
	 */
	public function test_save_endpoint_requires_session_key() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 400, $response->get_status(), 'Should return 400 when session_key is missing' );
		$this->assertArrayHasKey( 'code', $data, 'Error response should include code' );
		$this->assertEquals( 'wp_mcp_ai_transcripts_missing_session', $data['code'], 'Should return missing session error' );
	}

	/**
	 * Test that the endpoint requires messages parameter.
	 */
	public function test_save_endpoint_requires_messages() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-session-123' );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 400, $response->get_status(), 'Should return 400 when messages are missing' );
		$this->assertArrayHasKey( 'code', $data, 'Error response should include code' );
		$this->assertEquals( 'wp_mcp_ai_transcripts_missing_messages', $data['code'], 'Should return missing messages error' );
	}

	/**
	 * Test successful transcript save.
	 */
	public function test_save_transcript_successfully() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-session-456' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello, assistant!',
				),
				array(
					'role'    => 'assistant',
					'content' => 'Hello! How can I help you?',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Should return 200 on successful save' );
		$this->assertArrayHasKey( 'success', $data, 'Response should include success flag' );
		$this->assertTrue( $data['success'], 'Success flag should be true' );
		$this->assertArrayHasKey( 'session_key', $data, 'Response should include session_key' );
		$this->assertEquals( 'test-session-456', $data['session_key'], 'Response should return the same session_key' );

		// Verify the transcript was passed to the handler.
		$this->assertNotNull( $this->transcript_handler, 'Transcript handler should be initialized' );
		$this->assertCount( 1, $this->transcript_handler->records, 'One record should have been saved' );

		$record = $this->transcript_handler->records[0];
		$this->assertEquals( 'test-session-456', $record['session_key'], 'Record should have correct session_key' );
		$this->assertEquals( $this->admin_id, $record['user_id'], 'Record should have correct user_id' );
		$this->assertEquals( (string) $this->assistant_id, $record['assistant_id'], 'Record should have correct assistant_id' );
	}

	/**
	 * Test that unauthenticated users cannot save transcripts.
	 */
	public function test_save_endpoint_requires_authentication() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-session-789' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 401, $response->get_status(), 'Should return 401 for unauthenticated users' );
	}

	/**
	 * Test that the endpoint validates assistant access.
	 */
	public function test_save_endpoint_validates_assistant_access() {
		// Create a draft assistant that the user shouldn't be able to save to.
		$draft_assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'Draft Assistant',
			)
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'assistant_id', $draft_assistant_id );
		$request->set_param( 'session_key', 'test-session-999' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);

		// Set up as a subscriber (non-admin) user.
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$response = rest_get_server()->dispatch( $request );

		$this->assertNotEquals( 200, $response->get_status(), 'Should not allow saving to inaccessible assistant' );
	}

	/**
	 * Test that the endpoint accepts assistant messages with null content (agentic flows).
	 */
	public function test_save_endpoint_accepts_null_content_for_assistant_messages() {
		add_filter( 'wp_mcp_ai_chat_transcript_handler', array( $this, 'provide_transcript_handler' ), 10 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-agentic-flow-123' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Generate an image of a cat',
				),
				array(
					'role'       => 'assistant',
					'content'    => null, // null content is valid when tool_calls are present.
					'tool_calls' => array(
						array(
							'id'       => 'call_abc123',
							'type'     => 'function',
							'function' => array(
								'name'      => 'generate_image',
								'arguments' => '{"prompt":"a cat"}',
							),
						),
					),
				),
				array(
					'role'         => 'tool',
					'tool_call_id' => 'call_abc123',
					'content'      => '{"image_url":"https://example.com/cat.png"}',
				),
				array(
					'role'    => 'assistant',
					'content' => 'Here is an image of a cat.',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Should accept assistant messages with null content (agentic flows)' );
		$this->assertArrayHasKey( 'success', $data, 'Response should include success flag' );
		$this->assertTrue( $data['success'], 'Success flag should be true' );

		// Verify the transcript was saved with the null content.
		$this->assertNotNull( $this->transcript_handler, 'Transcript handler should be initialized' );
		$this->assertCount( 1, $this->transcript_handler->records, 'One record should have been saved' );
	}
}
