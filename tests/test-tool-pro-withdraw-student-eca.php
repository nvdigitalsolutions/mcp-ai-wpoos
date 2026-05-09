<?php
/**
 * Tests for WP_MCP_AI_Tool_Withdraw_Student_ECA.
 *
 * Covers: guest forbidden, missing student_id/eca_id, invalid student/eca
 * post types, student not enrolled in the ECA, and a happy path where
 * enrollment meta is set before calling withdraw.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the withdraw_student_eca pro tool.
 */
class Test_Tool_Pro_Withdraw_Student_ECA extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Withdraw_Student_ECA
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

		if ( ! class_exists( 'WP_MCP_AI_Tool_Withdraw_Student_ECA' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-withdraw-student-eca.php';
		}

		$this->tool = new WP_MCP_AI_Tool_Withdraw_Student_ECA();
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
	public function test_get_slug_returns_withdraw_student_eca() {
		$this->assertSame( 'withdraw_student_eca', $this->tool->get_slug() );
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
				'student_id' => 1,
				'eca_id'     => 1,
			),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – missing IDs
	// -----------------------------------------------------------------------

	/**
	 * Test that missing student_id and eca_id returns WP_Error('wp_mcp_ai_missing_ids').
	 */
	public function test_missing_ids_returns_wp_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_ids', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – invalid student_id (wrong post type)
	// -----------------------------------------------------------------------

	/**
	 * Test that a student_id pointing to the wrong post type returns 'wp_mcp_ai_invalid_student'.
	 */
	public function test_invalid_student_id_returns_wp_error() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'post' ) );
		$eca_id  = $this->factory->post->create( array( 'post_type' => 'mcp_ai_eca' ) );

		$result = $this->tool->execute(
			array(
				'student_id' => $post_id,
				'eca_id'     => $eca_id,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_student', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – invalid eca_id (wrong post type)
	// -----------------------------------------------------------------------

	/**
	 * Test that an eca_id pointing to the wrong post type returns 'wp_mcp_ai_invalid_eca'.
	 */
	public function test_invalid_eca_id_returns_wp_error() {
		$student_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_student' ) );
		$post_id    = $this->factory->post->create( array( 'post_type' => 'post' ) );

		$result = $this->tool->execute(
			array(
				'student_id' => $student_id,
				'eca_id'     => $post_id,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_eca', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – student not enrolled
	// -----------------------------------------------------------------------

	/**
	 * Test that a student with no enrollment meta for the ECA returns 'wp_mcp_ai_not_enrolled'.
	 */
	public function test_student_not_enrolled_returns_wp_error() {
		$student_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_student' ) );
		$eca_id     = $this->factory->post->create( array( 'post_type' => 'mcp_ai_eca' ) );

		// No enrollment meta — student is not enrolled.
		$result = $this->tool->execute(
			array(
				'student_id' => $student_id,
				'eca_id'     => $eca_id,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_not_enrolled', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – happy path
	// -----------------------------------------------------------------------

	/**
	 * Test that withdrawing an enrolled student returns a success array.
	 */
	public function test_withdraw_enrolled_student_returns_success() {
		$student_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_student' ) );
		$eca_id     = $this->factory->post->create( array( 'post_type' => 'mcp_ai_eca' ) );

		// Simulate enrolment by setting the expected meta on the ECA post
		// (keyed by student_id, matching the tool's get_post_meta lookup).
		update_post_meta( $eca_id, '_eca_student_enrollments', array( $student_id => array( 'enrollment_type' => 'confirmed' ) ) );

		$result = $this->tool->execute(
			array(
				'student_id' => $student_id,
				'eca_id'     => $eca_id,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
	}
}
