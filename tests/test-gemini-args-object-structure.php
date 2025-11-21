<?php
/**
 * Tests for Gemini args object structure handling.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that Gemini function call args are always objects, never arrays.
 */
class Test_Gemini_Args_Object_Structure extends WP_UnitTestCase {
	/**
	 * Gemini client instance.
	 *
	 * @var WP_MCP_AI_Gemini_Client
	 */
	private $client;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';
		$this->client = new WP_MCP_AI_Gemini_Client();
	}

	/**
	 * Test that numeric arrays are converted to object structure.
	 */
	public function test_numeric_array_converted_to_object() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalise_tool_arguments' );
		$method->setAccessible( true );

		// Test with a simple numeric array.
		$args   = array( 'value1', 'value2', 'value3' );
		$result = $method->invoke( $this->client, $args );

		// Should be wrapped in 'items' to force object serialization.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertSame( $args, $result['items'] );

		// Verify it serializes as an object, not an array.
		$json = wp_json_encode( $result );
		$this->assertStringContainsString( '{"items":', $json );
		$this->assertStringNotContainsString( '[0,1,2]', $json );
	}

	/**
	 * Test that associative arrays are preserved.
	 */
	public function test_associative_array_preserved() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalise_tool_arguments' );
		$method->setAccessible( true );

		// Test with an associative array (typical tool arguments).
		$args = array(
			'hook'      => 'my_hook',
			'timestamp' => 1234567890,
			'schedule'  => 'hourly',
		);

		$result = $method->invoke( $this->client, $args );

		// Should be preserved as-is.
		$this->assertSame( $args, $result );
	}

	/**
	 * Test that nested numeric arrays in associative arrays are wrapped.
	 */
	public function test_nested_numeric_array_wrapped() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalise_tool_arguments' );
		$method->setAccessible( true );

		// Test with associative array containing numeric array (like cron args).
		$args = array(
			'hook'     => 'my_hook',
			'schedule' => 'hourly',
			'args'     => array( 'arg1', 'arg2', 'arg3' ),
		);

		$result = $method->invoke( $this->client, $args );

		// Top level should be associative.
		$this->assertArrayHasKey( 'hook', $result );
		$this->assertArrayHasKey( 'schedule', $result );
		$this->assertArrayHasKey( 'args', $result );

		// The 'args' field should be wrapped in 'items'.
		$this->assertIsArray( $result['args'] );
		$this->assertArrayHasKey( 'items', $result['args'] );
		$this->assertSame( array( 'arg1', 'arg2', 'arg3' ), $result['args']['items'] );

		// Verify JSON structure.
		$json = wp_json_encode( $result );
		$this->assertStringContainsString( '"args":{"items":', $json );
	}

	/**
	 * Test that empty arrays remain empty.
	 */
	public function test_empty_array_remains_empty() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalise_tool_arguments' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, array() );

		$this->assertSame( array(), $result );
	}

	/**
	 * Test that tool call conversion produces valid Gemini functionCall structure.
	 */
	public function test_tool_call_conversion_with_array_args() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'convert_tool_call_to_function_call' );
		$method->setAccessible( true );

		// Simulate a tool call for create_cron_job with array arguments.
		$tool_call = array(
			'id'   => 'call_123',
			'type' => 'function',
			'function' => array(
				'name'      => 'create_cron_job',
				'arguments' => wp_json_encode(
					array(
						'hook'      => 'test_hook',
						'timestamp' => 1234567890,
						'schedule'  => 'hourly',
						'args'      => array( 'param1', 'param2' ),
					)
				),
			),
		);

		$result = $method->invoke( $this->client, $tool_call );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'args', $result );
		$this->assertSame( 'create_cron_job', $result['name'] );

		// Verify the args structure.
		$this->assertIsArray( $result['args'] );
		$this->assertArrayHasKey( 'hook', $result['args'] );
		$this->assertArrayHasKey( 'args', $result['args'] );

		// The nested 'args' should be wrapped.
		$this->assertIsArray( $result['args']['args'] );
		$this->assertArrayHasKey( 'items', $result['args']['args'] );
		$this->assertSame( array( 'param1', 'param2' ), $result['args']['args']['items'] );

		// Verify JSON serialization.
		$json = wp_json_encode( $result );
		$this->assertStringContainsString( '"args":{"hook":', $json );
		$this->assertStringContainsString( '"args":{"items":', $json );
		// Should not contain a JSON array at the args level.
		$this->assertStringNotMatchesRegularExpression( '/"args":\[/', $json );
	}

	/**
	 * Test that deeply nested structures are handled correctly.
	 */
	public function test_deeply_nested_arrays() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalise_tool_arguments' );
		$method->setAccessible( true );

		$args = array(
			'level1' => array(
				'level2' => array(
					'numeric' => array( 1, 2, 3 ),
					'assoc'   => array( 'key' => 'value' ),
				),
			),
		);

		$result = $method->invoke( $this->client, $args );

		// Verify structure.
		$this->assertArrayHasKey( 'level1', $result );
		$this->assertArrayHasKey( 'level2', $result['level1'] );
		$this->assertArrayHasKey( 'numeric', $result['level1']['level2'] );
		$this->assertArrayHasKey( 'assoc', $result['level1']['level2'] );

		// Numeric array should be wrapped.
		$this->assertArrayHasKey( 'items', $result['level1']['level2']['numeric'] );
		$this->assertSame( array( 1, 2, 3 ), $result['level1']['level2']['numeric']['items'] );

		// Associative array should be preserved.
		$this->assertArrayHasKey( 'key', $result['level1']['level2']['assoc'] );
		$this->assertSame( 'value', $result['level1']['level2']['assoc']['key'] );
	}

	/**
	 * Test that JSON string arguments with arrays are processed correctly.
	 */
	public function test_json_string_with_array_args() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalise_tool_arguments' );
		$method->setAccessible( true );

		$json_args = wp_json_encode(
			array(
				'hook' => 'test',
				'args' => array( 'a', 'b', 'c' ),
			)
		);

		$result = $method->invoke( $this->client, $json_args );

		// Should decode and process the array.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'hook', $result );
		$this->assertArrayHasKey( 'args', $result );

		// The args should be wrapped.
		$this->assertArrayHasKey( 'items', $result['args'] );
		$this->assertSame( array( 'a', 'b', 'c' ), $result['args']['items'] );
	}
}
