<?php
/**
 * Tests for tool registry robustness and error handling.
 *
 * Covers the new methods added to WP_MCP_AI_Tool_Registry:
 * - is_tool_registered()
 * - execute_tool()
 * - get_tool_definition()
 * - get_tool_capability()
 * - get_registered_tools()
 *
 * @package WP_MCP_AI
 */

/**
 * Mock tool for testing that always succeeds.
 */
class WP_MCP_AI_Mock_Success_Tool implements WP_MCP_AI_Tool_Interface {
	public function get_slug() {
		return 'mock_success';
	}

	public function get_name() {
		return 'Mock Success Tool';
	}

	public function get_description() {
		return 'A tool that always succeeds';
	}

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'test_param' => array(
					'type'        => 'string',
					'description' => 'A test parameter',
				),
			),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		return array(
			'success' => true,
			'args'    => $arguments,
			'context' => $context,
		);
	}
}

/**
 * Mock tool for testing that returns WP_Error.
 */
class WP_MCP_AI_Mock_Error_Tool implements WP_MCP_AI_Tool_Interface {
	public function get_slug() {
		return 'mock_error';
	}

	public function get_name() {
		return 'Mock Error Tool';
	}

	public function get_description() {
		return 'A tool that returns an error';
	}

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		return new WP_Error(
			'mock_error_code',
			'This is a mock error message',
			array( 'status' => 400 )
		);
	}
}

/**
 * Mock tool for testing that throws an exception.
 */
class WP_MCP_AI_Mock_Exception_Tool implements WP_MCP_AI_Tool_Interface {
	public function get_slug() {
		return 'mock_exception';
	}

	public function get_name() {
		return 'Mock Exception Tool';
	}

	public function get_description() {
		return 'A tool that throws an exception';
	}

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		throw new Exception( 'This is a mock exception' );
	}
}

/**
 * Mock tool with custom capability requirement.
 */
class WP_MCP_AI_Mock_Capability_Tool implements WP_MCP_AI_Tool_Interface {
	public function get_slug() {
		return 'mock_capability';
	}

	public function get_name() {
		return 'Mock Capability Tool';
	}

	public function get_description() {
		return 'A tool with custom capability';
	}

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	public function get_required_capability() {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		return array( 'success' => true );
	}
}

/**
 * @group tool-registry
 * @group tool-robustness
 */
class WP_MCP_AI_Tool_Registry_Robustness_Tests extends WP_UnitTestCase {

	/**
	 * Original registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry|null
	 */
	protected $original_instance;

	/**
	 * Test registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	public function setUp(): void {
		parent::setUp();

		// Reset the singleton instance using reflection.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Registry' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$this->original_instance = $property->getValue();
		$property->setValue( null, null );

		// Get fresh instance.
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset tools.
		$tools_property = $reflection->getProperty( 'tools' );
		$tools_property->setAccessible( true );
		$tools_property->setValue( $this->registry, array() );
	}

	public function tearDown(): void {
		// Restore original instance.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Registry' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, $this->original_instance );

		parent::tearDown();
	}

	/**
	 * Test is_tool_registered() returns true for registered tools.
	 */
	public function test_is_tool_registered_returns_true_for_registered_tools() {
		$tool = new WP_MCP_AI_Mock_Success_Tool();
		$this->registry->register_tool( $tool );

		$this->assertTrue( $this->registry->is_tool_registered( 'mock_success' ) );
	}

	/**
	 * Test is_tool_registered() returns false for unregistered tools.
	 */
	public function test_is_tool_registered_returns_false_for_unregistered_tools() {
		$this->assertFalse( $this->registry->is_tool_registered( 'nonexistent_tool' ) );
	}

	/**
	 * Test is_tool_registered() sanitizes slugs.
	 */
	public function test_is_tool_registered_sanitizes_slugs() {
		$tool = new WP_MCP_AI_Mock_Success_Tool();
		$this->registry->register_tool( $tool );

		// Should return true even with uppercase or special chars.
		$this->assertTrue( $this->registry->is_tool_registered( 'MOCK_SUCCESS' ) );
		$this->assertTrue( $this->registry->is_tool_registered( 'mock-success' ) );
	}

	/**
	 * Test execute_tool() with successful tool execution.
	 */
	public function test_execute_tool_with_successful_execution() {
		$tool = new WP_MCP_AI_Mock_Success_Tool();
		$this->registry->register_tool( $tool );

		$result = $this->registry->execute_tool(
			'mock_success',
			array( 'test_param' => 'test_value' ),
			array( 'assistant_id' => 123 )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'test_value', $result['args']['test_param'] );
		$this->assertEquals( 123, $result['context']['assistant_id'] );
	}

