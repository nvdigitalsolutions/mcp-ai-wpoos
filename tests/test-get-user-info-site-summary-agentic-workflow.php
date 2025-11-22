<?php
/**
 * Tests for get_user_info and get_site_summary agentic workflow issue.
 *
 * This test verifies that running get_user_info followed by get_site_summary
 * works correctly in the agentic workflow without breaking due to caching
 * or categorization issues.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for get_user_info and get_site_summary workflow.
 */
class WP_MCP_AI_Test_Get_User_Info_Site_Summary_Agentic_Workflow extends WP_UnitTestCase {

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	/**
	 * Agentic workflow optimizer instance.
	 *
	 * @var WP_MCP_AI_Agentic_Workflow_Optimizer
	 */
	protected $optimizer;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user.
		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );

		// Initialize tool registry.
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->registry->init();

		// Initialize optimizer.
		$this->optimizer = new WP_MCP_AI_Agentic_Workflow_Optimizer();

		// Clear any existing cache.
		wp_cache_flush();
	}

	/**
	 * Test that both tools are properly categorized in tool recommendations.
	 */
	public function test_both_tools_are_in_low_resource_category() {
		$get_user_info_rec    = WP_MCP_AI_Tool_Recommendations::get_tool_recommendation( 'get_user_info' );
		$get_site_summary_rec = WP_MCP_AI_Tool_Recommendations::get_tool_recommendation( 'get_site_summary' );

		$this->assertNotNull( $get_user_info_rec, 'get_user_info should have a recommendation' );
		$this->assertNotNull( $get_site_summary_rec, 'get_site_summary should have a recommendation' );

		$this->assertEquals( 'low_resource', $get_user_info_rec['category'], 'get_user_info should be in low_resource category' );
		$this->assertEquals( 'low_resource', $get_site_summary_rec['category'], 'get_site_summary should be in low_resource category' );

		$this->assertEquals( 1.0, $get_user_info_rec['multiplier'], 'get_user_info should have 1.0 multiplier' );
		$this->assertEquals( 1.0, $get_site_summary_rec['multiplier'], 'get_site_summary should have 1.0 multiplier' );
	}

	/**
	 * Test that tools have correct cacheability based on their context dependencies.
	 *
	 * get_user_info depends on user context (which user is calling) and should NOT be cacheable
	 * to prevent security issues where User A's data could be returned to User B.
	 *
	 * get_site_summary does not depend on user context (same data for all admin users)
	 * and should be cacheable for performance.
	 */
	public function test_tools_have_correct_cacheability() {
		$reflection_class  = new ReflectionClass( 'WP_MCP_AI_Agentic_Workflow_Optimizer' );
		$reflection_method = $reflection_class->getMethod( 'is_cacheable_tool' );
		$reflection_method->setAccessible( true );

		$is_user_info_cacheable    = $reflection_method->invoke( $this->optimizer, 'get_user_info' );
		$is_site_summary_cacheable = $reflection_method->invoke( $this->optimizer, 'get_site_summary' );

		$this->assertFalse( $is_user_info_cacheable, 'get_user_info should NOT be cacheable (depends on user context)' );
		$this->assertTrue( $is_site_summary_cacheable, 'get_site_summary should be cacheable (same for all admin users)' );
	}

	/**
	 * Test that running get_user_info then get_site_summary works correctly.
	 */
	public function test_sequential_execution_works() {
		$get_user_info_tool    = $this->registry->get_tool( 'get_user_info' );
		$get_site_summary_tool = $this->registry->get_tool( 'get_site_summary' );

		$this->assertNotNull( $get_user_info_tool, 'get_user_info tool should be registered' );
		$this->assertNotNull( $get_site_summary_tool, 'get_site_summary tool should be registered' );

		$context = array( 'user_id' => $this->user_id );

		// Execute get_user_info first.
		$user_info_result = $get_user_info_tool->execute( array(), $context );
		$this->assertNotWPError( $user_info_result, 'get_user_info should not return an error' );
		$this->assertIsArray( $user_info_result, 'get_user_info should return an array' );
		$this->assertArrayHasKey( 'ID', $user_info_result, 'get_user_info result should have ID' );

		// Execute get_site_summary second.
		$site_summary_result = $get_site_summary_tool->execute( array(), $context );
		$this->assertNotWPError( $site_summary_result, 'get_site_summary should not return an error' );
		$this->assertIsArray( $site_summary_result, 'get_site_summary should return an array' );
		$this->assertArrayHasKey( 'site_name', $site_summary_result, 'get_site_summary result should have site_name' );
	}

	/**
	 * Test that get_site_summary caches correctly while get_user_info does not.
	 *
	 * This is intentional behavior:
	 * - get_site_summary returns the same data for all admin users, so caching is safe
	 * - get_user_info returns different data based on the calling user's context, so caching
	 *   would be a security risk (User A's data could leak to User B)
	 */
	public function test_site_summary_caches_user_info_does_not() {
		$get_user_info_tool    = $this->registry->get_tool( 'get_user_info' );
		$get_site_summary_tool = $this->registry->get_tool( 'get_site_summary' );

		$context = array( 'user_id' => $this->user_id );

		// Clear cache.
		wp_cache_flush();

		// Execute get_user_info - should NOT be cached.
		$user_info_result1 = $get_user_info_tool->execute( array(), $context );
		$this->assertNotWPError( $user_info_result1, 'get_user_info first execution should succeed' );

		// Execute get_site_summary - should be cached.
		$site_summary_result1 = $get_site_summary_tool->execute( array(), $context );
		$this->assertNotWPError( $site_summary_result1, 'get_site_summary first execution should succeed' );

		// Verify that only get_site_summary is cacheable.
		$reflection_class  = new ReflectionClass( 'WP_MCP_AI_Agentic_Workflow_Optimizer' );
		$is_cacheable      = $reflection_class->getMethod( 'is_cacheable_tool' );
		$is_cacheable->setAccessible( true );

		$this->assertFalse( $is_cacheable->invoke( $this->optimizer, 'get_user_info' ), 'get_user_info should not be cacheable' );
		$this->assertTrue( $is_cacheable->invoke( $this->optimizer, 'get_site_summary' ), 'get_site_summary should be cacheable' );
	}

	/**
	 * Test that both tools have consistent capability flags.
	 */
	public function test_both_tools_have_consistent_capability_flags() {
		$get_user_info_tool    = $this->registry->get_tool( 'get_user_info' );
		$get_site_summary_tool = $this->registry->get_tool( 'get_site_summary' );

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $get_user_info_tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $get_site_summary_tool );

		$user_info_flags    = $get_user_info_tool->get_capability_flags();
		$site_summary_flags = $get_site_summary_tool->get_capability_flags();

		// Both should be read-only.
		$this->assertContains( 'read-only', $user_info_flags, 'get_user_info should be read-only' );
		$this->assertContains( 'read-only', $site_summary_flags, 'get_site_summary should be read-only' );

		// Both should be local-only.
		$this->assertContains( 'local-only', $user_info_flags, 'get_user_info should be local-only' );
		$this->assertContains( 'local-only', $site_summary_flags, 'get_site_summary should be local-only' );

		// Both should require capability.
		$this->assertContains( 'requires-capability', $user_info_flags, 'get_user_info should require capability' );
		$this->assertContains( 'requires-capability', $site_summary_flags, 'get_site_summary should require capability' );
	}

	/**
	 * Test that the order of execution doesn't matter.
	 */
	public function test_reverse_order_also_works() {
		$get_user_info_tool    = $this->registry->get_tool( 'get_user_info' );
		$get_site_summary_tool = $this->registry->get_tool( 'get_site_summary' );

		$context = array( 'user_id' => $this->user_id );

		// Clear cache.
		wp_cache_flush();

		// Execute get_site_summary FIRST this time.
		$site_summary_result = $get_site_summary_tool->execute( array(), $context );
		$this->assertNotWPError( $site_summary_result, 'get_site_summary should not return an error' );
		$this->assertIsArray( $site_summary_result, 'get_site_summary should return an array' );

		// Execute get_user_info SECOND.
		$user_info_result = $get_user_info_tool->execute( array(), $context );
		$this->assertNotWPError( $user_info_result, 'get_user_info should not return an error' );
		$this->assertIsArray( $user_info_result, 'get_user_info should return an array' );

		// Both results should be valid regardless of order.
		$this->assertArrayHasKey( 'site_name', $site_summary_result );
		$this->assertArrayHasKey( 'ID', $user_info_result );
	}
}
