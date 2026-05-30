<?php
/**
 * Tests for WP_MCP_AI_Tool_Bulk_Enroll_Students.
 *
 * Covers: guest forbidden, missing eca_id, missing students array,
 * invalid eca_id (wrong post type), and a successful batch-enroll happy path.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the bulk_enroll_students pro tool.
 */
class Test_Tool_Pro_Bulk_Enroll_Students extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Bulk_Enroll_Students
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

		if ( ! class_exists( 'WP_MCP_AI_Tool_Bulk_Enroll_Students' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/eca-management/class-wp-mcp-ai-tool-bulk-enroll-students.php';
		}

		$this->tool = new WP_MCP_AI_Tool_Bulk_Enroll_Students();
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
	public function test_get_slug_returns_bulk_enroll_students() {
		$this->assertSame( 'bulk_enroll_students', $this->tool->get_slug() );
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
				'eca_id'   => 1,
				'students' => array( array( 'student_id' => 1 ) ),
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
	 * Test that missing eca_id returns WP_Error('wp_mcp_ai_missing_eca').
	 */
	public function test_missing_eca_id_returns_wp_error() {
		$result = $this->tool->execute(
			array( 'students' => array( array( 'student_id' => 1 ) ) ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_eca', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – missing students
	// -----------------------------------------------------------------------

	/**
	 * Test that missing students array returns WP_Error('wp_mcp_ai_missing_students').
	 */
	public function test_missing_students_returns_wp_error() {
		$result = $this->tool->execute(
			array( 'eca_id' => 1 ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_students', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – invalid eca_id
	// -----------------------------------------------------------------------

	/**
	 * Test that an eca_id pointing to a non-ECA post returns 'wp_mcp_ai_invalid_eca'.
	 */
	public function test_invalid_eca_id_returns_wp_error() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'post' ) );

		$result = $this->tool->execute(
			array(
				'eca_id'   => $post_id,
				'students' => array( array( 'student_id' => 1 ) ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_eca', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – happy path (enroll student into ECA)
	// -----------------------------------------------------------------------

	/**
	 * Test that valid arguments enroll a student and return a success result.
	 */
	public function test_enroll_student_happy_path_returns_success() {
		$eca_id     = $this->factory->post->create( array( 'post_type' => 'mcp_ai_eca' ) );
		$student_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_student' ) );

		$result = $this->tool->execute(
			array(
				'eca_id'   => $eca_id,
				'students' => array(
					array(
						'student_id'      => $student_id,
						'enrollment_type' => 'confirmed',
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'results', $result );
	}
}
