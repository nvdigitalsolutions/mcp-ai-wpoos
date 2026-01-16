<?php
/**
 * Tests for Cloudflare response_format parameter support (JSON Mode).
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Cloudflare response_format functionality.
 */
class Test_Cloudflare_Response_Format extends WP_UnitTestCase {

	/**
	 * Cloudflare client instance.
	 *
	 * @var WP_MCP_AI_Cloudflare_Client
	 */
	private $client;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->client = new WP_MCP_AI_Cloudflare_Client();
	}

	/**
	 * Test that response_format is added to payload when provided.
	 */
	public function test_response_format_added_to_payload() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Tell me about India',
			),
		);

		$response_format = array(
			'type' => 'json_object',
		);

		$options = array(
			'response_format' => $response_format,
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		$this->assertArrayHasKey( 'response_format', $payload, 'response_format should be in payload' );
		$this->assertSame( $response_format, $payload['response_format'], 'response_format should match input' );
	}

	/**
	 * Test that response_format with json_schema is properly passed.
	 */
	public function test_response_format_with_json_schema() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Extract country information',
			),
		);

		$response_format = array(
			'type'        => 'json_schema',
			'json_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'name'      => array( 'type' => 'string' ),
					'capital'   => array( 'type' => 'string' ),
					'languages' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'required'   => array( 'name', 'capital' ),
			),
		);

		$options = array(
			'response_format' => $response_format,
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		$this->assertArrayHasKey( 'response_format', $payload, 'response_format should be in payload' );
		$this->assertArrayHasKey( 'json_schema', $payload['response_format'], 'json_schema should be in response_format' );
		$this->assertSame( 'json_schema', $payload['response_format']['type'], 'Type should be json_schema' );
		$this->assertArrayHasKey( 'properties', $payload['response_format']['json_schema'], 'Schema should have properties' );
	}

	/**
	 * Test that response_format is not added when not provided.
	 */
	public function test_response_format_not_added_when_absent() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Normal request',
			),
		);

		$options = array(); // No response_format.

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		$this->assertArrayNotHasKey( 'response_format', $payload, 'response_format should not be in payload when not provided' );
	}

	/**
	 * Test that response_format is ignored when not an array.
	 */
	public function test_response_format_ignored_when_invalid_type() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test request',
			),
		);

		$options = array(
			'response_format' => 'invalid_string', // Not an array.
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		$this->assertArrayNotHasKey( 'response_format', $payload, 'response_format should not be added when not an array' );
	}

	/**
	 * Test that response_format works alongside tools.
	 */
	public function test_response_format_with_tools() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Get structured data',
			),
		);

		$options = array(
			'response_format' => array(
				'type' => 'json_object',
			),
			'tools'           => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'fetch_data',
						'description' => 'Fetch data',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(),
						),
					),
				),
			),
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		// Both should be present.
		$this->assertArrayHasKey( 'response_format', $payload, 'response_format should be in payload' );
		$this->assertArrayHasKey( 'tools', $payload, 'tools should be in payload' );
	}

	/**
	 * Test that response_format works with tool_choice.
	 */
	public function test_response_format_with_tool_choice() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Combined test',
			),
		);

		$options = array(
			'response_format' => array(
				'type' => 'json_object',
			),
			'tools'           => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'test_tool',
						'description' => 'Test',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(),
						),
					),
				),
			),
			'tool_choice'     => 'required',
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		// All three should be present.
		$this->assertArrayHasKey( 'response_format', $payload, 'response_format should be in payload' );
		$this->assertArrayHasKey( 'tools', $payload, 'tools should be in payload' );
		$this->assertArrayHasKey( 'tool_choice', $payload, 'tool_choice should be in payload' );
	}

	/**
	 * Test empty response_format array is not added.
	 */
	public function test_empty_response_format_not_added() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test',
			),
		);

		$options = array(
			'response_format' => array(), // Empty array.
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		// Empty array is still technically an array, so it should be added.
		$this->assertArrayHasKey( 'response_format', $payload, 'Empty response_format array should still be added' );
		$this->assertSame( array(), $payload['response_format'], 'response_format should be empty array' );
	}

	/**
	 * Test that response_format is NOT auto-enabled when tools are present.
	 * Auto-JSON has been removed because not all Cloudflare models support it.
	 */
	public function test_no_auto_json_mode_with_tools() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Use a tool',
			),
		);

		$options = array(
			'tools' => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'test_tool',
						'description' => 'Test',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(),
						),
					),
				),
			),
			// No response_format explicitly set.
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		// Auto-JSON should NOT be enabled (removed in fix for unsupported models).
		$this->assertArrayNotHasKey( 'response_format', $payload, 'Auto-JSON should not be enabled automatically' );
	}

	/**
	 * Test that response_format can still be explicitly set when tools are present.
	 */
	public function test_explicit_response_format_with_tools() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Use a tool',
			),
		);

		$options = array(
			'tools'            => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'test_tool',
						'description' => 'Test',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(),
						),
					),
				),
			),
			'response_format' => array( 'type' => 'json_object' ), // Explicitly enable.
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		// Explicit response_format should be respected.
		$this->assertArrayHasKey( 'response_format', $payload, 'Explicit response_format should be included' );
		$this->assertSame( array( 'type' => 'json_object' ), $payload['response_format'], 'Should use explicitly set response_format' );
	}

	/**
	 * Test that explicit response_format overrides auto-JSON.
	 */
	public function test_explicit_response_format_overrides_auto_json() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Use a tool',
			),
		);

		$custom_format = array(
			'type'        => 'json_schema',
			'json_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'result' => array( 'type' => 'string' ),
				),
			),
		);

		$options = array(
			'tools'           => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'test_tool',
						'description' => 'Test',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(),
						),
					),
				),
			),
			'response_format' => $custom_format, // Explicit format.
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		// Should use explicit format, not auto-JSON.
		$this->assertArrayHasKey( 'response_format', $payload, 'response_format should be present' );
		$this->assertSame( $custom_format, $payload['response_format'], 'Should use explicit format, not auto-JSON' );
	}
}
