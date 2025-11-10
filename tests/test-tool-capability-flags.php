<?php
/**
 * Tests for tool capability flags system.
 *
 * @package WP_MCP_AI
 */

/**
 * Mock tool with capability flags for testing.
 */
class WP_MCP_AI_Mock_Tool_With_Flags implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	protected $slug;
	protected $flags;

	public function __construct( $slug = 'mock_tool', $flags = array() ) {
		$this->slug  = $slug;
		$this->flags = $flags;
	}

	public function get_slug() {
		return $this->slug;
	}

	public function get_name() {
		return 'Mock Tool';
	}

	public function get_description() {
		return 'A mock tool for testing';
	}

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		return array( 'result' => 'success' );
	}

	public function get_capability_flags() {
		return $this->flags;
	}
}

/**
 * Mock tool without capability flags for backward compatibility testing.
 */
class WP_MCP_AI_Mock_Tool_No_Flags implements WP_MCP_AI_Tool_Interface {
	public function get_slug() {
		return 'mock_tool_no_flags';
	}

	public function get_name() {
		return 'Mock Tool Without Flags';
	}

	public function get_description() {
		return 'A mock tool without capability flags';
	}

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		return array( 'result' => 'success' );
	}
}

/**
 * @group tool-capability-flags
 */
class WP_MCP_AI_Tool_Capability_Flags_Tests extends WP_UnitTestCase {

	/**
	 * Original registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry|null
	 */
	protected $original_instance;

	public function setUp(): void {
		parent::setUp();

		// Reset the singleton instance using reflection.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Registry' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$this->original_instance = $property->getValue();
		$property->setValue( null, null );
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
	 * Test that get_tool_capability_flags returns empty array for tool without flags.
	 */
	public function test_get_tool_capability_flags_no_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = new WP_MCP_AI_Mock_Tool_No_Flags();

		$registry->register_tool( $tool );
		$flags = $registry->get_tool_capability_flags( 'mock_tool_no_flags' );

		$this->assertIsArray( $flags );
		$this->assertEmpty( $flags );
	}

	/**
	 * Test that get_tool_capability_flags returns flags for tool with flags.
	 */
	public function test_get_tool_capability_flags_with_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = new WP_MCP_AI_Mock_Tool_With_Flags( 'test_tool', array( 'read-only', 'cacheable' ) );

		$registry->register_tool( $tool );
		$flags = $registry->get_tool_capability_flags( 'test_tool' );

