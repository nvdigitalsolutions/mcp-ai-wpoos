<?php
/**
 * Tests for pro tools capability flag presence.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that all pro tools have the 'pro' capability flag.
 */
class Test_Pro_Tools_Capability_Flag extends WP_UnitTestCase {

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
	 * List of pro tool slugs that should have the 'pro' capability flag.
	 *
	 * @return array
	 */
	private function get_pro_tool_slugs() {
		return array(
			'create_google_calendar_event',
			'elementor',
			'get_facebook_instagram_insights',
			'get_google_business_insights',
			'get_linkedin_insights',
			'get_tiktok_insights',
			'google_analytics_report',
			'install_and_activate_plugin',
			'install_and_activate_theme',
			'jetengine',
			'lookup_product_price',
			'post_facebook_instagram',
			'post_google_business_update',
			'post_linkedin_update',
			'post_tiktok_video',
			'product_actualization',
			'quickbooks_report',
			'search_gmail',
			'send_mailjet_email',
			'send_telegram_message',
			'send_whatsapp_message',
			'site_creator',
			'update_option',
			'woo_orders',
			'woo_products',
		);
	}

	/**
	 * Test that all pro tools have the 'pro' capability flag.
	 */
	public function test_all_pro_tools_have_pro_flag() {
		$pro_tools = $this->get_pro_tool_slugs();
		$all_tools = $this->registry->get_tools();

		foreach ( $pro_tools as $tool_slug ) {
			// Check if tool is registered.
			if ( ! isset( $all_tools[ $tool_slug ] ) ) {
				// Tool might not be available if dependencies aren't met (e.g., WooCommerce not active).
				continue;
			}

			$tool = $all_tools[ $tool_slug ];

			// Check if tool implements capability flags interface.
			$this->assertInstanceOf(
				'WP_MCP_AI_Tool_Capability_Flags_Interface',
				$tool,
				sprintf( 'Pro tool "%s" should implement WP_MCP_AI_Tool_Capability_Flags_Interface', $tool_slug )
			);

			// Get capability flags.
			$flags = $tool->get_capability_flags();

			// Assert that 'pro' flag is present.
			$this->assertIsArray( $flags, sprintf( 'Tool "%s" should return an array of capability flags', $tool_slug ) );
			$this->assertContains(
				'pro',
				$flags,
				sprintf( 'Pro tool "%s" should have the "pro" capability flag', $tool_slug )
			);

			// Assert that 'pro' is the first flag.
			$this->assertEquals(
				'pro',
				$flags[0],
				sprintf( 'Pro tool "%s" should have "pro" as the first capability flag', $tool_slug )
			);
		}
	}

	/**
	 * Test that the tools orchestration renderer groups 'pro' flags properly.
	 */
	public function test_orchestration_renderer_groups_pro_flag() {
		// Load the orchestration renderer if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Tools_Orchestration_Renderer' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-tools-orchestration-renderer.php';
		}

		$reflection = new ReflectionClass( 'WP_MCP_AI_Tools_Orchestration_Renderer' );
		$method     = $reflection->getMethod( 'group_capability_flags' );
		$method->setAccessible( true );

		// Test with a sample set of flags including 'pro'.
		$test_flags = array( 'pro', 'read-only', 'write', 'external-api' );
		$grouped    = $method->invoke( null, $test_flags );

		// Assert that 'tier' group exists.
		$this->assertArrayHasKey( 'tier', $grouped, 'Grouped flags should have a "tier" group' );

		// Assert that 'pro' flag is in the tier group.
		$this->assertIsArray( $grouped['tier']['flags'], 'Tier group should have a flags array' );
		$this->assertContains( 'pro', $grouped['tier']['flags'], 'Tier group should contain "pro" flag' );

		// Assert that tier group has the correct color.
		$this->assertEquals( '#9b51e0', $grouped['tier']['color'], 'Tier group should have purple color (#9b51e0)' );
	}

	/**
	 * Test that get_tools_by_capability_flag works for 'pro' flag.
	 */
	public function test_get_tools_by_pro_flag() {
		// Get all tools with 'pro' capability flag.
		$pro_tools = $this->registry->get_tools_by_capability_flag( 'pro' );

		// Assert that we get an array.
		$this->assertIsArray( $pro_tools, 'Should return an array of pro tools' );

		// If any pro tools are available (not all require plugins), verify they have the flag.
		if ( ! empty( $pro_tools ) ) {
			foreach ( $pro_tools as $tool_slug => $tool ) {
				$flags = $tool->get_capability_flags();
				$this->assertContains(
					'pro',
					$flags,
					sprintf( 'Tool "%s" returned by get_tools_by_capability_flag("pro") should have "pro" flag', $tool_slug )
				);
			}
		}
	}
}
