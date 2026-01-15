<?php
/**
 * Tests for WP_MCP_AI_Tool_Pro_Excel class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Pro Excel tool tests.
 *
 * @group tools
 * @group pro-excel
 * @group excel
 */
class WP_MCP_AI_Tool_Pro_Excel_Tests extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Pro_Excel
	 */
	protected $tool;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test user with appropriate capabilities.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		wp_set_current_user( $this->user_id );

		// Load the tool class.
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-pro-excel.php';

		$this->tool = new WP_MCP_AI_Tool_Pro_Excel();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'pro_excel', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
		$this->assertStringContainsString( 'Excel', $this->tool->get_name() );
		$this->assertStringContainsString( 'LAMBDA', $this->tool->get_description() );
	}

	/**
	 * Test parameter schema structure.
	 */
	public function test_parameter_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'operation', $schema['properties'] );
		$this->assertArrayHasKey( 'description', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'operation', $schema['required'] );
	}

	/**
	 * Test operation types in schema.
	 */
	public function test_operation_types() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'enum', $schema['properties']['operation'] );
		$operations = $schema['properties']['operation']['enum'];

		$expected_operations = array( 'generate', 'explain', 'debug', 'document', 'convert', 'lambda' );
		foreach ( $expected_operations as $op ) {
			$this->assertContains( $op, $operations, "Operation '$op' should be supported" );
		}
	}

	/**
	 * Test Excel version options in schema.
	 */
	public function test_excel_version_options() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'excel_version', $schema['properties'] );
		$this->assertArrayHasKey( 'enum', $schema['properties']['excel_version'] );

		$versions = $schema['properties']['excel_version']['enum'];
		$expected_versions = array( 'modern', 'legacy', 'online' );

		foreach ( $expected_versions as $version ) {
			$this->assertContains( $version, $versions, "Excel version '$version' should be supported" );
		}
	}

	/**
	 * Test capability flags interface implementation.
	 */
	public function test_implements_capability_flags_interface() {
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $this->tool );
	}

	/**
	 * Test capability flags returned.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'pro', $flags, 'Tool should be marked as pro' );
		$this->assertContains( 'requires-credentials', $flags, 'Tool requires API credentials' );
		$this->assertContains( 'requires-model', $flags, 'Tool requires AI model' );
		$this->assertContains( 'consumes-tokens', $flags, 'Tool consumes API tokens' );
		$this->assertContains( 'requires-capability', $flags, 'Tool requires user capability' );
	}

	/**
	 * Test required capability.
	 */
	public function test_required_capability() {
		$definition = $this->tool->get_definition();

		$this->assertArrayHasKey( 'required_capability', $definition );
		$this->assertEquals( 'edit_posts', $definition['required_capability'] );
	}

	/**
	 * Test execution without required capability fails.
	 */
	public function test_execution_without_capability_fails() {
		// Create subscriber user (no edit_posts capability).
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$arguments = array(
			'operation'   => 'generate',
			'description' => 'Calculate sum of A1:A10',
		);

		$context = array( 'user_id' => $subscriber_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_permissions', $result->get_error_code() );
	}

	/**
	 * Test validation of required parameters.
	 */
	public function test_missing_required_parameters() {
		$arguments = array(
			// Missing 'operation' and 'description'.
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'operation', $result->get_error_message() );
	}

	/**
	 * Test invalid operation type.
	 */
	public function test_invalid_operation() {
		$arguments = array(
			'operation'   => 'invalid_operation',
			'description' => 'Test description',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'operation', strtolower( $result->get_error_message() ) );
	}

	/**
	 * Test invalid Excel version.
	 */
	public function test_invalid_excel_version() {
		$arguments = array(
			'operation'     => 'generate',
			'description'   => 'Calculate sum',
			'excel_version' => 'invalid_version',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'version', strtolower( $result->get_error_message() ) );
	}

	/**
	 * Test system prompt generation for generate operation.
	 */
	public function test_system_prompt_generate_operation() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'get_system_prompt' );
		$method->setAccessible( true );

		$prompt = $method->invoke( $this->tool, 'generate', 'modern' );

		$this->assertStringContainsString( 'Excel formula expert', $prompt );
		$this->assertStringContainsString( 'LAMBDA', $prompt );
		$this->assertStringContainsString( 'modern', $prompt );
		$this->assertStringContainsString( 'JSON', $prompt );
	}

	/**
	 * Test system prompt generation for explain operation.
	 */
	public function test_system_prompt_explain_operation() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'get_system_prompt' );
		$method->setAccessible( true );

		$prompt = $method->invoke( $this->tool, 'explain', 'modern' );

		$this->assertStringContainsString( 'explain', strtolower( $prompt ) );
		$this->assertStringContainsString( 'formula', strtolower( $prompt ) );
	}

	/**
	 * Test system prompt generation for debug operation.
	 */
	public function test_system_prompt_debug_operation() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'get_system_prompt' );
		$method->setAccessible( true );

		$prompt = $method->invoke( $this->tool, 'debug', 'modern' );

		$this->assertStringContainsString( 'debug', strtolower( $prompt ) );
		$this->assertStringContainsString( 'error', strtolower( $prompt ) );
	}

	/**
	 * Test system prompt generation for lambda operation.
	 */
	public function test_system_prompt_lambda_operation() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'get_system_prompt' );
		$method->setAccessible( true );

		$prompt = $method->invoke( $this->tool, 'lambda', 'modern' );

		$this->assertStringContainsString( 'LAMBDA', $prompt );
		$this->assertStringContainsString( 'recursive', strtolower( $prompt ) );
		$this->assertStringContainsString( 'Turing', $prompt );
	}

	/**
	 * Test legacy version system prompt.
	 */
	public function test_system_prompt_legacy_version() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'get_system_prompt' );
		$method->setAccessible( true );

		$prompt = $method->invoke( $this->tool, 'generate', 'legacy' );

		$this->assertStringContainsString( 'legacy', strtolower( $prompt ) );
		$this->assertStringContainsString( 'traditional', strtolower( $prompt ) );
	}

	/**
	 * Test that tool is registered in registry.
	 */
	public function test_tool_registered_in_registry() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'pro_excel' );
		$this->assertNotNull( $tool, 'Pro Excel tool should be registered' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Pro_Excel', $tool );
	}

	/**
	 * Test tool group assignment.
	 */
	public function test_tool_group_assignment() {
		$registry   = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
		$tool_groups = $registry->get_tool_group_map();

		$this->assertArrayHasKey( 'pro_excel', $tool_groups );
		$this->assertEquals( 'external-tools', $tool_groups['pro_excel'] );
	}

	/**
	 * Test JSON parsing of formula response.
	 */
	public function test_parse_json_response() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'parse_response' );
		$method->setAccessible( true );

		$json_response = wp_json_encode(
			array(
				'formula'     => '=SUM(A1:A10)',
				'explanation' => 'Sum of range A1 to A10',
			)
		);

		$result = $method->invoke( $this->tool, $json_response );

		$this->assertIsArray( $result );
		$this->assertEquals( '=SUM(A1:A10)', $result['formula'] );
		$this->assertEquals( 'Sum of range A1 to A10', $result['explanation'] );
	}

	/**
	 * Test JSON parsing with markdown code blocks.
	 */
	public function test_parse_json_with_markdown() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'parse_response' );
		$method->setAccessible( true );

		$markdown_response = "```json\n" . wp_json_encode(
			array(
				'formula'     => '=VLOOKUP(A1,B:C,2,FALSE)',
				'explanation' => 'Lookup value',
			)
		) . "\n```";

		$result = $method->invoke( $this->tool, $markdown_response );

		$this->assertIsArray( $result );
		$this->assertEquals( '=VLOOKUP(A1,B:C,2,FALSE)', $result['formula'] );
	}

	/**
	 * Test fallback to plain text when JSON parsing fails.
	 */
	public function test_parse_non_json_response() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'parse_response' );
		$method->setAccessible( true );

		$plain_response = '=SUM(A1:A10) - This is a plain text response';

		$result = $method->invoke( $this->tool, $plain_response );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'response', $result );
		$this->assertEquals( $plain_response, $result['response'] );
	}

	/**
	 * Test settings integration - Excel version.
	 */
	public function test_settings_excel_version() {
		// Set Excel version setting.
		update_option( 'wp_mcp_ai_excel_default_version', 'legacy' );

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$this->assertEquals( 'legacy', $settings['excel_default_version'] );
	}

	/**
	 * Test settings integration - LAMBDA enabled.
	 */
	public function test_settings_lambda_enabled() {
		// Set LAMBDA setting.
		update_option( 'wp_mcp_ai_excel_enable_lambda', true );

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$this->assertTrue( $settings['excel_enable_lambda'] );
	}

	/**
	 * Test settings integration - maximum complexity.
	 */
	public function test_settings_max_complexity() {
		// Set max complexity setting.
		update_option( 'wp_mcp_ai_excel_max_complexity', 'advanced' );

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$this->assertEquals( 'advanced', $settings['excel_max_complexity'] );
	}

	/**
	 * Test that tool respects provider availability.
	 */
	public function test_tool_respects_provider_availability() {
		// This test ensures the tool works with different providers.
		// Since we can't actually call the APIs in tests, we verify the tool
		// is properly configured to use provider-agnostic execution.
		
		$definition = $this->tool->get_definition();
		$flags      = $this->tool->get_capability_flags();

		$this->assertContains( 'requires-model', $flags );
		$this->assertContains( 'requires-credentials', $flags );
	}

	/**
	 * Test tool description mentions key features.
	 */
	public function test_description_mentions_key_features() {
		$description = $this->tool->get_description();

		$key_features = array( 'LAMBDA', 'formula', 'Excel', 'generate' );

		foreach ( $key_features as $feature ) {
			$this->assertStringContainsString(
				$feature,
				$description,
				"Description should mention '$feature'"
			);
		}
	}

	/**
	 * Test tool supports all required operations.
	 */
	public function test_supports_all_operations() {
		$schema     = $this->tool->get_parameters_schema();
		$operations = $schema['properties']['operation']['enum'];

		$required_operations = array(
			'generate' => 'Generate formulas from natural language',
			'explain'  => 'Explain existing formulas',
			'debug'    => 'Debug formula errors',
			'document' => 'Document formulas',
			'convert'  => 'Convert between formula types',
			'lambda'   => 'Create LAMBDA functions',
		);

		foreach ( array_keys( $required_operations ) as $operation ) {
			$this->assertContains( $operation, $operations );
		}
	}
}