		$this->assertIsArray( $flags );
		$this->assertCount( 2, $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'cacheable', $flags );
	}

	/**
	 * Test that get_tool_capability_flags returns empty array for non-existent tool.
	 */
	public function test_get_tool_capability_flags_nonexistent_tool() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$flags    = $registry->get_tool_capability_flags( 'nonexistent_tool' );

		$this->assertIsArray( $flags );
		$this->assertEmpty( $flags );
	}

	/**
	 * Test get_all_tool_capability_flags returns map of all tools with flags.
	 */
	public function test_get_all_tool_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset tools using reflection.
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'tools' );
		$property->setAccessible( true );
		$property->setValue( $registry, array() );

		$tool1 = new WP_MCP_AI_Mock_Tool_With_Flags( 'tool_1', array( 'read-only' ) );
		$tool2 = new WP_MCP_AI_Mock_Tool_With_Flags( 'tool_2', array( 'write', 'async' ) );
		$tool3 = new WP_MCP_AI_Mock_Tool_No_Flags();

		$registry->register_tool( $tool1 );
		$registry->register_tool( $tool2 );
		$registry->register_tool( $tool3 );

		$flags_map = $registry->get_all_tool_capability_flags();

		$this->assertIsArray( $flags_map );
		$this->assertArrayHasKey( 'tool_1', $flags_map );
		$this->assertArrayHasKey( 'tool_2', $flags_map );
		$this->assertArrayNotHasKey( 'mock_tool_no_flags', $flags_map ); // Tool without flags should not be in map.

		$this->assertEquals( array( 'read-only' ), $flags_map['tool_1'] );
		$this->assertEquals( array( 'write', 'async' ), $flags_map['tool_2'] );
	}

	/**
	 * Test get_tools_by_capability_flag filters correctly.
	 */
	public function test_get_tools_by_capability_flag() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset tools using reflection.
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'tools' );
		$property->setAccessible( true );
		$property->setValue( $registry, array() );

		$tool1 = new WP_MCP_AI_Mock_Tool_With_Flags( 'tool_1', array( 'read-only', 'cacheable' ) );
		$tool2 = new WP_MCP_AI_Mock_Tool_With_Flags( 'tool_2', array( 'write', 'async' ) );
		$tool3 = new WP_MCP_AI_Mock_Tool_With_Flags( 'tool_3', array( 'read-only', 'local-only' ) );
		$tool4 = new WP_MCP_AI_Mock_Tool_No_Flags();

		$registry->register_tool( $tool1 );
		$registry->register_tool( $tool2 );
		$registry->register_tool( $tool3 );
		$registry->register_tool( $tool4 );

		// Filter by 'read-only' flag.
		$readonly_tools = $registry->get_tools_by_capability_flag( 'read-only' );
		$this->assertCount( 2, $readonly_tools );

		// Filter by 'write' flag.
		$write_tools = $registry->get_tools_by_capability_flag( 'write' );
		$this->assertCount( 1, $write_tools );
		$this->assertEquals( 'tool_2', $write_tools[0]->get_slug() );

		// Filter by non-existent flag.
		$none = $registry->get_tools_by_capability_flag( 'nonexistent' );
		$this->assertEmpty( $none );
	}

	/**
	 * Test that search_content tool has expected capability flags.
	 */
	public function test_search_content_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'search_content' );
		$this->assertNotNull( $tool );

		if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = $tool->get_capability_flags();
			$this->assertIsArray( $flags );
			$this->assertContains( 'read-only', $flags );
			$this->assertContains( 'local-only', $flags );
			$this->assertContains( 'cacheable', $flags );
		}
	}

	/**
	 * Test that save_post tool has expected capability flags.
	 */
	public function test_save_post_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'save_post' );
		$this->assertNotNull( $tool );

		if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = $tool->get_capability_flags();
			$this->assertIsArray( $flags );
			$this->assertContains( 'write', $flags );
			$this->assertContains( 'state-changing', $flags );
			$this->assertNotContains( 'read-only', $flags ); // Write tool should not be read-only.
		}
	}

	/**
	 * Test that generate_openai_image tool has expected capability flags.
	 */
	public function test_generate_openai_image_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'generate_openai_image' );
		$this->assertNotNull( $tool );

		if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = $tool->get_capability_flags();
			$this->assertIsArray( $flags );
			$this->assertContains( 'requires-credentials', $flags );
			$this->assertContains( 'write', $flags );
			$this->assertContains( 'async', $flags );
			$this->assertContains( 'rate-limited', $flags );
		}
	}

	/**
	 * Test that web_search tool has expected capability flags.
	 */
	public function test_web_search_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'web_search' );
		$this->assertNotNull( $tool );

		if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = $tool->get_capability_flags();
			$this->assertIsArray( $flags );
			$this->assertContains( 'external-api', $flags );
			$this->assertContains( 'network-dependent', $flags );
			$this->assertContains( 'non-deterministic', $flags );
		}
	}

	/**
	 * Test WooCommerce tool has expected capability flags if available.
	 */
	public function test_woo_orders_capability_flags() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not available' );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'get_woo_recent_orders' );
		$this->assertNotNull( $tool );

		if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = $tool->get_capability_flags();
			$this->assertIsArray( $flags );
			$this->assertContains( 'requires-plugin', $flags );
			$this->assertContains( 'pii-data', $flags );
			$this->assertContains( 'requires-capability', $flags );
		}
	}

	/**
	 * Test filtering capability flags map via filter.
	 */
	public function test_filter_tool_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset tools.
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'tools' );
		$property->setAccessible( true );
		$property->setValue( $registry, array() );

		$tool = new WP_MCP_AI_Mock_Tool_With_Flags( 'test_tool', array( 'read-only' ) );
		$registry->register_tool( $tool );

		add_filter(
			'wp_mcp_ai_tool_capability_flags',
			function ( $flags_map ) {
				$flags_map['test_tool'][] = 'custom-flag';
				return $flags_map;
			}
		);

		$flags_map = $registry->get_all_tool_capability_flags();

		$this->assertArrayHasKey( 'test_tool', $flags_map );
		$this->assertContains( 'read-only', $flags_map['test_tool'] );
		$this->assertContains( 'custom-flag', $flags_map['test_tool'] );

		remove_all_filters( 'wp_mcp_ai_tool_capability_flags' );
	}

	/**
	 * Test that capability flags can be used for orchestration decisions.
	 */
	public function test_orchestration_scenario_safe_operations() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset tools.
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'tools' );
		$property->setAccessible( true );
		$property->setValue( $registry, array() );

		// Register a mix of safe and unsafe tools.
		$registry->register_tool( new WP_MCP_AI_Mock_Tool_With_Flags( 'safe_read', array( 'read-only', 'local-only' ) ) );
		$registry->register_tool( new WP_MCP_AI_Mock_Tool_With_Flags( 'risky_write', array( 'write', 'state-changing' ) ) );
		$registry->register_tool( new WP_MCP_AI_Mock_Tool_With_Flags( 'external_api', array( 'external-api', 'requires-credentials' ) ) );

		// Get only safe, read-only tools.
		$safe_tools = $registry->get_tools_by_capability_flag( 'read-only' );
		$this->assertCount( 1, $safe_tools );
		$this->assertEquals( 'safe_read', $safe_tools[0]->get_slug() );

		// Get tools that modify state.
		$state_changing = $registry->get_tools_by_capability_flag( 'state-changing' );
		$this->assertCount( 1, $state_changing );
		$this->assertEquals( 'risky_write', $state_changing[0]->get_slug() );
	}

	/**
	 * Test that capability flags help identify tools requiring credentials.
	 */
	public function test_orchestration_scenario_credential_requirements() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset tools.
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'tools' );
		$property->setAccessible( true );
		$property->setValue( $registry, array() );

		$registry->register_tool( new WP_MCP_AI_Mock_Tool_With_Flags( 'local_tool', array( 'local-only' ) ) );
		$registry->register_tool( new WP_MCP_AI_Mock_Tool_With_Flags( 'api_tool', array( 'requires-credentials', 'external-api' ) ) );

		// Get tools that require credentials.
		$needs_creds = $registry->get_tools_by_capability_flag( 'requires-credentials' );
		$this->assertCount( 1, $needs_creds );
		$this->assertEquals( 'api_tool', $needs_creds[0]->get_slug() );

		// Get local-only tools.
		$local_only = $registry->get_tools_by_capability_flag( 'local-only' );
		$this->assertCount( 1, $local_only );
		$this->assertEquals( 'local_tool', $local_only[0]->get_slug() );
	}

	/**
	 * Test that capability flags help identify cacheable tools.
	 */
	public function test_orchestration_scenario_caching() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Reset tools.
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'tools' );
		$property->setAccessible( true );
		$property->setValue( $registry, array() );

		$registry->register_tool( new WP_MCP_AI_Mock_Tool_With_Flags( 'cacheable_tool', array( 'read-only', 'cacheable' ) ) );
		$registry->register_tool( new WP_MCP_AI_Mock_Tool_With_Flags( 'dynamic_tool', array( 'read-only', 'non-deterministic' ) ) );

		// Get tools that can be cached.
		$cacheable = $registry->get_tools_by_capability_flag( 'cacheable' );
		$this->assertCount( 1, $cacheable );
		$this->assertEquals( 'cacheable_tool', $cacheable[0]->get_slug() );

		// Get tools with non-deterministic results.
		$non_deterministic = $registry->get_tools_by_capability_flag( 'non-deterministic' );
		$this->assertCount( 1, $non_deterministic );
		$this->assertEquals( 'dynamic_tool', $non_deterministic[0]->get_slug() );
	}

	/**
	 * Test combining grouping with capability flags.
	 */
	public function test_grouping_and_capability_flags_together() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$group_map = $registry->get_tool_group_map();
		$flags_map = $registry->get_all_tool_capability_flags();

		// Verify search_content has both grouping and capability flags.
		$this->assertArrayHasKey( 'search_content', $group_map );
		$this->assertEquals( 'wordpress-core', $group_map['search_content'] );

		if ( isset( $flags_map['search_content'] ) ) {
			$this->assertIsArray( $flags_map['search_content'] );
			$this->assertNotEmpty( $flags_map['search_content'] );
		}
	}

	/**
	 * Test that tools without flags don't break the system.
	 */
	public function test_backward_compatibility() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Many tools won't have capability flags yet.
		$all_tools = $registry->get_tools();
		$this->assertNotEmpty( $all_tools );

		// Getting flags for any tool should work without errors.
		foreach ( $all_tools as $tool ) {
			$flags = $registry->get_tool_capability_flags( $tool->get_slug() );
			$this->assertIsArray( $flags );
			// Some will be empty, some will have flags - both are valid.
		}
	}
}
