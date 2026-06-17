<?php
/**
 * Tests for WP_MCP_AI_Tool_Import_ECAs_CSV.
 *
 * Covers: guest forbidden, missing csv content AND file_url, and a happy-path
 * dry-run import with minimal CSV content.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the import_ecas_csv pro tool.
 */
class Test_Tool_Pro_Import_ECAs_CSV extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Import_ECAs_CSV
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

		if ( ! class_exists( 'WP_MCP_AI_Tool_Import_ECAs_CSV' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/eca-management/class-wp-mcp-ai-tool-import-ecas-csv.php';
		}

		$this->tool = new WP_MCP_AI_Tool_Import_ECAs_CSV();
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
	public function test_get_slug_returns_import_ecas_csv() {
		$this->assertSame( 'import_ecas_csv', $this->tool->get_slug() );
	}

	// -----------------------------------------------------------------------
	// execute – guest user
	// -----------------------------------------------------------------------

	/**
	 * Test that user_id=0 returns WP_Error('wp_mcp_ai_forbidden').
	 */
	public function test_guest_returns_forbidden() {
		$result = $this->tool->execute(
			array( 'csv_content' => "name\nChess Club" ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – neither csv_content nor file_url provided
	// -----------------------------------------------------------------------

	/**
	 * Test that omitting both csv_content and file_url returns WP_Error('wp_mcp_ai_missing_csv').
	 */
	public function test_missing_csv_content_and_file_url_returns_wp_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_csv', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – dry-run happy path
	// -----------------------------------------------------------------------

	/**
	 * Test that valid CSV content with dry_run returns a success array.
	 */
	public function test_csv_dry_run_returns_success_array() {
		$csv = "name,eca_code,description\nChess Club,CHESS-001,Learn chess\n";

		$result = $this->tool->execute(
			array(
				'csv_content' => $csv,
				'dry_run'     => true,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		// In dry-run mode the tool should not return a WP_Error.
		$this->assertNotInstanceOf( WP_Error::class, $result );
	}

	// -----------------------------------------------------------------------
	// execute – get_parameters_schema structure
	// -----------------------------------------------------------------------

	/**
	 * Test that get_parameters_schema declares both csv_content and file_url.
	 */
	public function test_schema_declares_csv_content_and_file_url() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'csv_content', $schema['properties'] );
		$this->assertArrayHasKey( 'file_url', $schema['properties'] );
	}
}
