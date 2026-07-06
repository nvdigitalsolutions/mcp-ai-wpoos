<?php
/**
 * Tests for WP_MCP_AI_Tool_Mark_ECA_Attendance.
 *
 * Covers: guest forbidden, missing eca_id, invalid eca_id, invalid session_date,
 * missing attendees, and a happy-path attendance recording.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the mark_eca_attendance pro tool.
 */
class Test_Tool_Pro_Mark_ECA_Attendance extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Mark_ECA_Attendance
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

		update_option( 'wp_mcp_ai_settings', array( 'enable_eca_management' => true ) );

		if ( ! class_exists( 'WP_MCP_AI_ECA_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/class-wp-mcp-ai-eca-cpt.php';
		}
		WP_MCP_AI_ECA_CPT::register_post_types();

		if ( ! class_exists( 'WP_MCP_AI_Tool_Mark_ECA_Attendance' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-mark-eca-attendance.php';
		}

		$this->tool = new WP_MCP_AI_Tool_Mark_ECA_Attendance();
	}

	/**
	 * Clean up option after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// get_slug
	// -----------------------------------------------------------------------

	/**
	 * Test that get_slug returns the expected string.
	 */
	public function test_get_slug_returns_mark_eca_attendance() {
		$this->assertSame( 'mark_eca_attendance', $this->tool->get_slug() );
	}

	// -----------------------------------------------------------------------
	// execute – guest user
	// -----------------------------------------------------------------------

	/**
	 * Test that user_id=0 returns WP_Error('wp_mcp_ai_forbidden').
	 */
	public function test_guest_returns_forbidden() {
		$result = $this->tool->execute(
			array(
				'eca_id'       => 1,
				'session_date' => '2025-01-01',
				'attendees'    => array(
					array(
						'student_id' => 1,
						'status'     => 'present',
					),
				),
			),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – missing eca_id
	// -----------------------------------------------------------------------

	/**
	 * Test that missing eca_id returns WP_Error('wp_mcp_ai_missing_id').
	 */
	public function test_missing_eca_id_returns_wp_error() {
		$result = $this->tool->execute(
			array(
				'session_date' => '2025-01-01',
				'attendees'    => array(
					array(
						'student_id' => 1,
						'status'     => 'present',
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_id', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – invalid eca_id
	// -----------------------------------------------------------------------

	/**
	 * Test that a non-ECA post ID returns WP_Error('wp_mcp_ai_invalid_eca').
	 */
	public function test_invalid_eca_id_returns_wp_error() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'post' ) );

		$result = $this->tool->execute(
			array(
				'eca_id'       => $post_id,
				'session_date' => '2025-01-01',
				'attendees'    => array(
					array(
						'student_id' => 1,
						'status'     => 'present',
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_eca', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – invalid session_date format
	// -----------------------------------------------------------------------

	/**
	 * Test that an invalid session_date returns WP_Error('wp_mcp_ai_invalid_date').
	 */
	public function test_invalid_session_date_returns_wp_error() {
		$eca_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_eca' ) );

		$result = $this->tool->execute(
			array(
				'eca_id'       => $eca_id,
				'session_date' => 'not-a-date',
				'attendees'    => array(
					array(
						'student_id' => 1,
						'status'     => 'present',
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_date', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – missing attendees
	// -----------------------------------------------------------------------

	/**
	 * Test that an empty attendees array returns WP_Error('wp_mcp_ai_missing_attendees').
	 */
	public function test_missing_attendees_returns_wp_error() {
		$eca_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_eca' ) );

		$result = $this->tool->execute(
			array(
				'eca_id'       => $eca_id,
				'session_date' => '2025-06-01',
				'attendees'    => array(),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_attendees', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – happy path
	// -----------------------------------------------------------------------

	/**
	 * Test that valid inputs record attendance and return a success array.
	 */
	public function test_mark_attendance_happy_path_returns_success() {
		$eca_id     = $this->factory->post->create( array( 'post_type' => 'mcp_ai_eca' ) );
		$student_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_student' ) );

		$result = $this->tool->execute(
			array(
				'eca_id'       => $eca_id,
				'session_date' => '2025-06-01',
				'attendees'    => array(
					array(
						'student_id' => $student_id,
						'status'     => 'present',
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
	}
}
