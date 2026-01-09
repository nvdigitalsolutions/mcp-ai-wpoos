<?php
/**
 * Tests for Cloudflare message normalization
 *
 * @package WP_MCP_AI
 */

/**
 * Test Cloudflare message normalization functionality.
 */
class Test_Cloudflare_Message_Normalization extends WP_UnitTestCase {

	/**
	 * Cloudflare client instance for testing.
	 *
	 * @var WP_MCP_AI_Cloudflare_Client
	 */
	private $client;

	/**
	 * Set up test environment.
	 */
	public function setUp() {
		parent::setUp();

		// Load the Cloudflare client class.
		if ( ! class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cloudflare-client.php';
		}

		$this->client = new WP_MCP_AI_Cloudflare_Client();
	}

	/**
	 * Test that messages with string content are passed through unchanged.
	 */
	public function test_string_content_unchanged() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, how are you?',
			),
			array(
				'role'    => 'assistant',
				'content' => 'I am doing well, thank you!',
			),
		);

		$normalized = $this->invoke_normalize_messages( $messages );

		$this->assertCount( 2, $normalized );
		$this->assertEquals( 'user', $normalized[0]['role'] );
		$this->assertEquals( 'Hello, how are you?', $normalized[0]['content'] );
		$this->assertEquals( 'assistant', $normalized[1]['role'] );
		$this->assertEquals( 'I am doing well, thank you!', $normalized[1]['content'] );
	}

	/**
	 * Test that array content with text parts is converted to string.
	 */
	public function test_array_content_converted_to_string() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Hello',
					),
					array(
						'type' => 'text',
						'text' => 'World',
					),
				),
			),
		);

		$normalized = $this->invoke_normalize_messages( $messages );

		$this->assertCount( 1, $normalized );
		$this->assertEquals( 'user', $normalized[0]['role'] );
		$this->assertIsString( $normalized[0]['content'] );
		$this->assertEquals( "Hello\nWorld", $normalized[0]['content'] );
	}

	/**
	 * Test that array content with simple text property is converted.
	 */
	public function test_array_content_with_text_property() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array( 'text' => 'First part' ),
					array( 'text' => 'Second part' ),
				),
			),
		);

		$normalized = $this->invoke_normalize_messages( $messages );

		$this->assertCount( 1, $normalized );
		$this->assertIsString( $normalized[0]['content'] );
		$this->assertEquals( "First part\nSecond part", $normalized[0]['content'] );
	}

	/**
	 * Test that array content with simple strings is converted.
	 */
	public function test_array_content_with_simple_strings() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array( 'Hello', 'there', 'friend' ),
			),
		);

		$normalized = $this->invoke_normalize_messages( $messages );

		$this->assertCount( 1, $normalized );
		$this->assertIsString( $normalized[0]['content'] );
		$this->assertEquals( "Hello\nthere\nfriend", $normalized[0]['content'] );
	}

	/**
	 * Test that empty messages are filtered out (except assistant with tool_calls).
	 */
	public function test_empty_messages_filtered() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => '',
			),
			array(
				'role'    => 'user',
				'content' => 'Valid message',
			),
			array(
				'role'    => 'assistant',
				'content' => '',
			),
		);

		$normalized = $this->invoke_normalize_messages( $messages );

		// Empty user message should be filtered, empty assistant should remain.
		$this->assertCount( 2, $normalized );
		$this->assertEquals( 'user', $normalized[0]['role'] );
		$this->assertEquals( 'Valid message', $normalized[0]['content'] );
		$this->assertEquals( 'assistant', $normalized[1]['role'] );
	}

	/**
	 * Test that tool_calls are preserved in assistant messages.
	 */
	public function test_tool_calls_preserved() {
		$messages = array(
			array(
				'role'       => 'assistant',
				'content'    => 'I will search for that information.',
				'tool_calls' => array(
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'search',
							'arguments' => '{"query":"test"}',
						),
					),
				),
			),
		);

		$normalized = $this->invoke_normalize_messages( $messages );

		$this->assertCount( 1, $normalized );
		$this->assertEquals( 'assistant', $normalized[0]['role'] );
		$this->assertArrayHasKey( 'tool_calls', $normalized[0] );
		$this->assertCount( 1, $normalized[0]['tool_calls'] );
		$this->assertEquals( 'call_123', $normalized[0]['tool_calls'][0]['id'] );
	}

	/**
	 * Test that tool_call_id and name are preserved in tool messages.
	 */
	public function test_tool_message_properties_preserved() {
		$messages = array(
			array(
				'role'         => 'tool',
				'content'      => 'Search results: ...',
				'tool_call_id' => 'call_123',
				'name'         => 'search',
			),
		);

		$normalized = $this->invoke_normalize_messages( $messages );

		$this->assertCount( 1, $normalized );
		$this->assertEquals( 'tool', $normalized[0]['role'] );
		$this->assertArrayHasKey( 'tool_call_id', $normalized[0] );
		$this->assertEquals( 'call_123', $normalized[0]['tool_call_id'] );
		$this->assertArrayHasKey( 'name', $normalized[0] );
		$this->assertEquals( 'search', $normalized[0]['name'] );
	}

	/**
	 * Test mixed format: string and array content in same conversation.
	 */
	public function test_mixed_content_formats() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Simple string message',
			),
			array(
				'role'    => 'assistant',
				'content' => array(
					array( 'type' => 'text', 'text' => 'Array' ),
					array( 'type' => 'text', 'text' => 'format' ),
				),
			),
			array(
				'role'    => 'user',
				'content' => array( 'Another', 'array', 'format' ),
			),
		);

		$normalized = $this->invoke_normalize_messages( $messages );

		$this->assertCount( 3, $normalized );
		$this->assertEquals( 'Simple string message', $normalized[0]['content'] );
		$this->assertEquals( "Array\nformat", $normalized[1]['content'] );
		$this->assertEquals( "Another\narray\nformat", $normalized[2]['content'] );
	}

	/**
	 * Helper method to invoke the protected normalize_messages method.
	 *
	 * @param array $messages Messages to normalize.
	 * @return array Normalized messages.
	 */
	private function invoke_normalize_messages( array $messages ) {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalize_messages' );
		$method->setAccessible( true );

		return $method->invoke( $this->client, $messages );
	}
}
