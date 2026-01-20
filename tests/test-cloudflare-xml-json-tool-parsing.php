<?php
/**
 * Tests for Cloudflare XML and JSON tool call parsing
 *
 * @package WP_MCP_AI
 */

class Test_Cloudflare_Tool_Call_Parsing extends WP_UnitTestCase {

	private $client;

	public function setUp(): void {
		parent::setUp();

		// Create Cloudflare client instance.
		$this->client = new WP_MCP_AI_Cloudflare_Client();
	}

	/**
	 * Test XML tool call detection.
	 */
	public function test_xml_tool_call_detection() {
		$xml_content = '<name>get_weather</name><arguments>{"city": "London"}</arguments>';

		$method = new ReflectionMethod( 'WP_MCP_AI_Cloudflare_Client', 'contains_xml_tool_call' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $xml_content );

		$this->assertTrue( $result, 'Should detect XML tool call format' );
	}

	/**
	 * Test XML tool call parsing.
	 */
	public function test_xml_tool_call_parsing() {
		$xml_content = '<name>get_weather</name><arguments>{"city": "London", "units": "celsius"}</arguments>';

		$method = new ReflectionMethod( 'WP_MCP_AI_Cloudflare_Client', 'parse_xml_tool_calls' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $xml_content );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertCount( 1, $result, 'Should parse one tool call' );
		$this->assertEquals( 'function', $result[0]['type'], 'Should have type function' );
		$this->assertEquals( 'get_weather', $result[0]['function']['name'], 'Should extract tool name' );

		$arguments = json_decode( $result[0]['function']['arguments'], true );
		$this->assertEquals( 'London', $arguments['city'], 'Should extract arguments' );
	}

	/**
	 * Test XML tool call with multiple tools.
	 */
	public function test_xml_multiple_tool_calls() {
		$xml_content = '<name>get_weather</name><arguments>{"city": "London"}</arguments> <name>get_forecast</name><arguments>{"city": "Paris"}</arguments>';

		$method = new ReflectionMethod( 'WP_MCP_AI_Cloudflare_Client', 'parse_xml_tool_calls' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $xml_content );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertCount( 2, $result, 'Should parse two tool calls' );
		$this->assertEquals( 'get_weather', $result[0]['function']['name'], 'Should extract first tool name' );
		$this->assertEquals( 'get_forecast', $result[1]['function']['name'], 'Should extract second tool name' );
	}

	/**
	 * Test XML tool call detection with whitespace.
	 */
	public function test_xml_tool_call_with_whitespace() {
		$xml_content = '
			<name>get_weather</name>
			<arguments>{"city": "London"}</arguments>
		';

		$method = new ReflectionMethod( 'WP_MCP_AI_Cloudflare_Client', 'contains_xml_tool_call' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $xml_content );

		$this->assertTrue( $result, 'Should detect XML tool call with whitespace' );
	}

	/**
	 * Test JSON tool call detection.
	 */
	public function test_json_tool_call_detection() {
		$json_content = '{"type": "function", "name": "get_open_meteo_forecast", "parameters": {"latitude": "18", "longitude": "77"}}';

		$method = new ReflectionMethod( 'WP_MCP_AI_Cloudflare_Client', 'contains_json_tool_call' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $json_content );

		$this->assertTrue( $result, 'Should detect JSON tool call format' );
	}

	/**
	 * Test JSON tool call parsing with parameters field.
	 */
	public function test_json_tool_call_parsing_with_parameters() {
		$json_content = '{"type": "function", "name": "get_open_meteo_forecast", "parameters": {"latitude": "18", "longitude": "77"}}';

		$method = new ReflectionMethod( 'WP_MCP_AI_Cloudflare_Client', 'parse_json_tool_calls' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $json_content );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertCount( 1, $result, 'Should parse one tool call' );
		$this->assertEquals( 'function', $result[0]['type'], 'Should have type function' );
		$this->assertEquals( 'get_open_meteo_forecast', $result[0]['function']['name'], 'Should extract tool name' );

		$arguments = json_decode( $result[0]['function']['arguments'], true );
		$this->assertEquals( '18', $arguments['latitude'], 'Should extract latitude parameter' );
		$this->assertEquals( '77', $arguments['longitude'], 'Should extract longitude parameter' );
	}

