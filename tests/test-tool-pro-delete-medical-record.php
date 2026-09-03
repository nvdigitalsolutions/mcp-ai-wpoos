<?php
/**
 * Tests for WP_MCP_AI_Tool_Delete_Medical_Record.
 *
 * Destructive, high-risk path.  Covers: guest forbidden (user_id=0),
 * missing record_id, record not found, wrong post type, and a successful
 * delete happy path.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the delete_medical_record pro tool.
 */
class Test_Tool_Pro_Delete_Medical_Record extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Delete_Medical_Record
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

		update_option( 'wp_mcp_ai_settings', array( 'enable_health_wellness_management' => true ) );

		if ( ! class_exists( 'WP_MCP_AI_Health_Wellness_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/class-wp-mcp-ai-health-wellness-cpt.php';
		}
		WP_MCP_AI_Health_Wellness_CPT::register_post_types();

		$tool_file = dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-delete-medical-record.php';
		if ( ! class_exists( 'WP_MCP_AI_Tool_Delete_Medical_Record' ) && file_exists( $tool_file ) ) {
			require_once $tool_file;
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Delete_Medical_Record' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Delete_Medical_Record class not available.' );
			return;
		}

		$this->tool = new WP_MCP_AI_Tool_Delete_Medical_Record();
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
	public function test_get_slug_returns_delete_medical_record() {
		$this->assertSame( 'delete_medical_record', $this->tool->get_slug() );
	}

	/**
	 * Test that get_capability_flags contains 'destructive'.
	 */
	public function test_capability_flags_contain_destructive() {
		$flags = $this->tool->get_capability_flags();
		$this->assertIsArray( $flags );
		$this->assertContains( 'destructive', $flags );
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
			array( 'record_id' => 1 ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – missing record_id
	// -----------------------------------------------------------------------

	/**
	 * Test that missing record_id returns WP_Error('wp_mcp_ai_missing_id').
	 */
	public function test_missing_record_id_returns_wp_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_id', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – record not found
	// -----------------------------------------------------------------------

	/**
	 * Test that a non-existent record_id returns WP_Error('wp_mcp_ai_not_found').
	 */
	public function test_nonexistent_record_returns_not_found() {
		$result = $this->tool->execute(
			array( 'record_id' => 999999 ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_not_found', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – wrong post type
	// -----------------------------------------------------------------------

	/**
	 * Test that a record_id pointing to a non-medical-record post returns 'wp_mcp_ai_not_found'.
	 */
	public function test_wrong_post_type_returns_not_found() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'post' ) );

		$result = $this->tool->execute(
			array( 'record_id' => $post_id ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_not_found', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – happy path
	// -----------------------------------------------------------------------

	/**
	 * Test that deleting a valid medical record returns a success result.
	 */
	public function test_delete_record_happy_path_returns_success() {
		$record_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_med_record' ) );

		$result = $this->tool->execute(
			array( 'record_id' => $record_id ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
	}
}
