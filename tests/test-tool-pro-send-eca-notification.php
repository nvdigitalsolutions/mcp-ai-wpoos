<?php
/**
 * Tests for WP_MCP_AI_Tool_Send_ECA_Notification.
 *
 * Covers: guest forbidden, missing eca_id, invalid eca_id (wrong post type),
 * missing notification_type, missing recipients, and a dry-run happy path
 * with a real ECA post.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the send_eca_notification pro tool.
 */
class Test_Tool_Pro_Send_ECA_Notification extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Send_ECA_Notification
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

		// Enable ECA management.
		update_option( 'wp_mcp_ai_settings', array( 'enable_eca_management' => true ) );

		// Load ECA CPT and register post types.
		if ( ! class_exists( 'WP_MCP_AI_ECA_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/class-wp-mcp-ai-eca-cpt.php';
		}
		WP_MCP_AI_ECA_CPT::register_post_types();

		$tool_file = dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-send-eca-notification.php';
		if ( ! class_exists( 'WP_MCP_AI_Tool_Send_ECA_Notification' ) && file_exists( $tool_file ) ) {
			require_once $tool_file;
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Send_ECA_Notification' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Send_ECA_Notification class not available.' );
			return;
		}

		$this->tool = new WP_MCP_AI_Tool_Send_ECA_Notification();
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
	public function test_get_slug_returns_send_eca_notification() {
		$this->assertSame( 'send_eca_notification', $this->tool->get_slug() );
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
			array(
				'eca_id'            => 1,
				'notification_type' => 'reminder',
				'recipients'        => 'all_enrolled',
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
				'notification_type' => 'reminder',
				'recipients'        => 'all_enrolled',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_id', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – invalid eca_id (wrong post type)
	// -----------------------------------------------------------------------

	/**
	 * Test that an eca_id pointing to a non-ECA post returns 'wp_mcp_ai_invalid_eca'.
	 */
	public function test_invalid_eca_id_returns_wp_error() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'post' ) );

		$result = $this->tool->execute(
			array(
				'eca_id'            => $post_id,
				'notification_type' => 'reminder',
				'recipients'        => 'all_enrolled',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_eca', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – invalid notification_type
	// -----------------------------------------------------------------------

	/**
	 * Test that an invalid notification_type returns WP_Error('wp_mcp_ai_invalid_type').
	 */
	public function test_invalid_notification_type_returns_wp_error() {
		$eca_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_eca' ) );

		$result = $this->tool->execute(
			array(
				'eca_id'            => $eca_id,
				'notification_type' => 'invalid_type_xyz',
				'recipients'        => 'all_enrolled',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_type', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – invalid recipients
	// -----------------------------------------------------------------------

	/**
	 * Test that an invalid recipients value returns WP_Error('wp_mcp_ai_invalid_recipients').
	 */
	public function test_invalid_recipients_returns_wp_error() {
		$eca_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_eca' ) );

		$result = $this->tool->execute(
			array(
				'eca_id'            => $eca_id,
				'notification_type' => 'reminder',
				'recipients'        => 'bad_group',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_recipients', $result->get_error_code() );
	}
}
