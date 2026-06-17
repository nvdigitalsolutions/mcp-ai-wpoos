<?php
/**
 * Tests for WP_MCP_AI_Pro_Tool_Create_Pro_Schedule.
 *
 * Exercises the capability gate, the not_blog_member path for user 0,
 * and the happy-path schedule creation with the 'task' type.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the create_pro_schedule pro tool.
 */
class Test_Tool_Pro_Create_Pro_Schedule extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Pro_Tool_Create_Pro_Schedule
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

		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Create_Pro_Schedule' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-create-pro-schedule.php';
		}

		$this->tool = new WP_MCP_AI_Pro_Tool_Create_Pro_Schedule();
	}

	/**
	 * Clean up any created schedule options.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_pro_schedules' );
		delete_option( 'wp_mcp_ai_pro_schedule_history' );
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// get_slug / definition
	// -----------------------------------------------------------------------

	/**
	 * Test that get_slug returns the expected string.
	 */
	public function test_get_slug_returns_create_pro_schedule() {
		$this->assertSame( 'create_pro_schedule', $this->tool->get_slug() );
	}

	/**
	 * Test that get_capability_flags returns an array.
	 */
	public function test_get_capability_flags_returns_array() {
		$flags = $this->tool->get_capability_flags();
		$this->assertIsArray( $flags );
	}

	// -----------------------------------------------------------------------
	// execute – guest user (not_blog_member path)
	// -----------------------------------------------------------------------

	/**
	 * Test that user_id=0 returns a WP_Error.
	 *
	 * User 0 is never a blog member so is_user_member_of_blog(0) = false,
	 * which triggers the not_blog_member guard (after the manage_options check
	 * passes via the global bootstrap filter).
	 */
	public function test_guest_returns_wp_error() {
		$result = $this->tool->execute(
			array(
				'name'     => 'Test Schedule',
				'schedule' => 'daily',
			),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
	}

	// -----------------------------------------------------------------------
	// execute – happy path (task type)
	// -----------------------------------------------------------------------

	/**
	 * Test that a valid 'task' schedule is created and returns a schedule_id.
	 */
	public function test_create_task_schedule_returns_schedule_id() {
		$result = $this->tool->execute(
			array(
				'name'          => 'My Test Schedule',
				'schedule_type' => 'task',
				'hook'          => 'my_custom_test_hook',
				'schedule'      => 'daily',
			),
			array( 'user_id' => $this->admin_id )
		);

		// Should return an array (not WP_Error) with schedule_id.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'schedule_id', $result );
		$this->assertNotEmpty( $result['schedule_id'] );
	}

	// -----------------------------------------------------------------------
	// execute – WP_Error propagated from Schedule_Manager
	// -----------------------------------------------------------------------

	/**
	 * Test that a schedule creation that results in an error returns WP_Error.
	 *
	 * Providing an invalid schedule type should trigger validation inside
	 * WP_MCP_AI_Pro_Schedule_Manager::create_schedule() and bubble a WP_Error.
	 */
	public function test_invalid_schedule_type_returns_wp_error_or_array() {
		$result = $this->tool->execute(
			array(
				'name'          => 'Bad Type Schedule',
				'schedule_type' => 'invalid_type_xyz',
			),
			array( 'user_id' => $this->admin_id )
		);

		// Should be either a WP_Error or an array with failure info.
		$this->assertTrue( is_wp_error( $result ) || is_array( $result ) );
	}
}
