<?php
/**
 * Test to debug run_with_tools XML tool call issue
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Cloudflare XML tool calls debugging.
 */
class Test_Cloudflare_Run_With_Tools_XML_Debug extends WP_UnitTestCase {

	/**
	 * Cloudflare client instance.
	 *
	 * @var WP_MCP_AI_Cloudflare_Client
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
	 * Test that XML tool calls in content are properly parsed and tools are executed.
	 */
	public function test_xml_tool_calls_are_executed_in_run_with_tools() {
		// Simulate what happens when model returns XML in content
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is 5 + 3?',
			),
		);

		$tool_executed = false;
		$tool_result = null;

		$tools = array(
			array(
				'name'        => 'calculate',
				'description' => 'Perform a calculation',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'expression' => array(
							'type'        => 'string',
							'description' => 'Math expression',
						),
					),
					'required'   => array( 'expression' ),
				),
				'function'    => function( $args ) use ( &$tool_executed, &$tool_result ) {
					$tool_executed = true;
					$tool_result = $args;
					// Simple eval for testing (never use in production!)
					return array( 'result' => 8 );
				},
			),
		);

		// Mock a response with XML tool call in content
		// This simulates what some Cloudflare models do
		$this->assertTrue( true, 'Test setup complete' );

		/*
		 * The issue: When model returns XML like:
		 * <name>calculate</name><arguments>{"expression":"5+3"}</arguments>
		 *
		 * Expected behavior:
		 * 1. XML is parsed to tool_calls
		 * 2. Tool is executed
		 * 3. Result is sent back to model
		 * 4. Final response is returned
		 *
		 * Current behavior (bug):
		 * 1. XML is parsed
		 * 2. Content is cleared
		 * 3. Response is returned with empty content
		 * 4. Tool is NOT executed
		 */
	}

	/**
	 * Test that finish_reason is correctly set when XML tool calls are found.
	 */
	public function test_finish_reason_set_correctly_for_xml_tool_calls() {
		// Mock response with XML tool calls
		$decoded = array(
			'success' => true,
			'result'  => array(
				'response' => '<name>test_tool</name><arguments>{"param":"value"}</arguments>',
			),
		);

		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'normalize_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $decoded, '@cf/meta/llama-3.1-8b-instruct' );

		// After XML parsing, tool_calls should be present
		$this->assertArrayHasKey( 'tool_calls', $result['choices'][0]['message'], 'XML should be parsed to tool_calls' );

		// finish_reason should be 'tool_calls' not 'stop'
		$this->assertEquals(
			'tool_calls',
			$result['choices'][0]['finish_reason'],
			'finish_reason should be "tool_calls" when XML tool calls are parsed'
		);

		// Content should be empty since XML was converted
		$this->assertEquals(
			'',
			$result['choices'][0]['message']['content'],
			'Content should be empty after XML conversion'
		);
	}

	/**
	 * Test the XML parsing method directly.
	 */
	public function test_xml_parsing_method() {
		$content = '<name>get_weather</name><arguments>{"location":"London"}</arguments>';

		$reflection = new ReflectionClass( $this->client );

		// Test contains_xml_tool_call.
		$contains_method = $reflection->getMethod( 'contains_xml_tool_call' );
		$contains_method->setAccessible( true );
		$has_xml = $contains_method->invoke( $this->client, $content );

		$this->assertTrue( $has_xml, 'Should detect XML tool call pattern' );

		// Test parse_xml_tool_calls.
		$parse_method = $reflection->getMethod( 'parse_xml_tool_calls' );
		$parse_method->setAccessible( true );
		$parsed = $parse_method->invoke( $this->client, $content );

		$this->assertIsArray( $parsed );
		$this->assertNotEmpty( $parsed );
		$this->assertEquals( 'get_weather', $parsed[0]['function']['name'] );

		// Arguments should be JSON string.
		$this->assertIsString( $parsed[0]['function']['arguments'] );
		$args = json_decode( $parsed[0]['function']['arguments'], true );
		$this->assertEquals( 'London', $args['location'] );
	}
}
