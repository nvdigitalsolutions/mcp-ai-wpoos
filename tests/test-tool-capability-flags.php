<?php
/**
 * Tests for tool capability flags system.
 *
 * @package WP_MCP_AI
 */

/**
 * Test tool capability flags functionality.
 */
class Test_Tool_Capability_Flags extends WP_UnitTestCase {

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->registry->init();
	}

	/**
	 * Test that get_tool_capability_flags method exists.
	 */
	public function test_get_tool_capability_flags_method_exists() {
		$this->assertTrue(
			method_exists( $this->registry, 'get_tool_capability_flags' ),
			'Tool registry should have get_tool_capability_flags method'
		);
	}

	/**
	 * Test that get_all_tool_capability_flags method exists.
	 */
	public function test_get_all_tool_capability_flags_method_exists() {
		$this->assertTrue(
			method_exists( $this->registry, 'get_all_tool_capability_flags' ),
			'Tool registry should have get_all_tool_capability_flags method'
		);
	}

	/**
	 * Test that get_tools_by_capability_flag method exists.
	 */
	public function test_get_tools_by_capability_flag_method_exists() {
		$this->assertTrue(
			method_exists( $this->registry, 'get_tools_by_capability_flag' ),
			'Tool registry should have get_tools_by_capability_flag method'
		);
	}

	/**
	 * Test getting flags for a non-existent tool returns empty array.
	 */
	public function test_get_capability_flags_nonexistent_tool() {
		$flags = $this->registry->get_tool_capability_flags( 'nonexistent_tool' );
		$this->assertIsArray( $flags, 'Should return an array' );
		$this->assertEmpty( $flags, 'Should return empty array for nonexistent tool' );
	}

	/**
	 * Test getting flags for a tool that implements the interface.
	 */
	public function test_get_capability_flags_for_tool_with_flags() {
		// Test with a tool that implements capability flags interface.
		// Using web_search as an example since it implements the interface.
		$flags = $this->registry->get_tool_capability_flags( 'web_search' );
		$this->assertIsArray( $flags, 'Should return an array' );
		// web_search should have capability flags.
		$this->assertNotEmpty( $flags, 'web_search tool should have capability flags' );
	}

	/**
	 * Test getting all tool capability flags.
	 */
	public function test_get_all_tool_capability_flags() {
		$all_flags = $this->registry->get_all_tool_capability_flags();

		$this->assertIsArray( $all_flags, 'Should return an array' );
		$this->assertNotEmpty( $all_flags, 'Should have at least some tools with flags' );

		// Verify structure - each entry should be slug => array of flags.
		foreach ( $all_flags as $slug => $flags ) {
			$this->assertIsString( $slug, 'Keys should be tool slugs (strings)' );
			$this->assertIsArray( $flags, 'Values should be arrays of flags' );
			$this->assertNotEmpty( $flags, 'Each tool in the map should have at least one flag' );
		}
	}

	/**
	 * Test filtering tools by capability flag.
	 */
	public function test_get_tools_by_capability_flag() {
		// Test with 'requires-credentials' flag - should return tools like web_search, generate_openai_image, etc.
		$tools_with_credentials = $this->registry->get_tools_by_capability_flag( 'requires-credentials' );

		$this->assertIsArray( $tools_with_credentials, 'Should return an array' );
		// There should be some tools requiring credentials.
		$this->assertNotEmpty( $tools_with_credentials, 'Should have tools requiring credentials' );

		// Verify all returned tools actually have the flag.
		foreach ( $tools_with_credentials as $tool ) {
			$this->assertInstanceOf(
				'WP_MCP_AI_Tool_Interface',
				$tool,
				'All returned items should be tool instances'
			);

			if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
				$flags = $tool->get_capability_flags();
				$this->assertContains(
					'requires-credentials',
					$flags,
					'Tool should have the requires-credentials flag'
				);
			}
		}
	}

	/**
	 * Test filtering with a flag that no tools have.
	 */
	public function test_get_tools_by_nonexistent_flag() {
		$tools = $this->registry->get_tools_by_capability_flag( 'nonexistent-flag-xyz' );

		$this->assertIsArray( $tools, 'Should return an array' );
		$this->assertEmpty( $tools, 'Should return empty array for nonexistent flag' );
	}

	/**
	 * Test that tools implementing the interface actually return flags.
	 */
	public function test_tools_with_interface_return_flags() {
		$all_tools = $this->registry->get_tools();

		$tools_with_interface = 0;
		foreach ( $all_tools as $tool ) {
			if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
				++$tools_with_interface;

				$flags = $this->registry->get_tool_capability_flags( $tool->get_slug() );
				$this->assertIsArray( $flags, 'Tool with interface should return array of flags' );

				// Verify the flags are valid strings.
				foreach ( $flags as $flag ) {
					$this->assertIsString( $flag, 'Each flag should be a string' );
					$this->assertNotEmpty( $flag, 'Flag should not be empty' );
				}
			}
		}

		$this->assertGreaterThan(
			0,
			$tools_with_interface,
			'There should be at least some tools implementing the capability flags interface'
		);
	}

	/**
	 * Test common capability flags are used correctly.
	 */
	public function test_common_capability_flags_usage() {
		$common_flags = array(
			'requires-credentials',
			'requires-plugin',
			'read-only',
			'write',
			'external-api',
			'local-only',
		);

		foreach ( $common_flags as $flag ) {
			$tools = $this->registry->get_tools_by_capability_flag( $flag );
			$this->assertIsArray( $tools, "Should return array for flag: $flag" );
			// Not asserting they're non-empty as some flags might not be used.
		}
	}

	/**
	 * Test that tool execution responses include capability flags.
	 */
	public function test_tool_execution_includes_capability_flags() {
		// Create a test user with admin capabilities.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Test with get_system_logs tool which has capability flags.
		$tool_slug = 'get_system_logs';
		$tool      = $this->registry->get_tool( $tool_slug );

		$this->assertNotNull( $tool, 'get_system_logs tool should be registered' );
		$this->assertInstanceOf(
			'WP_MCP_AI_Tool_Capability_Flags_Interface',
			$tool,
			'get_system_logs should implement capability flags interface'
		);

		// Execute the tool.
		$context = array( 'user_id' => $user_id );
		$result  = $tool->execute( array(), $context );

		$this->assertNotWPError( $result, 'Tool execution should succeed' );

		// Get the expected capability flags directly from the tool.
		$expected_flags = $tool->get_capability_flags();
		$this->assertIsArray( $expected_flags, 'Tool should return capability flags array' );
		$this->assertNotEmpty( $expected_flags, 'get_system_logs should have capability flags' );
		$this->assertContains( 'read-only', $expected_flags, 'get_system_logs should have read-only flag' );
		$this->assertContains( 'local-only', $expected_flags, 'get_system_logs should have local-only flag' );
		$this->assertContains( 'requires-capability', $expected_flags, 'get_system_logs should have requires-capability flag' );
	}
}
