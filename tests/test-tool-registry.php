<?php
/**
 * Tests for WP_MCP_AI_Tool_Registry class.
 *
 * @package WP_MCP_AI
 */

/**
 * Mock tool implementation for testing.
 */
class WP_MCP_AI_Mock_Tool implements WP_MCP_AI_Tool_Interface {
	protected $slug;
	protected $name;

	/**
	 * Constructor.
	 */
	public function __construct( $slug = 'mock_tool', $name = 'Mock Tool' ) {
		$this->slug = $slug;
		$this->name = $name;
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * Get the tool name.
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Get the tool description.
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return 'A mock tool for testing';
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array Parameters schema.
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context Execution context.
	 * @return array|WP_Error Tool result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		return array( 'result' => 'success' );
	}
}

/**
 * @group tool-registry
 */
class WP_MCP_AI_Tool_Registry_Tests extends WP_UnitTestCase {

	/**
	 * Original registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry|null
	 */
	protected $original_instance;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Reset the singleton instance using reflection.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Registry' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$this->original_instance = $property->getValue();
		$property->setValue( null, null );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Restore original instance.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Registry' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, $this->original_instance );

		parent::tearDown();
	}

	/**
	 * Test singleton pattern.
	 */
	public function test_get_instance_returns_singleton() {
		$instance1 = WP_MCP_AI_Tool_Registry::get_instance();
		$instance2 = WP_MCP_AI_Tool_Registry::get_instance();

		$this->assertSame( $instance1, $instance2 );
	}

	/**
	 * Test registering a tool by class name.
	 */
	public function test_register_tool_by_class_name() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$result = $registry->register_tool( 'WP_MCP_AI_Mock_Tool' );

		$this->assertTrue( $result );
	}

	/**
	 * Test registering a tool by instance.
	 */
	public function test_register_tool_by_instance() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = new WP_MCP_AI_Mock_Tool();

		$result = $registry->register_tool( $tool );

		$this->assertTrue( $result );
	}

	/**
	 * Test registering a tool with invalid class name.
	 */
	public function test_register_tool_with_invalid_class() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$result = $registry->register_tool( 'NonExistentClass' );

		$this->assertFalse( $result );
	}

	/**
	 * Test registering non-tool object.
	 */
	public function test_register_tool_with_non_tool_object() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$result = $registry->register_tool( new stdClass() );

		$this->assertFalse( $result );
	}

	/**
	 * Test retrieving a registered tool.
	 */
	public function test_get_tool() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = new WP_MCP_AI_Mock_Tool( 'test_tool' );

		$registry->register_tool( $tool );
		$retrieved = $registry->get_tool( 'test_tool' );

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $retrieved );
		$this->assertEquals( 'test_tool', $retrieved->get_slug() );
	}

	/**
	 * Test retrieving a non-existent tool.
	 */
	public function test_get_tool_nonexistent() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$retrieved = $registry->get_tool( 'nonexistent_tool' );

		$this->assertNull( $retrieved );
	}

	/**
	 * Test unregistering a tool.
	 */
	public function test_unregister_tool() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = new WP_MCP_AI_Mock_Tool( 'test_tool' );

		$registry->register_tool( $tool );
		$this->assertNotNull( $registry->get_tool( 'test_tool' ) );

		$registry->unregister_tool( 'test_tool' );
		$this->assertNull( $registry->get_tool( 'test_tool' ) );
	}

	/**
	 * Test getting all tools.
	 */
	public function test_get_tools() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset tools using reflection.
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'tools' );
		$property->setAccessible( true );
		$property->setValue( $registry, array() );

		$tool1 = new WP_MCP_AI_Mock_Tool( 'tool_1', 'Tool 1' );
		$tool2 = new WP_MCP_AI_Mock_Tool( 'tool_2', 'Tool 2' );

		$registry->register_tool( $tool1 );
		$registry->register_tool( $tool2 );

		$tools = $registry->get_tools();

		$this->assertCount( 2, $tools );
		$this->assertContainsOnlyInstancesOf( 'WP_MCP_AI_Tool_Interface', $tools );
	}

	/**
	 * Test registering multiple tools with same slug.
	 */
	public function test_register_tool_overwrites_same_slug() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$tool1 = new WP_MCP_AI_Mock_Tool( 'same_slug', 'Tool 1' );
		$tool2 = new WP_MCP_AI_Mock_Tool( 'same_slug', 'Tool 2' );

		$registry->register_tool( $tool1 );
		$registry->register_tool( $tool2 );

		$retrieved = $registry->get_tool( 'same_slug' );
		$this->assertEquals( 'Tool 2', $retrieved->get_name() );
	}

	/**
	 * Test get_tool_group_map returns array.
	 */
	public function test_get_tool_group_map() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$map      = $registry->get_tool_group_map();

		$this->assertIsArray( $map );
		$this->assertNotEmpty( $map );
	}

	/**
	 * Test get_tool_group_labels returns array.
	 */
	public function test_get_tool_group_labels() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$labels   = $registry->get_tool_group_labels();

		$this->assertIsArray( $labels );
		$this->assertNotEmpty( $labels );
		$this->assertArrayHasKey( 'wordpress-core', $labels );
		$this->assertArrayHasKey( 'wordpress-plugins', $labels );
		$this->assertArrayHasKey( 'external-tools', $labels );
	}

	/**
	 * Test tool group map contains expected tools.
	 */
	public function test_tool_group_map_contains_known_tools() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$map      = $registry->get_tool_group_map();

		$this->assertArrayHasKey( 'search_content', $map );
		$this->assertArrayHasKey( 'save_post', $map );
		$this->assertArrayHasKey( 'get_site_health', $map );
		$this->assertArrayHasKey( 'web_search', $map );
	}

	/**
	 * Test init is idempotent.
	 */
	public function test_init_is_idempotent() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset bootstrapped state.
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'bootstrapped' );
		$property->setAccessible( true );
		$property->setValue( $registry, false );

		// Call init twice.
		$registry->init();
		$tools_after_first = $registry->get_tools();

		$registry->init();
		$tools_after_second = $registry->get_tools();

		// Should have same tools (init doesn't duplicate).
		$this->assertCount( count( $tools_after_first ), $tools_after_second );
	}

	/**
	 * Test tool slug sanitization.
	 */
	public function test_tool_slug_sanitization() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$tool = new WP_MCP_AI_Mock_Tool( 'Test Tool!@#', 'Test' );
		$registry->register_tool( $tool );

		// Slug should be sanitized.
		$retrieved = $registry->get_tool( 'testtool' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $retrieved );
	}

	/**
	 * Test filtering tool group map.
	 */
	public function test_filter_tool_group_map() {
		add_filter(
			'wp_mcp_ai_tool_group_map',
			function ( $map ) {
				$map['custom_tool'] = 'custom_group';
				return $map;
			}
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$map      = $registry->get_tool_group_map();

		$this->assertArrayHasKey( 'custom_tool', $map );
		$this->assertEquals( 'custom_group', $map['custom_tool'] );

		remove_all_filters( 'wp_mcp_ai_tool_group_map' );
	}

	/**
	 * Test filtering tool group labels.
	 */
	public function test_filter_tool_group_labels() {
		add_filter(
			'wp_mcp_ai_tool_group_labels',
			function ( $labels ) {
				$labels['custom_group'] = 'Custom Group Label';
				return $labels;
			}
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$labels   = $registry->get_tool_group_labels();

		$this->assertArrayHasKey( 'custom_group', $labels );
		$this->assertEquals( 'Custom Group Label', $labels['custom_group'] );

		remove_all_filters( 'wp_mcp_ai_tool_group_labels' );
	}

	/**
	 * Test get_tools returns values only (not keys).
	 */
	public function test_get_tools_returns_values_only() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset tools.
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'tools' );
		$property->setAccessible( true );
		$property->setValue( $registry, array() );

		$tool = new WP_MCP_AI_Mock_Tool( 'test_tool' );
		$registry->register_tool( $tool );

		$tools = $registry->get_tools();
		$keys  = array_keys( $tools );

		// Should be numeric keys.
		$this->assertEquals( array( 0 ), $keys );
	}
}
