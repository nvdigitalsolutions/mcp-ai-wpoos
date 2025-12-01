<?php
/**
 * Tests for get_recent_posts tool.
 *
 * @package WP_MCP_AI
 */

/**
 * Test get_recent_posts tool functionality.
 */
class Test_Tool_Get_Recent_Posts extends WP_UnitTestCase {

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
	 * Test that get_recent_posts tool has the requires-capability flag.
	 */
	public function test_get_recent_posts_has_requires_capability_flag() {
		$flags = $this->registry->get_tool_capability_flags( 'get_recent_posts' );

		$this->assertIsArray( $flags, 'Should return an array of flags' );
		$this->assertContains(
			'requires-capability',
			$flags,
			'get_recent_posts tool should have the requires-capability flag'
		);
	}

	/**
	 * Test that get_recent_posts tool has appropriate capability flags.
	 */
	public function test_get_recent_posts_capability_flags() {
		$flags = $this->registry->get_tool_capability_flags( 'get_recent_posts' );

		$this->assertIsArray( $flags, 'Should return an array of flags' );
		$this->assertNotEmpty( $flags, 'Should have at least one flag' );

		// Verify specific flags that should be present.
		$expected_flags = array(
			'read-only',            // Only reads data.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capability.
			'cacheable',            // Results can be cached.
		);

		foreach ( $expected_flags as $expected_flag ) {
			$this->assertContains(
				$expected_flag,
				$flags,
				"get_recent_posts tool should have the {$expected_flag} flag"
			);
		}
	}

	/**
	 * Test that get_recent_posts tool implements the capability flags interface.
	 */
	public function test_get_recent_posts_implements_capability_flags_interface() {
		$tool = $this->registry->get_tool( 'get_recent_posts' );

		$this->assertNotNull( $tool, 'Tool should exist in registry' );
		$this->assertInstanceOf(
			'WP_MCP_AI_Tool_Capability_Flags_Interface',
			$tool,
			'get_recent_posts should implement WP_MCP_AI_Tool_Capability_Flags_Interface'
		);
	}

	/**
	 * Test that get_recent_posts can be retrieved by requires-capability flag.
	 */
	public function test_get_recent_posts_retrievable_by_capability_flag() {
		$tools = $this->registry->get_tools_by_capability_flag( 'requires-capability' );

		$this->assertIsArray( $tools, 'Should return an array of tools' );

		// Find get_recent_posts in the list.
		$found = false;
		foreach ( $tools as $tool ) {
			if ( $tool->get_slug() === 'get_recent_posts' ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue(
			$found,
			'get_recent_posts should be in the list of tools with requires-capability flag'
		);
	}
}
