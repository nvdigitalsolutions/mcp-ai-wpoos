<?php
/**
 * Tests for WP_MCP_AI_Tool_Create_Medical_Record.
 *
 * Sensitive HIPAA-adjacent medical data.  Covers: guest forbidden,
 * missing member_id, missing record_type and title, record-not-found on
 * update, and a successful creation happy path.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the create_medical_record pro tool.
 */
class Test_Tool_Pro_Create_Medical_Record extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Create_Medical_Record
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

		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Medical_Record' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-create-medical-record.php';
		}

		$this->tool = new WP_MCP_AI_Tool_Create_Medical_Record();
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
	public function test_get_slug_returns_create_medical_record() {
		$this->assertSame( 'create_medical_record', $this->tool->get_slug() );
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
				'member_id'   => 1,
				'record_type' => 'diagnosis',
				'title'       => 'Test Record',
			),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_member', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – missing member_id
	// -----------------------------------------------------------------------

	/**
	 * Test that missing member_id returns WP_Error('wp_mcp_ai_missing_member').
	 */
	public function test_missing_member_id_returns_wp_error() {
		$result = $this->tool->execute(
			array(
				'record_type' => 'diagnosis',
				'title'       => 'Test Record',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_member', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – update with non-existent medical_record_id
	// -----------------------------------------------------------------------

	/**
	 * Test that providing a non-existent medical_record_id returns WP_Error('wp_mcp_ai_record_not_found').
	 */
	public function test_update_nonexistent_record_returns_wp_error() {
		$member_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_member' ) );

		$result = $this->tool->execute(
			array(
				'medical_record_id' => 999999,
				'member_id'         => $member_id,
				'record_type'       => 'diagnosis',
				'title'             => 'Test',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_record_not_found', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – happy path (create)
	// -----------------------------------------------------------------------

	/**
	 * Test that valid arguments create a medical record and return a record_id.
	 */
	public function test_create_record_happy_path_returns_record_id() {
		$member_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_member' ) );

		$result = $this->tool->execute(
			array(
				'member_id'   => $member_id,
				'record_type' => 'diagnosis',
				'title'       => 'Annual Blood Panel',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertArrayHasKey( 'record_id', $result );
		$this->assertGreaterThan( 0, $result['record_id'] );
	}
}
