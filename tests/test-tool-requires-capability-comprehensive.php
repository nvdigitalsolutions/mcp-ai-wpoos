<?php
/**
 * Tests to verify all tools with user_can checks have requires-capability flag.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test that tools with capability checks declare the requires-capability flag.
 */
class Test_Tool_Requires_Capability_Flag extends WP_UnitTestCase {

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
	 * Get list of tool files that contain user_can checks.
	 *
	 * @return array Array of tool slugs that have user_can checks.
	 */
	private function get_tools_with_capability_checks() {
		$tools_dir = WP_MCP_AI_PATH . 'includes/tools/';
		$files     = glob( $tools_dir . 'class-wp-mcp-ai-tool-*.php' );
		$slugs     = array();

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );

			// Check if file contains user_can or capability checks.
			if ( strpos( $content, 'user_can(' ) !== false ||
				strpos( $content, 'user_can_access_attachment(' ) !== false ) {
				// Extract slug from filename.
				$basename = basename( $file );
				$slug     = str_replace( array( 'class-wp-mcp-ai-tool-', '.php' ), '', $basename );
				$slug     = str_replace( '-', '_', $slug );
				$slugs[]  = $slug;
			}
		}

		return $slugs;
	}

	/**
	 * Test that all tools with capability checks have the requires-capability flag.
	 */
	public function test_all_tools_with_capability_checks_have_flag() {
		$tools_with_checks = $this->get_tools_with_capability_checks();

		$this->assertNotEmpty(
			$tools_with_checks,
			'Should have found at least some tools with capability checks'
		);

		$missing_flag = array();

		foreach ( $tools_with_checks as $slug ) {
			$tool = $this->registry->get_tool( $slug );

			if ( ! $tool ) {
				// Tool might not be registered (e.g., requires optional dependencies).
				continue;
			}

			$flags = $this->registry->get_tool_capability_flags( $slug );

			if ( ! in_array( 'requires-capability', $flags, true ) ) {
				$missing_flag[] = $slug;
			}
		}

		$this->assertEmpty(
			$missing_flag,
			'All tools with user_can checks should have requires-capability flag. Missing: ' . implode( ', ', $missing_flag )
		);
	}

	/**
	 * Test specific tools that were fixed.
	 */
	public function test_specific_fixed_tools_have_flag() {
		$fixed_tools = array(
			'get_recent_posts',
			'check_wp_cli',
			'check_site_security',
			'get_site_health',
			'get_user_info',
			'list_cron_jobs',
			'search_attachments',
		);

		foreach ( $fixed_tools as $slug ) {
			$tool = $this->registry->get_tool( $slug );

			if ( ! $tool ) {
				// Tool might not be available in this environment.
				continue;
			}

			$flags = $this->registry->get_tool_capability_flags( $slug );

			$this->assertContains(
				'requires-capability',
				$flags,
				"Tool '{$slug}' should have requires-capability flag"
			);

			// Verify it implements the interface.
			$this->assertInstanceOf(
				'WP_MCP_AI_Tool_Capability_Flags_Interface',
				$tool,
				"Tool '{$slug}' should implement WP_MCP_AI_Tool_Capability_Flags_Interface"
			);
		}
	}

	/**
	 * Test that tools can be retrieved by requires-capability flag.
	 */
	public function test_get_tools_by_requires_capability_flag() {
		$tools = $this->registry->get_tools_by_capability_flag( 'requires-capability' );

		$this->assertIsArray( $tools, 'Should return an array' );
		$this->assertNotEmpty( $tools, 'Should have tools with requires-capability flag' );

		// Verify all returned tools actually have the flag.
		foreach ( $tools as $tool ) {
			$this->assertInstanceOf(
				'WP_MCP_AI_Tool_Interface',
				$tool,
				'All items should be tool instances'
			);

			if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
				$flags = $tool->get_capability_flags();
				$this->assertContains(
					'requires-capability',
					$flags,
					'Tool ' . $tool->get_slug() . ' should have requires-capability flag'
				);
			}
		}
	}

	/**
	 * Test that read-only and requires-capability flags often appear together.
	 */
	public function test_read_only_tools_with_capability_checks() {
		$read_only_tools = $this->registry->get_tools_by_capability_flag( 'read-only' );
		$tools_with_caps = $this->registry->get_tools_by_capability_flag( 'requires-capability' );

		// Count tools that have both flags.
		$both_flags = 0;

		foreach ( $read_only_tools as $tool ) {
			if ( in_array( $tool, $tools_with_caps, true ) ) {
				++$both_flags;
			}
		}

		$this->assertGreaterThan(
			0,
			$both_flags,
			'Some tools should have both read-only and requires-capability flags'
		);
	}
}