	/**
	 * Test JSON tool call parsing with arguments field.
	 */
	public function test_json_tool_call_parsing_with_arguments() {
		$json_content = '{"type": "function", "name": "get_weather", "arguments": {"city": "London", "units": "celsius"}}';

		$method = new ReflectionMethod( 'WP_MCP_AI_Cloudflare_Client', 'parse_json_tool_calls' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $json_content );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertCount( 1, $result, 'Should parse one tool call' );
		$this->assertEquals( 'get_weather', $result[0]['function']['name'], 'Should extract tool name' );

		$arguments = json_decode( $result[0]['function']['arguments'], true );
		$this->assertEquals( 'London', $arguments['city'], 'Should extract city argument' );
	}

	/**
	 * Test JSON tool call ID format.
	 */
	public function test_json_tool_call_id_format() {
		$json_content = '{"type": "function", "name": "test_tool", "parameters": {}}';

		$method = new ReflectionMethod( 'WP_MCP_AI_Cloudflare_Client', 'parse_json_tool_calls' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $json_content );

		$this->assertStringStartsWith( 'call_json_', $result[0]['id'], 'Should have call_json_ prefix' );
	}

	/**
	 * Test JSON tool call detection with invalid JSON.
	 */
	public function test_json_tool_call_detection_invalid_json() {
		$invalid_json = 'This is not JSON';

		$method = new ReflectionMethod( 'WP_MCP_AI_Cloudflare_Client', 'contains_json_tool_call' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $invalid_json );

		$this->assertFalse( $result, 'Should not detect invalid JSON as tool call' );
	}

	/**
	 * Test JSON tool call detection without required fields.
	 */
	public function test_json_tool_call_detection_missing_fields() {
		$incomplete_json = '{"type": "function"}';

		$method = new ReflectionMethod( 'WP_MCP_AI_Cloudflare_Client', 'contains_json_tool_call' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $incomplete_json );

		$this->assertFalse( $result, 'Should not detect JSON without name field' );
	}

	/**
	 * Test that JSON parsing returns empty array for invalid input.
	 */
	public function test_json_parsing_invalid_input() {
		$invalid_input = 'not json at all';

		$method = new ReflectionMethod( 'WP_MCP_AI_Cloudflare_Client', 'parse_json_tool_calls' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $invalid_input );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertEmpty( $result, 'Should return empty array for invalid input' );
	}

	/**
	 * Test that JSON parsing returns empty array for missing tool name.
	 */
	public function test_json_parsing_missing_tool_name() {
		$json_without_name = '{"type": "function", "parameters": {"test": "value"}}';

		$method = new ReflectionMethod( 'WP_MCP_AI_Cloudflare_Client', 'parse_json_tool_calls' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $json_without_name );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertEmpty( $result, 'Should return empty array when tool name is missing' );
	}

	/**
	 * Test XML tool call with empty arguments.
	 */
	public function test_xml_tool_call_empty_arguments() {
		$xml_content = '<name>simple_tool</name><arguments>{}</arguments>';

		$method = new ReflectionMethod( 'WP_MCP_AI_Cloudflare_Client', 'parse_xml_tool_calls' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $xml_content );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertCount( 1, $result, 'Should parse tool call with empty arguments' );
		$this->assertEquals( 'simple_tool', $result[0]['function']['name'], 'Should extract tool name' );
	}

	/**
	 * Test JSON tool call with empty parameters.
	 */
	public function test_json_tool_call_empty_parameters() {
		$json_content = '{"type": "function", "name": "simple_tool", "parameters": {}}';

		$method = new ReflectionMethod( 'WP_MCP_AI_Cloudflare_Client', 'parse_json_tool_calls' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $json_content );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertCount( 1, $result, 'Should parse tool call with empty parameters' );
		$this->assertEquals( 'simple_tool', $result[0]['function']['name'], 'Should extract tool name' );
	}

	/**
	 * Test that non-function type JSON is not detected.
	 */
	public function test_json_non_function_type_not_detected() {
		$json_content = '{"type": "other", "name": "something"}';

		$method = new ReflectionMethod( 'WP_MCP_AI_Cloudflare_Client', 'contains_json_tool_call' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $json_content );

		$this->assertFalse( $result, 'Should not detect non-function type as tool call' );
	}
}
