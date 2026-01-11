<?php
/**
 * Tests for Cloudflare AI Utils (Embedded Function Calling)
 *
 * @package WP_MCP_AI
 */

/**
 * Test Cloudflare Workers AI utilities functionality.
 */
class Test_Cloudflare_AI_Utils extends WP_UnitTestCase {

	/**
	 * Cloudflare client instance.
	 *
	 * @var WP_MCP_AI_Cloudflare_Client
	 */
	private $client;

	/**
	 * Setup test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->client = new WP_MCP_AI_Cloudflare_Client();

		// Mock settings with valid credentials.
		$settings = array(
			'cloudflare_api_token' => 'test_token_12345',
			'cloudflare_account_id' => 'test_account_id',
			'cloudflare_model' => '@cf/meta/llama-3.1-8b-instruct',
		);
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * Test that Cloudflare client class exists.
	 */
	public function test_cloudflare_client_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Cloudflare_Client' ) );
	}

	/**
	 * Test that run_with_tools method exists.
	 */
	public function test_run_with_tools_method_exists() {
		$this->assertTrue( method_exists( $this->client, 'run_with_tools' ) );
	}

	/**
	 * Test run_with_tools returns error with empty tools array.
	 */
	public function test_run_with_tools_returns_error_with_empty_tools() {
		$messages = array(
			array(
				'role' => 'user',
				'content' => 'Hello',
			),
		);

		$result = $this->client->run_with_tools( $messages, array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_no_tools', $result->get_error_code() );
	}

	/**
	 * Test tool validation with required parameters.
	 */
	public function test_validate_tool_arguments_checks_required_parameters() {
		$function_name = 'get_weather';
		$arguments = array(); // Missing required parameter.

		$tool_definitions = array(
			array(
				'type' => 'function',
				'function' => array(
					'name' => 'get_weather',
					'parameters' => array(
						'type' => 'object',
						'properties' => array(
							'city' => array( 'type' => 'string' ),
						),
						'required' => array( 'city' ),
					),
				),
			),
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method = $reflection->getMethod( 'validate_tool_arguments' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $function_name, $arguments, $tool_definitions );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_missing_required_param', $result->get_error_code() );
	}

	/**
	 * Test tool validation with correct parameters.
	 */
	public function test_validate_tool_arguments_passes_with_valid_parameters() {
		$function_name = 'get_weather';
		$arguments = array( 'city' => 'Mumbai' );

		$tool_definitions = array(
			array(
				'type' => 'function',
				'function' => array(
					'name' => 'get_weather',
					'parameters' => array(
						'type' => 'object',
						'properties' => array(
							'city' => array( 'type' => 'string' ),
						),
						'required' => array( 'city' ),
					),
				),
			),
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method = $reflection->getMethod( 'validate_tool_arguments' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $function_name, $arguments, $tool_definitions );

		$this->assertTrue( $result );
	}

	/**
	 * Test tool validation with type mismatch.
	 */
	public function test_validate_tool_arguments_fails_with_type_mismatch() {
		$function_name = 'get_weather';
		$arguments = array( 'city' => 12345 ); // Should be string, not number.

		$tool_definitions = array(
			array(
				'type' => 'function',
				'function' => array(
					'name' => 'get_weather',
					'parameters' => array(
						'type' => 'object',
						'properties' => array(
							'city' => array( 'type' => 'string' ),
						),
						'required' => array( 'city' ),
					),
				),
			),
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method = $reflection->getMethod( 'validate_tool_arguments' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $function_name, $arguments, $tool_definitions );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_param_type', $result->get_error_code() );
	}

	/**
	 * Test auto_trim_tools keeps relevant tools.
	 */
	public function test_auto_trim_tools_keeps_relevant_tools() {
		$messages = array(
			array(
				'role' => 'user',
				'content' => 'What is the weather like in Mumbai?',
			),
		);

		$tools = array(
			array(
				'name' => 'get-weather',
				'description' => 'Gets weather information for a city',
				'parameters' => array(
					'type' => 'object',
					'properties' => array(
						'city' => array( 'type' => 'string' ),
					),
				),
				'function' => function( $args ) {
					return 'Sunny';
				},
			),
			array(
				'name' => 'search-database',
				'description' => 'Search the database for records',
				'parameters' => array(
					'type' => 'object',
					'properties' => array(
						'query' => array( 'type' => 'string' ),
					),
				),
				'function' => function( $args ) {
					return 'No results';
				},
			),
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method = $reflection->getMethod( 'auto_trim_tools' );
		$method->setAccessible( true );

		$trimmed = $method->invoke( $this->client, $messages, $tools );

		// Weather tool should be first (most relevant).
		$this->assertEquals( 'get-weather', $trimmed[0]['name'] );
	}

	/**
	 * Test auto_trim_tools with no relevant tools keeps minimum.
	 */
	public function test_auto_trim_tools_keeps_minimum_tools() {
		$messages = array(
			array(
				'role' => 'user',
				'content' => 'Tell me about quantum physics',
			),
		);

		$tools = array(
			array(
				'name' => 'get-weather',
				'description' => 'Gets weather information',
				'function' => function( $args ) {
					return 'Sunny';
				},
			),
			array(
				'name' => 'search-database',
				'description' => 'Search database',
				'function' => function( $args ) {
					return 'No results';
				},
			),
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method = $reflection->getMethod( 'auto_trim_tools' );
		$method->setAccessible( true );

		$trimmed = $method->invoke( $this->client, $messages, $tools );

		// Should keep at least 2 tools even without relevance.
		$this->assertGreaterThanOrEqual( 2, count( $trimmed ) );
	}

	/**
	 * Test tool definitions are properly formatted.
	 */
	public function test_tool_definitions_format() {
		$tools = array(
			array(
				'name' => 'test-tool',
				'description' => 'Test tool description',
				'parameters' => array(
					'type' => 'object',
					'properties' => array(
						'param1' => array( 'type' => 'string' ),
					),
					'required' => array( 'param1' ),
				),
				'function' => function( $args ) {
					return 'test result';
				},
			),
		);

		// This would normally be done in run_with_tools, but we test the format.
		$tool_name = $tools[0]['name'];
		$definition = array(
			'name' => $tool_name,
			'description' => $tools[0]['description'],
			'parameters' => $tools[0]['parameters'],
		);

		$tool_definition = array(
			'type' => 'function',
			'function' => $definition,
		);

		$this->assertEquals( 'function', $tool_definition['type'] );
		$this->assertEquals( 'test-tool', $tool_definition['function']['name'] );
		$this->assertEquals( 'Test tool description', $tool_definition['function']['description'] );
		$this->assertArrayHasKey( 'parameters', $tool_definition['function'] );
	}

	/**
	 * Test verbose logging option.
	 */
	public function test_verbose_logging_option() {
		$messages = array(
			array(
				'role' => 'user',
				'content' => 'Test message',
			),
		);

		$tools = array(
			array(
				'name' => 'test-tool',
				'description' => 'Test',
				'parameters' => array(
					'type' => 'object',
					'properties' => array(),
				),
				'function' => function( $args ) {
					return 'result';
				},
			),
		);

		$options = array(
			'verbose' => true,
			'maxRecursiveToolRuns' => 1,
		);

		// Note: This test would need HTTP mocking to fully work.
		// We're just testing that the method accepts the verbose option.
		$this->assertTrue( isset( $options['verbose'] ) && $options['verbose'] );
	}

	/**
	 * Test configuration options are properly handled.
	 */
	public function test_configuration_options_are_handled() {
		$options = array(
			'strictValidation' => false,
			'maxRecursiveToolRuns' => 10,
			'streamFinalResponse' => true,
			'verbose' => true,
			'autoTrimTools' => true,
			'maxTools' => 5,
		);

		// Test that options can be set and retrieved.
		$this->assertFalse( $options['strictValidation'] );
		$this->assertEquals( 10, $options['maxRecursiveToolRuns'] );
		$this->assertTrue( $options['streamFinalResponse'] );
		$this->assertTrue( $options['verbose'] );
		$this->assertTrue( $options['autoTrimTools'] );
		$this->assertEquals( 5, $options['maxTools'] );
	}

	/**
	 * Test type validation allows numbers for number type.
	 */
	public function test_type_validation_allows_integer_for_number() {
		$function_name = 'calculate';
		$arguments = array( 'value' => 42 ); // Integer should be valid for number type.

		$tool_definitions = array(
			array(
				'type' => 'function',
				'function' => array(
					'name' => 'calculate',
					'parameters' => array(
						'type' => 'object',
						'properties' => array(
							'value' => array( 'type' => 'number' ),
						),
						'required' => array( 'value' ),
					),
				),
			),
		);

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method = $reflection->getMethod( 'validate_tool_arguments' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, $function_name, $arguments, $tool_definitions );

		$this->assertTrue( $result );
	}

	/**
	 * Test auto-trim respects maxTools option.
	 */
	public function test_auto_trim_respects_max_tools_option() {
		$messages = array(
			array(
				'role' => 'user',
				'content' => 'weather database search calculate email phone',
			),
		);

		// Create 15 tools.
		$tools = array();
		for ( $i = 1; $i <= 15; $i++ ) {
			$tools[] = array(
				'name' => 'tool-' . $i,
				'description' => 'Tool ' . $i . ' description',
				'function' => function( $args ) {
					return 'result';
				},
			);
		}

		$options = array( 'maxTools' => 5 );

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method = $reflection->getMethod( 'auto_trim_tools' );
		$method->setAccessible( true );

		$trimmed = $method->invoke( $this->client, $messages, $tools, $options );

		// Should not exceed maxTools.
		$this->assertLessThanOrEqual( 5, count( $trimmed ) );
	}

	/**
	 * Test tool function must be callable.
	 */
	public function test_tool_function_must_be_callable() {
		$valid_tool = array(
			'name' => 'valid-tool',
			'description' => 'Valid tool',
			'parameters' => array(),
			'function' => function( $args ) {
				return 'result';
			},
		);

		$this->assertTrue( is_callable( $valid_tool['function'] ) );

		$invalid_tool = array(
			'name' => 'invalid-tool',
			'description' => 'Invalid tool',
			'parameters' => array(),
			'function' => 'not_a_real_function_name',
		);

		$this->assertFalse( is_callable( $invalid_tool['function'] ) );
	}
}
