<?php
/**
 * Tests for WP_MCP_AI_Tool_Import_Vitals.
 *
 * import_vitals is HIPAA-relevant.  Unlike most tools, on validation failure
 * it returns a plain PHP array (not WP_Error) with 'success' => false.
 * Tests cover: missing member_id, member_id not found (wrong post type),
 * missing data field, and a dry-run happy path with CSV format.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the import_vitals pro tool.
 */
class Test_Tool_Pro_Import_Vitals extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Import_Vitals
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

		// Load health/wellness CPT to register mcp_ai_member post type.
		if ( ! class_exists( 'WP_MCP_AI_Health_Wellness_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/class-wp-mcp-ai-health-wellness-cpt.php';
		}
		WP_MCP_AI_Health_Wellness_CPT::register_post_types();

		$tool_file = dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-import-vitals.php';
		if ( ! class_exists( 'WP_MCP_AI_Tool_Import_Vitals' ) && file_exists( $tool_file ) ) {
			require_once $tool_file;
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Import_Vitals' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Import_Vitals class not available.' );
			return;
		}

		$this->tool = new WP_MCP_AI_Tool_Import_Vitals();
	}

	// -----------------------------------------------------------------------
	// get_slug
	// -----------------------------------------------------------------------

	/**
	 * Test that get_slug returns the expected string.
	 */
	public function test_get_slug_returns_import_vitals() {
		$this->assertSame( 'import_vitals', $this->tool->get_slug() );
	}

	/**
	 * Test that get_capability_flags includes 'hipaa-relevant'.
	 */
	public function test_capability_flags_include_hipaa_relevant() {
		$flags = $this->tool->get_capability_flags();
		$this->assertIsArray( $flags );
		$this->assertContains( 'hipaa-relevant', $flags );
	}

	// -----------------------------------------------------------------------
	// execute – missing member_id
	// -----------------------------------------------------------------------

	/**
	 * Test that member_id=0 returns failure array (not WP_Error).
	 */
	public function test_missing_member_id_returns_failure_array() {
		$result = $this->tool->execute(
			array(
				'member_id' => 0,
				'data'      => "date,heart_rate\n2025-01-01,72",
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'member_id', $result['error'] );
	}

	// -----------------------------------------------------------------------
	// execute – member not found (non-existent post)
	// -----------------------------------------------------------------------

	/**
	 * Test that a member_id that does not exist returns a failure array.
	 */
	public function test_member_not_found_returns_failure_array() {
		$result = $this->tool->execute(
			array(
				'member_id' => 999999,
				'data'      => "date,heart_rate\n2025-01-01,72",
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	// -----------------------------------------------------------------------
	// execute – wrong post type for member_id
	// -----------------------------------------------------------------------

	/**
	 * Test that a post ID of the wrong type returns a failure array.
	 */
	public function test_wrong_post_type_returns_failure_array() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'post' ) );

		$result = $this->tool->execute(
			array(
				'member_id' => $post_id,
				'data'      => "date,heart_rate\n2025-01-01,72",
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	// -----------------------------------------------------------------------
	// execute – missing data field
	// -----------------------------------------------------------------------

	/**
	 * Test that an empty data field returns a failure array.
	 */
	public function test_missing_data_field_returns_failure_array() {
		$member_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_member' ) );

		$result = $this->tool->execute(
			array(
				'member_id' => $member_id,
				'data'      => '',
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	// -----------------------------------------------------------------------
	// execute – dry-run happy path with CSV
	// -----------------------------------------------------------------------

	/**
	 * Test that valid CSV with dry_run=true returns a success array.
	 */
	public function test_dry_run_with_valid_csv_returns_success() {
		$member_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_member' ) );

		$csv = "date,heart_rate\n2025-01-01,72\n";

		$result = $this->tool->execute(
			array(
				'member_id' => $member_id,
				'format'    => 'csv',
				'data'      => $csv,
				'dry_run'   => true,
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}
}
