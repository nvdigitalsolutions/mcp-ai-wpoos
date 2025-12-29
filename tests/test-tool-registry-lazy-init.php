<?php
/**
 * Tests for WP_MCP_AI_Tool_Registry lazy initialization.
 *
 * Verifies that pro tools and other dynamically registered tools
 * are available when admin pages render by ensuring the registry
 * automatically initializes when getter methods are called.
 *
 * @package WP_MCP_AI
 */

/**
 * Mock pro tool for testing dynamic registration.
 */
class WP_MCP_AI_Mock_Pro_Tool implements WP_MCP_AI_Tool_Interface {
	public function get_slug() {
		return 'mock_pro_tool';
	}

	public function get_name() {
		return 'Mock Pro Tool';
	}

	public function get_description() {
		return 'A mock pro tool registered via action hook';
	}

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		return array( 'result' => 'pro_tool_executed' );
	}
}

/**
 * @group tool-registry
 * @group lazy-init
 */
class WP_MCP_AI_Tool_Registry_Lazy_Init_Tests extends WP_UnitTestCase {

	/**
	 * Original registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry|null
	 */
	protected $original_instance;

	/**
	 * Flag to track if pro tools were registered.
	 *
	 * @var bool
	 */
	protected $pro_tools_registered = false;

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

		// Reset the bootstrapped flag.
		$bootstrapped_property = $reflection->getProperty( 'bootstrapped' );
		$bootstrapped_property->setAccessible( true );
		$bootstrapped_property->setValue( null, false );

		$this->pro_tools_registered = false;
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Remove our test action.
		remove_action( 'wp_mcp_ai_register_tools', array( $this, 'register_pro_tool' ), 20 );

		// Restore original instance.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Registry' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, $this->original_instance );

