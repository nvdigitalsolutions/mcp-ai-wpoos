<?php
/**
 * Tests for WP_MCP_AI_Slash_Command_Diagnose.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the /diagnose slash command.
 */
class Test_Slash_Command_Diagnose extends WP_UnitTestCase {

	/**
	 * Command instance.
	 *
	 * @var WP_MCP_AI_Slash_Command_Diagnose
	 */
	private $command;

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
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-diagnose.php';
		$this->command  = new WP_MCP_AI_Slash_Command_Diagnose();
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
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
	 * Editors (no manage_options) must be rejected.
	 */
	public function test_capability_gate_rejects_editor() {
		$editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );
		$result = $this->command->execute( array(), array(), array( 'user_id' => $editor_id ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * Happy path returns a success array with a diagnostic block.
	 */
	public function test_returns_diagnostic_block() {
		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->admin_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertStringContainsString( 'diagnostic', $result['message'] );
	}

	/**
	 * Response includes plugin version information.
	 */
	public function test_response_includes_version_info() {
		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->admin_id ) );
		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Plugin version', $result['message'] );
		$this->assertStringContainsString( 'PHP', $result['message'] );
	}

	/**
	 * --json flag returns machine-readable JSON.
	 */
	public function test_json_flag_returns_valid_json() {
		$result = $this->command->execute( array(), array( 'json' => true ), array( 'user_id' => $this->admin_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$decoded = json_decode( $result['message'], true );
		$this->assertNotNull( $decoded );
		$this->assertIsArray( $decoded );
	}

	/**
	 * Data array contains expected diagnostic keys.
	 */
	public function test_data_has_expected_keys() {
		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->admin_id ) );
		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'data', $result );
		$data = $result['data'];
		$this->assertArrayHasKey( 'plugin_version', $data );
		$this->assertArrayHasKey( 'wp_version', $data );
		$this->assertArrayHasKey( 'php_version', $data );
		$this->assertArrayHasKey( 'recent_errors', $data );
		$this->assertArrayHasKey( 'recent_activity', $data );
	}

	/**
	 * Recent errors and activity are arrays (even when empty).
	 */
	public function test_recent_errors_is_array() {
		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->admin_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result['data']['recent_errors'] );
		$this->assertIsArray( $result['data']['recent_activity'] );
	}

	/**
	 * Cleanup.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
