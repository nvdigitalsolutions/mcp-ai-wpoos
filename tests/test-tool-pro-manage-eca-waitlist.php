<?php
/**
 * Tests for WP_MCP_AI_Tool_Manage_ECA_Waitlist.
 *
 * Covers: guest forbidden, missing eca_id/action params, invalid eca_id,
 * invalid action value, and the 'list' action happy path.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the manage_eca_waitlist pro tool.
 */
class Test_Tool_Pro_Manage_ECA_Waitlist extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Manage_ECA_Waitlist
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

		$tool_file = dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-manage-eca-waitlist.php';
		if ( ! class_exists( 'WP_MCP_AI_Tool_Manage_ECA_Waitlist' ) && file_exists( $tool_file ) ) {
			require_once $tool_file;
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Manage_ECA_Waitlist' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Manage_ECA_Waitlist class not available.' );
			return;
		}

		$this->tool = new WP_MCP_AI_Tool_Manage_ECA_Waitlist();
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
	public function test_get_slug_returns_manage_eca_waitlist() {
		$this->assertSame( 'manage_eca_waitlist', $this->tool->get_slug() );
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
				'eca_id' => 1,
				'action' => 'list',
			),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – missing eca_id and action
	// -----------------------------------------------------------------------

	/**
	 * Test that missing both eca_id and action returns WP_Error('wp_mcp_ai_missing_params').
	 */
	public function test_missing_params_returns_wp_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_params', $result->get_error_code() );
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
				'eca_id' => $post_id,
				'action' => 'list',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_eca', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – invalid action
	// -----------------------------------------------------------------------

	/**
	 * Test that an invalid action value returns WP_Error('wp_mcp_ai_invalid_action').
	 */
	public function test_invalid_action_returns_wp_error() {
		$eca_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_eca' ) );

		$result = $this->tool->execute(
			array(
				'eca_id' => $eca_id,
				'action' => 'do_the_hokey_cokey',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_action', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – list happy path (empty waitlist)
	// -----------------------------------------------------------------------

	/**
	 * Test that the 'list' action returns a success array for an ECA with no waitlist.
	 */
	public function test_list_action_returns_success_for_empty_waitlist() {
		$eca_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_eca' ) );

		$result = $this->tool->execute(
			array(
				'eca_id' => $eca_id,
				'action' => 'list',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertArrayHasKey( 'waitlist', $result );
	}
}
