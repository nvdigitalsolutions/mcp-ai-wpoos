<?php
/**
 * Tests for chat service handling of pending errors (HTTP 202 responses).
 *
 * @package WP_MCP_AI
 */

/**
 * Test that pending errors (e.g., from web search HTTP 202) are handled gracefully.
 */
class WP_MCP_AI_Chat_Service_Pending_Errors_Test extends WP_UnitTestCase {

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Chat_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-chat-service.php';
		}

		// Create a fresh registry for each test.
		$this->registry = new WP_MCP_AI_Tool_Registry();
	}

	/**
	 * Test that pending errors are converted to informational results for the LLM.
	 *
	 * When a tool returns a WP_Error with is_pending=true, the chat service should
	 * NOT send it as a hard error to the LLM. Instead, it should be converted to
	 * an informational message that tells the LLM to use alternative sources.
	 */
	public function test_pending_error_converted_to_informational_result() {
		// Create a mock tool that returns a pending error (like web search with HTTP 202).
		$mock_tool = new class() implements WP_MCP_AI_Tool_Interface {
			public function get_slug() {
				return 'mock_pending_tool';
			}

			public function get_name() {
				return 'Mock Pending Tool';
			}

			public function get_description() {
				return 'A tool that returns pending status for testing';
			}

			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
					'required'   => array(),
				);
			}

			public function execute( array $arguments = array(), array $context = array() ) {
				// Return a pending error like web search does with HTTP 202.
				return new WP_Error(
					'wp_mcp_ai_tool_pending',
					'The service is temporarily processing your request.',
					array(
						'status'      => 202,
						'is_pending'  => true,
						'should_wait' => false,
					)
				);
			}
		};

		// Register the mock tool.
		$this->registry->register_tool( $mock_tool );

		// Use reflection to access private method for testing.
		$service    = new WP_MCP_AI_Chat_Service( $this->registry );
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'execute_tool_calls' );
		$method->setAccessible( true );

		// Prepare tool calls array.
		$tool_calls = array(
			array(
				'id'       => 'call_123',
				'function' => array(
					'name'      => 'mock_pending_tool',
					'arguments' => '{}',
				),
			),
		);

		// Execute tool calls.
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$results = $method->invoke( $service, $tool_calls, 1, array(), 0, 5 );

		// Verify we got a result.
		$this->assertIsArray( $results );
		$this->assertCount( 1, $results );

		// Verify the result structure.
		$result = $results[0];
		$this->assertArrayHasKey( 'role', $result );
		$this->assertArrayHasKey( 'tool_call_id', $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'content', $result );

		$this->assertSame( 'tool', $result['role'] );
		$this->assertSame( 'call_123', $result['tool_call_id'] );
		$this->assertSame( 'mock_pending_tool', $result['name'] );

		// Parse the content.
		$content = json_decode( $result['content'], true );
		$this->assertIsArray( $content );

		// Should NOT have error_code (this is key - it's not an error for the LLM).
		$this->assertArrayNotHasKey( 'error_code', $content );

		// Should have status=unavailable and instructive message.
		$this->assertArrayHasKey( 'status', $content );
		$this->assertSame( 'unavailable', $content['status'] );

		$this->assertArrayHasKey( 'message', $content );
		$this->assertStringContainsString( 'temporarily unavailable', $content['message'] );
		$this->assertStringContainsString( 'general knowledge', $content['message'] );

		// Should include the original error message as a note.
		$this->assertArrayHasKey( 'note', $content );
		$this->assertStringContainsString( 'temporarily processing', $content['note'] );
	}

	/**
	 * Test that regular errors are still sent as errors to the LLM.
	 *
	 * Only pending errors (is_pending=true) should be converted. Regular errors
	 * should continue to be sent with error_code and error_message.
	 */
	public function test_regular_error_still_sent_as_error() {
		// Create a mock tool that returns a regular error.
		$mock_tool = new class() implements WP_MCP_AI_Tool_Interface {
			public function get_slug() {
				return 'mock_error_tool';
			}

			public function get_name() {
				return 'Mock Error Tool';
			}

			public function get_description() {
				return 'A tool that returns a regular error for testing';
			}

			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
					'required'   => array(),
				);
			}

			public function execute( array $arguments = array(), array $context = array() ) {
				// Return a regular error (not pending).
				return new WP_Error(
					'wp_mcp_ai_tool_failed',
					'The tool failed to execute.',
					array( 'some_data' => 'value' )
				);
			}
		};

		// Register the mock tool.
		$this->registry->register_tool( $mock_tool );

		// Use reflection to access private method for testing.
		$service    = new WP_MCP_AI_Chat_Service( $this->registry );
		$reflection = new ReflectionClass( $service );
		$method     = $reflection->getMethod( 'execute_tool_calls' );
		$method->setAccessible( true );

		// Prepare tool calls array.
		$tool_calls = array(
			array(
				'id'       => 'call_456',
				'function' => array(
					'name'      => 'mock_error_tool',
					'arguments' => '{}',
				),
			),
		);

		// Execute tool calls.
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$results = $method->invoke( $service, $tool_calls, 1, array(), 0, 5 );

		// Verify we got a result.
		$this->assertIsArray( $results );
		$this->assertCount( 1, $results );

		// Parse the content.
		$result  = $results[0];
		$content = json_decode( $result['content'], true );
		$this->assertIsArray( $content );

		// SHOULD have error_code and error_message (this is a real error).
		$this->assertArrayHasKey( 'error_code', $content );
		$this->assertArrayHasKey( 'error_message', $content );
		$this->assertSame( 'wp_mcp_ai_tool_failed', $content['error_code'] );
		$this->assertSame( 'The tool failed to execute.', $content['error_message'] );

		// Should also have error_data.
		$this->assertArrayHasKey( 'error_data', $content );
		$this->assertIsArray( $content['error_data'] );
		$this->assertArrayHasKey( 'some_data', $content['error_data'] );
	}
}
