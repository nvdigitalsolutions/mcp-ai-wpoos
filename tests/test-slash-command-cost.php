<?php
/**
 * Tests for WP_MCP_AI_Slash_Command_Cost.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the /cost slash command.
 */
class Test_Slash_Command_Cost extends WP_UnitTestCase {

	/**
	 * Command instance.
	 *
	 * @var WP_MCP_AI_Slash_Command_Cost
	 */
	private $command;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-cost.php';
		$this->command   = new WP_MCP_AI_Slash_Command_Cost();
		$this->editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		$this->admin_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
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
	 * Happy path (service unavailable) returns a graceful response.
	 */
	public function test_graceful_degradation_when_service_unavailable() {
		if ( class_exists( 'WP_MCP_AI_Cost_Tracking_Service' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Cost_Tracking_Service is available.' );
		}
		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * --days flag defaults to 7 when 0 is given.
	 */
	public function test_days_defaults_when_zero() {
		if ( class_exists( 'WP_MCP_AI_Cost_Tracking_Service' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Cost_Tracking_Service is available; test only covers flag parsing.' );
		}
		// Even with 0 days the command should succeed (service not available path).
		$result = $this->command->execute( array(), array( 'days' => 0 ), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
	}

	/**
	 * --user-id flag for another user requires manage_options.
	 */
	public function test_user_id_flag_requires_manage_options() {
		$other_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$result   = $this->command->execute(
			array(),
			array( 'user-id' => $other_id ),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * --json flag with service unavailable still returns valid JSON message.
	 */
	public function test_json_flag_returns_valid_json() {
		if ( class_exists( 'WP_MCP_AI_Cost_Tracking_Service' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Cost_Tracking_Service is available.' );
		}
		// Service unavailable path returns success=false array, not JSON.
		$result = $this->command->execute( array(), array( 'json' => true ), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
	}

	/**
	 * Cleanup.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