		parent::tearDown();
	}

	/**
	 * Simulate pro addon tool registration.
	 *
	 * @param WP_MCP_AI_Tool_Registry $registry Tool registry instance.
	 */
	public function register_pro_tool( $registry ) {
		$registry->register_tool( new WP_MCP_AI_Mock_Pro_Tool() );
		$this->pro_tools_registered = true;
	}

	/**
	 * Test that get_tools() triggers lazy initialization.
	 *
	 * This simulates the scenario where the admin Tools Manager page
	 * calls get_tools() before the registry has been explicitly initialized.
	 */
	public function test_get_tools_triggers_lazy_initialization() {
		// Simulate pro addon registering a tool via action hook.
		add_action( 'wp_mcp_ai_register_tools', array( $this, 'register_pro_tool' ), 20 );

		// Get registry instance WITHOUT calling init().
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Call get_tools() - this should trigger lazy initialization.
		$tools = $registry->get_tools();

		// Verify pro tools were registered.
		$this->assertTrue( $this->pro_tools_registered, 'Pro tools should be registered via action hook' );

		// Verify the pro tool is in the list.
		$tool_slugs = array_map(
			function ( $tool ) {
				return $tool->get_slug();
			},
			$tools
		);

		$this->assertContains( 'mock_pro_tool', $tool_slugs, 'Pro tool should be in the tools list' );
	}

	/**
	 * Test that get_tool() triggers lazy initialization.
	 */
	public function test_get_tool_triggers_lazy_initialization() {
		add_action( 'wp_mcp_ai_register_tools', array( $this, 'register_pro_tool' ), 20 );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Call get_tool() - this should trigger lazy initialization.
		$tool = $registry->get_tool( 'mock_pro_tool' );

		$this->assertTrue( $this->pro_tools_registered, 'Pro tools should be registered' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool, 'Should retrieve pro tool instance' );
		$this->assertEquals( 'mock_pro_tool', $tool->get_slug() );
	}

	/**
	 * Test that is_tool_registered() triggers lazy initialization.
	 */
	public function test_is_tool_registered_triggers_lazy_initialization() {
		add_action( 'wp_mcp_ai_register_tools', array( $this, 'register_pro_tool' ), 20 );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Call is_tool_registered() - this should trigger lazy initialization.
		$is_registered = $registry->is_tool_registered( 'mock_pro_tool' );

		$this->assertTrue( $this->pro_tools_registered, 'Pro tools should be registered' );
		$this->assertTrue( $is_registered, 'Pro tool should be registered' );
	}

	/**
	 * Test that get_tool_group_map() triggers lazy initialization.
	 */
	public function test_get_tool_group_map_triggers_lazy_initialization() {
		add_action( 'wp_mcp_ai_register_tools', array( $this, 'register_pro_tool' ), 20 );

		// Also add a filter to add pro tool to group map.
		add_filter(
			'wp_mcp_ai_tool_group_map',
			function ( $map ) {
				$map['mock_pro_tool'] = 'wordpress-plugins';
				return $map;
			},
			20
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Call get_tool_group_map() - this should trigger lazy initialization.
		$group_map = $registry->get_tool_group_map();

		$this->assertTrue( $this->pro_tools_registered, 'Pro tools should be registered' );
		$this->assertArrayHasKey( 'mock_pro_tool', $group_map, 'Pro tool should be in group map' );
	}

	/**
	 * Test that get_tool_group_labels() triggers lazy initialization.
	 */
	public function test_get_tool_group_labels_triggers_lazy_initialization() {
		add_action( 'wp_mcp_ai_register_tools', array( $this, 'register_pro_tool' ), 20 );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Call get_tool_group_labels() - this should trigger lazy initialization.
		$labels = $registry->get_tool_group_labels();

		$this->assertTrue( $this->pro_tools_registered, 'Pro tools should be registered' );
		$this->assertIsArray( $labels, 'Should return labels array' );
		$this->assertArrayHasKey( 'wordpress-core', $labels );
	}

	/**
	 * Test that multiple calls to getters don't re-initialize.
	 */
	public function test_lazy_init_only_runs_once() {
		$init_count = 0;

		add_action(
			'wp_mcp_ai_register_tools',
			function ( $registry ) use ( &$init_count ) {
				$init_count++;
				$this->register_pro_tool( $registry );
			},
			20
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Call multiple getter methods.
		$registry->get_tools();
		$registry->get_tool( 'mock_pro_tool' );
		$registry->get_tool_group_map();
		$registry->is_tool_registered( 'mock_pro_tool' );

		// The action should only fire once.
		$this->assertEquals( 1, $init_count, 'Registry should only initialize once despite multiple getter calls' );
	}

	/**
	 * Test that lazy init works when registry is already initialized.
	 */
	public function test_lazy_init_with_already_initialized_registry() {
		add_action( 'wp_mcp_ai_register_tools', array( $this, 'register_pro_tool' ), 20 );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Explicitly initialize.
		$registry->init();

		// Verify it's initialized.
		$this->assertTrue( $this->pro_tools_registered, 'Pro tools should be registered after init' );

		// Reset the flag.
		$this->pro_tools_registered = false;

		// Now call a getter - it should NOT re-initialize.
		$tools = $registry->get_tools();

		$this->assertFalse( $this->pro_tools_registered, 'Should not re-register tools on subsequent getter calls' );

		// But the pro tool should still be in the list.
		$tool_slugs = array_map(
			function ( $tool ) {
				return $tool->get_slug();
			},
			$tools
		);

		$this->assertContains( 'mock_pro_tool', $tool_slugs, 'Pro tool should still be in the tools list' );
	}

	/**
	 * Test that get_tools_by_capability_flag() triggers lazy initialization.
	 */
	public function test_get_tools_by_capability_flag_triggers_lazy_initialization() {
		add_action( 'wp_mcp_ai_register_tools', array( $this, 'register_pro_tool' ), 20 );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Call get_tools_by_capability_flag() - this should trigger lazy initialization.
		$tools = $registry->get_tools_by_capability_flag( 'read-only' );

		$this->assertTrue( $this->pro_tools_registered, 'Pro tools should be registered' );
		$this->assertIsArray( $tools, 'Should return tools array' );
	}

	/**
	 * Test that get_all_tool_capability_flags() triggers lazy initialization.
	 */
	public function test_get_all_tool_capability_flags_triggers_lazy_initialization() {
		add_action( 'wp_mcp_ai_register_tools', array( $this, 'register_pro_tool' ), 20 );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Call get_all_tool_capability_flags() - this should trigger lazy initialization.
		$flags = $registry->get_all_tool_capability_flags();

		$this->assertTrue( $this->pro_tools_registered, 'Pro tools should be registered' );
		$this->assertIsArray( $flags, 'Should return flags array' );
	}
}
