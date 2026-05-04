<?php
/**
 * Tests for WP_MCP_AI_Slash_Command_Jobs.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the /jobs slash command.
 */
class Test_Slash_Command_Jobs extends WP_UnitTestCase {

	/**
	 * Command instance.
	 *
	 * @var WP_MCP_AI_Slash_Command_Jobs
	 */
	private $command;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-jobs.php';
		$this->command  = new WP_MCP_AI_Slash_Command_Jobs();
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );
	}

	/**
	 * Guest requests must be blocked.
	 */
	public function test_guest_request_is_blocked() {
		$result = $this->command->execute( array(), array(), array( 'guest_request' => true ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'guest_forbidden', $result->get_error_code() );
	}

	/**
	 * Subscribers (no edit_posts) must be rejected.
	 */
	public function test_capability_gate_rejects_subscriber() {
		$sub_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub_id );
		$result = $this->command->execute( array(), array(), array( 'user_id' => $sub_id ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * --list (default) returns a success array for an editor.
	 */
	public function test_list_returns_array_for_editor() {
		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * --all flag without manage_options is rejected.
	 */
	public function test_all_flag_requires_manage_options() {
		$result = $this->command->execute( array(), array( 'all' => true ), array( 'user_id' => $this->editor_id ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * --all flag works for administrator.
	 */
	public function test_all_flag_works_for_admin() {
		wp_set_current_user( $this->admin_id );
		$result = $this->command->execute( array(), array( 'all' => true ), array( 'user_id' => $this->admin_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * Invalid --status value returns WP_Error.
	 */
	public function test_invalid_status_flag_returns_error() {
		$result = $this->command->execute( array(), array( 'status' => 'invalid_status' ), array( 'user_id' => $this->editor_id ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_status', $result->get_error_code() );
	}

	/**
	 * --json flag changes output format.
	 */
	public function test_json_flag_returns_json_string() {
		$result = $this->command->execute( array(), array( 'json' => true ), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		// Message should be valid JSON when --json is used.
		$decoded = json_decode( $result['message'], true );
		$this->assertNotNull( $decoded );
	}

	/**
	 * --cancel when async job queue not available returns service error.
	 */
	public function test_cancel_when_service_unavailable() {
		if ( class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Async_Job_Queue is available; skipping unavailability test.' );
		}
		$result = $this->command->execute(
			array(),
			array( 'cancel' => 'test-job-123' ),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'service_unavailable', $result->get_error_code() );
	}

	/**
	 * Clean up.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
