<?php
/**
 * Tests for WP_MCP_AI_Slash_Command_Status.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the /status slash command.
 */
class Test_Slash_Command_Status extends WP_UnitTestCase {

	/**
	 * Command instance.
	 *
	 * @var WP_MCP_AI_Slash_Command_Status
	 */
	private $command;

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
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-status.php';
		$this->command   = new WP_MCP_AI_Slash_Command_Status();
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
	 * Happy path returns a success array with a message string.
	 */
	public function test_list_returns_success_array() {
		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertIsString( $result['message'] );
	}

	/**
	 * Response includes status indicators.
	 */
	public function test_response_contains_status_section() {
		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'System Status', $result['message'] );
	}

	/**
	 * --json flag changes output to valid JSON.
	 */
	public function test_json_flag_returns_json_string() {
		$result = $this->command->execute( array(), array( 'json' => true ), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$decoded = json_decode( $result['message'], true );
		$this->assertNotNull( $decoded );
	}

	/**
	 * Data array contains expected keys.
	 */
	public function test_data_has_expected_keys() {
		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertIsArray( $result['data'] );
		$this->assertArrayHasKey( 'async_health', $result['data'] );
		$this->assertArrayHasKey( 'job_counts', $result['data'] );
		$this->assertArrayHasKey( 'tool_registry', $result['data'] );
	}

	/**
	 * Cleanup.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
