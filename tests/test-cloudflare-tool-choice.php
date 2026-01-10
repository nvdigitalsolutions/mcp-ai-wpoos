<?php
/**
 * Tests for Cloudflare tool_choice parameter support.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Cloudflare tool_choice functionality.
 */
class Test_Cloudflare_Tool_Choice extends WP_UnitTestCase {

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
	 * Test that tool_choice="none" excludes tools from payload.
	 */
	public function test_tool_choice_none_excludes_tools() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What can you do?',
			),
		);

		$options = array(
			'tools'       => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'web_search',
						'description' => 'Search the web',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'query' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'tool_choice' => 'none',
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		// Tools should NOT be in payload when tool_choice is "none".
		$this->assertArrayNotHasKey( 'tools', $payload, 'Tools should be excluded when tool_choice is "none"' );
		$this->assertArrayNotHasKey( 'tool_choice', $payload, 'tool_choice should not be in payload when "none" (tools excluded)' );
	}

	/**
	 * Test that tool_choice="auto" includes tools but omits tool_choice field.
	 */
	public function test_tool_choice_auto_includes_tools_without_field() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Search for something',
			),
		);

		$options = array(
			'tools'       => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'web_search',
						'description' => 'Search the web',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'query' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'tool_choice' => 'auto',
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		// Tools should be in payload.
		$this->assertArrayHasKey( 'tools', $payload, 'Tools should be included when tool_choice is "auto"' );
		$this->assertIsArray( $payload['tools'], 'Tools should be an array' );
		$this->assertNotEmpty( $payload['tools'], 'Tools array should not be empty' );

		// tool_choice field should NOT be in payload (auto is default).
		$this->assertArrayNotHasKey( 'tool_choice', $payload, 'tool_choice should not be in payload when "auto" (default)' );
	}

	/**
	 * Test that tool_choice="required" includes both tools and tool_choice field.
	 */
	public function test_tool_choice_required_includes_both() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Get me some data',
			),
		);

		$options = array(
			'tools'       => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'fetch_data',
						'description' => 'Fetch data',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'source' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'tool_choice' => 'required',
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		// Both tools and tool_choice should be in payload.
		$this->assertArrayHasKey( 'tools', $payload, 'Tools should be included when tool_choice is "required"' );
		$this->assertArrayHasKey( 'tool_choice', $payload, 'tool_choice field should be in payload when set to "required"' );
		$this->assertSame( 'required', $payload['tool_choice'], 'tool_choice value should be "required"' );
	}

	/**
	 * Test that tool_choice="any" is supported (alias for required).
	 */
	public function test_tool_choice_any_supported() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Do something',
			),
		);

		$options = array(
			'tools'       => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'do_action',
						'description' => 'Perform action',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(),
						),
					),
				),
			),
			'tool_choice' => 'any',
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		$this->assertArrayHasKey( 'tool_choice', $payload, 'tool_choice field should be in payload' );
		$this->assertSame( 'any', $payload['tool_choice'], 'tool_choice value should be "any"' );
	}

	/**
	 * Test that specific tool choice is supported.
	 */
	public function test_tool_choice_specific_tool() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Use specific tool',
			),
		);

		$tool_name = 'specific_tool';
		$options   = array(
			'tools'       => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => $tool_name,
						'description' => 'A specific tool',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(),
						),
					),
				),
			),
			'tool_choice' => array(
				'type'     => 'function',
				'function' => array( 'name' => $tool_name ),
			),
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		$this->assertArrayHasKey( 'tool_choice', $payload, 'tool_choice field should be in payload' );
		$this->assertIsArray( $payload['tool_choice'], 'tool_choice should be an array for specific tool' );
		$this->assertArrayHasKey( 'function', $payload['tool_choice'], 'tool_choice should have function key' );
		$this->assertSame( $tool_name, $payload['tool_choice']['function']['name'], 'Specific tool name should match' );
	}

	/**
	 * Test that default behavior (no tool_choice) includes tools with auto behavior.
	 */
	public function test_default_behavior_includes_tools() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Default behavior test',
			),
		);

		$options = array(
			'tools' => array(
				array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'default_tool',
						'description' => 'Default tool',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(),
						),
					),
				),
			),
			// No tool_choice specified.
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		// Default behavior should include tools (backward compatible).
		$this->assertArrayHasKey( 'tools', $payload, 'Tools should be included by default' );
		// tool_choice should not be in payload (auto is default).
		$this->assertArrayNotHasKey( 'tool_choice', $payload, 'tool_choice should not be in payload by default (auto)' );
	}

	/**
	 * Test that tool_choice works with empty tools array.
	 */
	public function test_tool_choice_with_empty_tools() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Empty tools test',
			),
		);

		$options = array(
			'tools'       => array(), // Empty tools array.
			'tool_choice' => 'required',
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		// With empty tools, neither tools nor tool_choice should be in payload.
		$this->assertArrayNotHasKey( 'tools', $payload, 'Tools should not be in payload when array is empty' );
		$this->assertArrayNotHasKey( 'tool_choice', $payload, 'tool_choice should not be in payload when tools array is empty' );
	}

	/**
	 * Test that tool_choice is sanitized properly.
	 */
	public function test_tool_choice_sanitization() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Sanitization test',
			),
		);

		// Test with various tool_choice values.
		$test_cases = array(
			'none'     => 'none',
			'auto'     => 'auto',
			'required' => 'required',
			'any'      => 'any',
			'NONE'     => 'NONE', // Should preserve case.
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		foreach ( $test_cases as $input => $expected ) {
			$options = array(
				'tools'       => array(
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
				'tool_choice' => $input,
			);

			$payload = $method->invoke( $this->client, $messages, $options );

			if ( 'none' === $input ) {
				$this->assertArrayNotHasKey( 'tools', $payload, "Tools should be excluded for tool_choice={$input}" );
			} elseif ( 'auto' === $input ) {
				$this->assertArrayNotHasKey( 'tool_choice', $payload, "tool_choice should not be in payload for {$input}" );
			} else {
				$this->assertArrayHasKey( 'tool_choice', $payload, "tool_choice should be in payload for {$input}" );
				$this->assertSame( $expected, $payload['tool_choice'], "tool_choice value should match for {$input}" );
			}
		}
	}
}
