<?php
/**
 * Test Cloudflare tool_calls response parsing and validation.
 *
 * @package WP_MCP_AI
 */

class Test_Cloudflare_Tool_Calls_Validation extends WP_UnitTestCase {

	/**
	 * Cloudflare client instance.
	 */
	private $client;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cloudflare-client.php';
		}

		$this->client = new WP_MCP_AI_Cloudflare_Client();
	}

	/**
	 * Test that valid tool_calls are preserved.
	 */
	public function test_valid_tool_calls_preserved() {
		$decoded = array(
			'success' => true,
			'result'  => array(
				'response'   => 'I will search for that information.',
				'tool_calls' => array(
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'web_search',
							'arguments' => '{"query":"Cloudflare Workers AI"}',
						),
					),
				),
			),
		);

		$reflection = new ReflectionClass( $this->client );
		$method = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $decoded, '@cf/meta/llama-3.1-8b-instruct' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'choices', $result );
		$this->assertArrayHasKey( 'message', $result['choices'][0] );
		$this->assertArrayHasKey( 'tool_calls', $result['choices'][0]['message'] );
		$this->assertCount( 1, $result['choices'][0]['message']['tool_calls'] );
		$this->assertEquals( 'web_search', $result['choices'][0]['message']['tool_calls'][0]['function']['name'] );
		$this->assertEquals( 'tool_calls', $result['choices'][0]['finish_reason'] );
	}

	/**
	 * Test that malformed tool_calls are filtered out.
	 */
	public function test_malformed_tool_calls_filtered() {
		$decoded = array(
			'success' => true,
			'result'  => array(
				'response'   => 'Normal response',
				'tool_calls' => array(
					// Missing function.name.
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'arguments' => '{}',
						),
					),
					// Missing function entirely.
					array(
						'id'   => 'call_456',
						'type' => 'function',
					),
				),
			),
		);

		$reflection = new ReflectionClass( $this->client );
		$method = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $decoded, '@cf/meta/llama-3.1-8b-instruct' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'choices', $result );
		$this->assertArrayHasKey( 'message', $result['choices'][0] );
		$this->assertArrayNotHasKey( 'tool_calls', $result['choices'][0]['message'], 'Malformed tool_calls should be filtered out' );
		$this->assertEquals( 'stop', $result['choices'][0]['finish_reason'], 'Should have stop finish_reason when no valid tool_calls' );
	}

	/**
	 * Test that empty tool_calls array results in no tool_calls in message.
	 */
	public function test_empty_tool_calls_array_filtered() {
		$decoded = array(
			'success' => true,
			'result'  => array(
				'response'   => 'Normal response',
				'tool_calls' => array(), // Empty array.
			),
		);

		$reflection = new ReflectionClass( $this->client );
		$method = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $decoded, '@cf/meta/llama-3.1-8b-instruct' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'choices', $result );
		$this->assertArrayHasKey( 'message', $result['choices'][0] );
		$this->assertArrayNotHasKey( 'tool_calls', $result['choices'][0]['message'], 'Empty tool_calls array should not be added to message' );
		$this->assertEquals( 'stop', $result['choices'][0]['finish_reason'] );
	}

	/**
	 * Test mix of valid and invalid tool_calls.
	 */
	public function test_mixed_valid_invalid_tool_calls() {
		$decoded = array(
			'success' => true,
			'result'  => array(
				'response'   => 'Processing multiple operations',
				'tool_calls' => array(
					// Valid tool call.
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'name'      => 'valid_tool',
							'arguments' => '{"param":"value"}',
						),
					),
					// Invalid - missing name.
					array(
						'id'       => 'call_456',
						'type'     => 'function',
						'function' => array(
							'arguments' => '{}',
						),
					),
					// Valid tool call.
					array(
						'id'       => 'call_789',
						'type'     => 'function',
						'function' => array(
							'name'      => 'another_valid_tool',
							'arguments' => '{}',
						),
					),
				),
			),
		);

		$reflection = new ReflectionClass( $this->client );
		$method = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $decoded, '@cf/meta/llama-3.1-8b-instruct' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'tool_calls', $result['choices'][0]['message'] );
		$this->assertCount( 2, $result['choices'][0]['message']['tool_calls'], 'Should only include 2 valid tool calls' );

		$tool_names = array_column( array_column( $result['choices'][0]['message']['tool_calls'], 'function' ), 'name' );
		$this->assertContains( 'valid_tool', $tool_names );
		$this->assertContains( 'another_valid_tool', $tool_names );
		$this->assertEquals( 'tool_calls', $result['choices'][0]['finish_reason'] );
	}

	/**
	 * Test response without tool_calls field.
	 */
	public function test_response_without_tool_calls_field() {
		$decoded = array(
			'success' => true,
			'result'  => array(
				'response' => 'Just a normal response without tools',
			),
		);

		$reflection = new ReflectionClass( $this->client );
		$method = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $decoded, '@cf/meta/llama-3.1-8b-instruct' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'choices', $result );
		$this->assertArrayHasKey( 'message', $result['choices'][0] );
		$this->assertArrayNotHasKey( 'tool_calls', $result['choices'][0]['message'] );
		$this->assertEquals( 'stop', $result['choices'][0]['finish_reason'] );
		$this->assertEquals( 'Just a normal response without tools', $result['choices'][0]['message']['content'] );
	}

	/**
	 * Test tool_calls with empty function name.
	 */
	public function test_tool_call_with_empty_function_name() {
		$decoded = array(
			'success' => true,
			'result'  => array(
				'response'   => 'Response',
				'tool_calls' => array(
					array(
						'id'       => 'call_123',
						'type'     => 'function',
						'function' => array(
							'name'      => '', // Empty name.
							'arguments' => '{}',
						),
					),
				),
			),
		);

		$reflection = new ReflectionClass( $this->client );
		$method = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $decoded, '@cf/meta/llama-3.1-8b-instruct' );

		$this->assertArrayNotHasKey( 'tool_calls', $result['choices'][0]['message'], 'Tool call with empty name should be filtered' );
		$this->assertEquals( 'stop', $result['choices'][0]['finish_reason'] );
	}

	/**
	 * Test logging of malformed tool_calls.
	 */
	public function test_logging_of_malformed_tool_calls() {
		// Clear existing logs.
		delete_option( 'wp_mcp_ai_recent_activity' );

		$decoded = array(
			'success' => true,
			'result'  => array(
				'response'   => 'Response',
				'tool_calls' => array(
					array(
						'id'   => 'call_bad',
						'type' => 'function',
						// Missing function field entirely.
					),
				),
			),
		);

		$reflection = new ReflectionClass( $this->client );
		$method = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $decoded, '@cf/meta/llama-3.1-8b-instruct' );

		// Check logs.
		$logs = get_option( 'wp_mcp_ai_recent_activity', array() );
		$malformed_logs = array_filter( $logs, function( $log ) {
			return isset( $log['event'] ) && 'cloudflare_invalid_tool_call' === $log['event'];
		} );

		$this->assertNotEmpty( $malformed_logs, 'Should log when malformed tool_call is detected' );
	}
}