	/**
	 * Test execute_tool() with non-existent tool.
	 */
	public function test_execute_tool_with_nonexistent_tool() {
		$result = $this->registry->execute_tool( 'nonexistent_tool' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_tool_not_found', $result->get_error_code() );
		$this->assertStringContainsString( 'not found', $result->get_error_message() );
	}

	/**
	 * Test execute_tool() with tool that returns WP_Error.
	 */
	public function test_execute_tool_with_tool_that_returns_error() {
		$tool = new WP_MCP_AI_Mock_Error_Tool();
		$this->registry->register_tool( $tool );

		$result = $this->registry->execute_tool( 'mock_error' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'mock_error_code', $result->get_error_code() );
		$this->assertEquals( 'This is a mock error message', $result->get_error_message() );
	}

	/**
	 * Test execute_tool() with tool that throws exception.
	 */
	public function test_execute_tool_catches_exceptions() {
		$tool = new WP_MCP_AI_Mock_Exception_Tool();
		$this->registry->register_tool( $tool );

		$result = $this->registry->execute_tool( 'mock_exception' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_tool_execution_exception', $result->get_error_code() );
		$this->assertStringContainsString( 'threw an exception', $result->get_error_message() );
		$this->assertStringContainsString( 'This is a mock exception', $result->get_error_message() );

		// Check error data contains exception details.
		$data = $result->get_error_data();
		$this->assertArrayHasKey( 'exception', $data );
		$this->assertArrayHasKey( 'trace', $data );
	}

	/**
	 * Test get_tool_definition() returns correct structure.
	 */
	public function test_get_tool_definition_returns_correct_structure() {
		$tool = new WP_MCP_AI_Mock_Success_Tool();
		$this->registry->register_tool( $tool );

		$definition = $this->registry->get_tool_definition( 'mock_success' );

		$this->assertIsArray( $definition );
		$this->assertArrayHasKey( 'slug', $definition );
		$this->assertArrayHasKey( 'name', $definition );
		$this->assertArrayHasKey( 'description', $definition );
		$this->assertArrayHasKey( 'parameters', $definition );

		$this->assertEquals( 'mock_success', $definition['slug'] );
		$this->assertEquals( 'Mock Success Tool', $definition['name'] );
		$this->assertEquals( 'A tool that always succeeds', $definition['description'] );
	}

	/**
	 * Test get_tool_definition() returns null for non-existent tool.
	 */
	public function test_get_tool_definition_returns_null_for_nonexistent_tool() {
		$definition = $this->registry->get_tool_definition( 'nonexistent_tool' );

		$this->assertNull( $definition );
	}

	/**
	 * Test get_tool_capability() returns custom capability.
	 */
	public function test_get_tool_capability_returns_custom_capability() {
		$tool = new WP_MCP_AI_Mock_Capability_Tool();
		$this->registry->register_tool( $tool );

		$capability = $this->registry->get_tool_capability( 'mock_capability' );

		$this->assertEquals( 'manage_options', $capability );
	}

	/**
	 * Test get_tool_capability() returns default for tool without custom capability.
	 */
	public function test_get_tool_capability_returns_default_for_tool_without_custom() {
		$tool = new WP_MCP_AI_Mock_Success_Tool();
		$this->registry->register_tool( $tool );

		$capability = $this->registry->get_tool_capability( 'mock_success' );

		$this->assertEquals( 'read', $capability );
	}

	/**
	 * Test get_tool_capability() returns null for non-existent tool.
	 */
	public function test_get_tool_capability_returns_null_for_nonexistent_tool() {
		$capability = $this->registry->get_tool_capability( 'nonexistent_tool' );

		$this->assertNull( $capability );
	}

	/**
	 * Test get_registered_tools() returns all tools.
	 */
	public function test_get_registered_tools_returns_all_tools() {
		$tool1 = new WP_MCP_AI_Mock_Success_Tool();
		$tool2 = new WP_MCP_AI_Mock_Error_Tool();

		$this->registry->register_tool( $tool1 );
		$this->registry->register_tool( $tool2 );

		$tools = $this->registry->get_registered_tools();

		$this->assertIsArray( $tools );
		$this->assertCount( 2, $tools );
		$this->assertArrayHasKey( 'mock_success', $tools );
		$this->assertArrayHasKey( 'mock_error', $tools );

		// Verify structure of returned tools.
		$this->assertArrayHasKey( 'slug', $tools['mock_success'] );
		$this->assertArrayHasKey( 'name', $tools['mock_success'] );
		$this->assertArrayHasKey( 'description', $tools['mock_success'] );
		$this->assertArrayHasKey( 'parameters', $tools['mock_success'] );
	}

	/**
	 * Test get_registered_tools() returns empty array when no tools registered.
	 */
	public function test_get_registered_tools_returns_empty_array_when_no_tools() {
		$tools = $this->registry->get_registered_tools();

		$this->assertIsArray( $tools );
		$this->assertEmpty( $tools );
	}

	/**
	 * Test execute_tool() with empty arguments.
	 */
	public function test_execute_tool_with_empty_arguments() {
		$tool = new WP_MCP_AI_Mock_Success_Tool();
		$this->registry->register_tool( $tool );

		$result = $this->registry->execute_tool( 'mock_success', array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * Test execute_tool() with empty context.
	 */
	public function test_execute_tool_with_empty_context() {
		$tool = new WP_MCP_AI_Mock_Success_Tool();
		$this->registry->register_tool( $tool );

		$result = $this->registry->execute_tool( 'mock_success', array(), array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}
}
