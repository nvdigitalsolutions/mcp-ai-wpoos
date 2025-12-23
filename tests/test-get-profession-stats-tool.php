<?php
/**
 * Tests for get_profession_stats tool including permission checks.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for get_profession_stats tool.
 */
class WP_MCP_AI_Get_Profession_Stats_Tool_Test extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Profession_Stats
	 */
	protected $tool;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Subscriber user ID (no special permissions).
	 *
	 * @var int
	 */
	protected $subscriber_user_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the tool class.
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-profession-stats.php';
		$this->tool = new WP_MCP_AI_Tool_Profession_Stats();

		// Create test users.
		$this->admin_user_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Test that the tool is properly configured.
	 */
	public function test_tool_configuration() {
		$this->assertSame( 'get_profession_stats', $this->tool->get_slug() );
		$this->assertSame( 'Get Profession Statistics', $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
		$this->assertIsArray( $this->tool->get_parameters_schema() );
	}

	/**
	 * Test that the tool has correct capability flags.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'read', $flags );
		$this->assertContains( 'local-only', $flags );
		$this->assertContains( 'safe', $flags );
	}

	/**
	 * Test tool execution with admin user (should succeed).
	 */
	public function test_execute_with_admin_user() {
		// Set current user to admin.
		wp_set_current_user( $this->admin_user_id );

		$context = array(
			'user_id' => $this->admin_user_id,
		);

		$result = $this->tool->execute( array(), $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );

		// Should succeed for admin users.
		if ( function_exists( 'wp_mcp_ai_get_profession_service' ) ) {
			$this->assertTrue( $result['success'], 'Admin should be able to view profession stats' );
			$this->assertArrayHasKey( 'total', $result );
			$this->assertArrayHasKey( 'by_category', $result );
			$this->assertArrayHasKey( 'top_categories', $result );
		}
	}

	/**
	 * Test tool execution with subscriber user (should check permissions).
	 */
	public function test_execute_with_subscriber_user() {
		// Set current user to subscriber.
		wp_set_current_user( $this->subscriber_user_id );

		$context = array(
			'user_id' => $this->subscriber_user_id,
		);

		$result = $this->tool->execute( array(), $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );

		// Subscribers have 'read' capability, so they should be able to view stats.
		// This is correct behavior as stats are read-only informational data.
		if ( function_exists( 'wp_mcp_ai_get_profession_service' ) ) {
			$this->assertTrue( $result['success'], 'Users with read capability should be able to view stats' );
		}
	}

	/**
	 * Test tool execution with no user (anonymous).
	 */
	public function test_execute_with_no_user() {
		// Set no user.
		wp_set_current_user( 0 );

		$context = array(
			'user_id' => 0,
		);

		$result = $this->tool->execute( array(), $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );

		// Anonymous users have no capabilities, so permission check should pass
		// (the check only fails if user_id is set AND user lacks capability).
		if ( function_exists( 'wp_mcp_ai_get_profession_service' ) ) {
			$this->assertTrue( $result['success'], 'Anonymous users can view stats (no user_id restriction)' );
		}
	}

	/**
	 * Test permission capability filter.
	 */
	public function test_permission_capability_filter() {
		// Add filter to require manage_options capability.
		add_filter(
			'wp_mcp_ai_profession_stats_capability',
			function( $default_cap ) {
				return 'manage_options';
			}
		);

		// Subscriber doesn't have manage_options.
		wp_set_current_user( $this->subscriber_user_id );

		$context = array(
			'user_id' => $this->subscriber_user_id,
		);

		$result = $this->tool->execute( array(), $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertFalse( $result['success'], 'Subscriber should not have manage_options capability' );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertStringContainsString( 'permission', strtolower( $result['message'] ) );

		// Remove filter.
		remove_all_filters( 'wp_mcp_ai_profession_stats_capability' );
	}

	/**
	 * Test that tool returns proper structure when professions exist.
	 */
	public function test_result_structure() {
		// Set current user to admin.
		wp_set_current_user( $this->admin_user_id );

		$context = array(
			'user_id' => $this->admin_user_id,
		);

		$result = $this->tool->execute( array(), $context );

		if ( ! function_exists( 'wp_mcp_ai_get_profession_service' ) ) {
			$this->assertFalse( $result['success'] );
			$this->assertStringContainsString( 'not available', $result['message'] );
			return;
		}

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertIsInt( $result['total'] );

		$this->assertArrayHasKey( 'by_category', $result );
		$this->assertIsArray( $result['by_category'] );
		$this->assertArrayHasKey( 'counts', $result['by_category'] );
		$this->assertArrayHasKey( 'percentages', $result['by_category'] );
		$this->assertArrayHasKey( 'labels', $result['by_category'] );

		$this->assertArrayHasKey( 'top_categories', $result );
		$this->assertIsArray( $result['top_categories'] );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}
}
