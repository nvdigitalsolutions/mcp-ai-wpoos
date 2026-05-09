<?php
/**
 * Tests for WP_MCP_AI_Pro_Tool_Delete_Pro_Schedule.
 *
 * Exercises the capability gate (user_id=0 surfaces not_blog_member),
 * the missing-schedule_id path, and the not-found path.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the delete_pro_schedule pro tool.
 */
class Test_Tool_Pro_Delete_Pro_Schedule extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Pro_Tool_Delete_Pro_Schedule
	 */
	private $tool;

	/**
	 * Admin user ID used across tests.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Delete_Pro_Schedule' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-delete-pro-schedule.php';
		}

		$this->tool = new WP_MCP_AI_Pro_Tool_Delete_Pro_Schedule();
	}

	// -----------------------------------------------------------------------
	// get_slug / definition
	// -----------------------------------------------------------------------

	/**
	 * Test that get_slug returns the expected string.
	 */
	public function test_get_slug_returns_delete_pro_schedule() {
		$this->assertSame( 'delete_pro_schedule', $this->tool->get_slug() );
	}

	/**
	 * Test that get_capability_flags is an array.
	 */
	public function test_get_capability_flags_returns_array() {
		$flags = $this->tool->get_capability_flags();
		$this->assertIsArray( $flags );
	}

	// -----------------------------------------------------------------------
	// execute – guest user is not a blog member
	// -----------------------------------------------------------------------

	/**
	 * Test that user_id=0 returns a WP_Error because user 0 is not a blog member.
	 *
	 * Even though the bootstrap filter grants manage_options to all users,
	 * is_user_member_of_blog(0) reliably returns false on single-site installs.
	 */
	public function test_guest_returns_wp_error() {
		$result = $this->tool->execute(
			array( 'schedule_id' => 'test-schedule' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
	}

	// -----------------------------------------------------------------------
	// execute – missing schedule_id
	// -----------------------------------------------------------------------

	/**
	 * Test that omitting schedule_id returns WP_Error('missing_id').
	 */
	public function test_missing_schedule_id_returns_wp_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_id', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – schedule not found
	// -----------------------------------------------------------------------

	/**
	 * Test that a non-existent schedule_id returns WP_Error('not_found').
	 */
	public function test_nonexistent_schedule_returns_not_found() {
		$result = $this->tool->execute(
			array( 'schedule_id' => 'definitely-does-not-exist-' . uniqid() ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}
}
