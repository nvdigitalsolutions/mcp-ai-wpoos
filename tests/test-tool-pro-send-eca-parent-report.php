<?php
/**
 * Tests for WP_MCP_AI_Tool_Send_ECA_Parent_Report.
 *
 * Covers: guest forbidden, missing student_id, invalid student_id (wrong post
 * type), and the no-enrollments path which is always hit when a student has
 * no ECA meta stored.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the send_eca_parent_report pro tool.
 */
class Test_Tool_Pro_Send_ECA_Parent_Report extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Send_ECA_Parent_Report
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

		if ( ! class_exists( 'WP_MCP_AI_Tool_Send_ECA_Parent_Report' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-send-eca-parent-report.php';
		}

		$this->tool = new WP_MCP_AI_Tool_Send_ECA_Parent_Report();
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
	public function test_get_slug_returns_send_eca_parent_report() {
		$this->assertSame( 'send_eca_parent_report', $this->tool->get_slug() );
	}

	// -----------------------------------------------------------------------
	// execute – guest user
	// -----------------------------------------------------------------------

	/**
	 * Test that user_id=0 returns WP_Error('wp_mcp_ai_forbidden').
	 */
	public function test_guest_returns_forbidden() {
		// The tool falls back to the current user when context user_id is 0;
		// a real guest request runs with current user 0.
		wp_set_current_user( 0 );

		$result = $this->tool->execute(
			array( 'student_id' => 1 ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – missing student_id
	// -----------------------------------------------------------------------

	/**
	 * Test that missing student_id returns WP_Error('wp_mcp_ai_missing_id').
	 */
	public function test_missing_student_id_returns_wp_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_id', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – invalid student_id (wrong post type)
	// -----------------------------------------------------------------------

	/**
	 * Test that a student_id pointing to a non-student post returns 'wp_mcp_ai_invalid_student'.
	 */
	public function test_invalid_student_id_returns_wp_error() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'post' ) );

		$result = $this->tool->execute(
			array( 'student_id' => $post_id ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_student', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – student has no enrollments
	// -----------------------------------------------------------------------

	/**
	 * Test that a student with no ECA enrollments returns WP_Error('wp_mcp_ai_no_enrollments').
	 */
	public function test_student_with_no_enrollments_returns_wp_error() {
		$student_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_student' ) );

		$result = $this->tool->execute(
			array( 'student_id' => $student_id ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_no_enrollments', $result->get_error_code() );
	}
}
