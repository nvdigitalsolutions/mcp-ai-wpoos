<?php
/**
 * Tests for ECA sync from iSAMS tool.
 *
 * @package WP_MCP_AI
 */

/**
 * Test ECA sync from iSAMS functionality.
 */
class Test_ECA_Sync_From_ISAMS extends WP_UnitTestCase {
	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user.
		$this->admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user );

		// Enable ECA management.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_eca_management' => true,
				'isams_api_url'         => 'https://example.isams.com/api',
				'isams_api_key'         => 'test_key',
			)
		);

		// Load ECA CPT class.
		if ( ! class_exists( 'WP_MCP_AI_ECA_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-eca-cpt.php';
		}

		// Register post types.
		WP_MCP_AI_ECA_CPT::register_post_types();

		// Load the sync tool.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Sync_ECAs_From_ISAMS' ) ) {
			require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-sync-ecas-from-isams.php';
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that tool is available when properly configured.
	 */
	public function test_tool_available_when_configured() {
		$this->assertTrue( WP_MCP_AI_Tool_Sync_ECAs_From_ISAMS::is_available() );
	}

	/**
	 * Test that tool is not available without iSAMS credentials.
	 */
	public function test_tool_unavailable_without_credentials() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_eca_management' => true,
			)
		);

		$this->assertFalse( WP_MCP_AI_Tool_Sync_ECAs_From_ISAMS::is_available() );
	}

	/**
	 * Test that tool is not available when ECA management is disabled.
	 */
	public function test_tool_unavailable_when_eca_disabled() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'isams_api_url' => 'https://example.isams.com/api',
				'isams_api_key' => 'test_key',
			)
		);

		$this->assertFalse( WP_MCP_AI_Tool_Sync_ECAs_From_ISAMS::is_available() );
	}

	/**
	 * Test tool parameters schema.
	 */
	public function test_parameters_schema() {
		$tool   = new WP_MCP_AI_Tool_Sync_ECAs_From_ISAMS();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'sync_type', $schema['properties'] );
		$this->assertArrayHasKey( 'eca_id', $schema['properties'] );
		$this->assertArrayHasKey( 'page', $schema['properties'] );
		$this->assertArrayHasKey( 'limit', $schema['properties'] );
		$this->assertArrayHasKey( 'update_existing', $schema['properties'] );

		// Verify sync_type enum.
		$this->assertArrayHasKey( 'enum', $schema['properties']['sync_type'] );
		$this->assertContains( 'single', $schema['properties']['sync_type']['enum'] );
		$this->assertContains( 'all', $schema['properties']['sync_type']['enum'] );
	}

	/**
	 * Test tool requires admin permissions.
	 */
	public function test_requires_admin_permissions() {
		// Create regular user.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$tool   = new WP_MCP_AI_Tool_Sync_ECAs_From_ISAMS();
		$result = $tool->execute(
			array( 'sync_type' => 'all' ),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test invalid sync type returns error.
	 */
	public function test_invalid_sync_type_returns_error() {
		$tool   = new WP_MCP_AI_Tool_Sync_ECAs_From_ISAMS();
		$result = $tool->execute(
			array( 'sync_type' => 'invalid' ),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_sync_type', $result->get_error_code() );
	}

	/**
	 * Test single sync requires eca_id.
	 */
	public function test_single_sync_requires_eca_id() {
		// Mock the iSAMS tool to avoid actual API calls.
		// In a real test, you'd mock the ISAMS_Query tool response.

		$tool   = new WP_MCP_AI_Tool_Sync_ECAs_From_ISAMS();
		$result = $tool->execute(
			array( 'sync_type' => 'single' ),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_missing_eca_id', $result->get_error_code() );
	}

	/**
	 * Test tool slug matches expected format.
	 */
	public function test_tool_slug() {
		$tool = new WP_MCP_AI_Tool_Sync_ECAs_From_ISAMS();
		$this->assertEquals( 'sync_ecas_from_isams', $tool->get_slug() );
	}

	/**
	 * Test tool has proper capability flags.
	 */
	public function test_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Sync_ECAs_From_ISAMS();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'database-write', $flags );
	}
}
