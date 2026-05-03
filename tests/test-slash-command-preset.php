<?php
/**
 * Tests for WP_MCP_AI_Slash_Command_Preset.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the /preset slash command.
 */
class Test_Slash_Command_Preset extends WP_UnitTestCase {

	/**
	 * Command instance.
	 *
	 * @var WP_MCP_AI_Slash_Command_Preset
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
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-preset.php';
		$this->command   = new WP_MCP_AI_Slash_Command_Preset();
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
	 * Graceful degradation when preset service is not available.
	 */
	public function test_graceful_degradation_when_service_unavailable() {
		if ( class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Orchestration_Preset_Service is available.' );
		}
		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * --list returns a success array when service is available.
	 */
	public function test_list_returns_success_array_when_service_available() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Orchestration_Preset_Service is not available.' );
		}
		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * --apply requires manage_options.
	 */
	public function test_apply_requires_manage_options() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Orchestration_Preset_Service is not available.' );
		}
		$result = $this->command->execute(
			array(),
			array( 'apply' => 'preset-1' ),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * --json flag returns valid JSON when service is available.
	 */
	public function test_json_flag_returns_valid_json() {
		if ( ! class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Orchestration_Preset_Service is not available.' );
		}
		$result = $this->command->execute( array(), array( 'json' => true ), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$decoded = json_decode( $result['message'], true );
		$this->assertNotNull( $decoded );
	}

	/**
	 * Cleanup.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
