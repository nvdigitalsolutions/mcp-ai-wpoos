<?php
/**
 * Tests for WP_MCP_AI_Slash_Command_Tools.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the /tools slash command.
 */
class Test_Slash_Command_Tools extends WP_UnitTestCase {

	/**
	 * Command instance.
	 *
	 * @var WP_MCP_AI_Slash_Command_Tools
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
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-tools.php';
		$this->command   = new WP_MCP_AI_Slash_Command_Tools();
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
	 * Happy path when registry is available returns success array.
	 */
	public function test_list_returns_success_array_when_registry_available() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Registry is not available.' );
		}
		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * Graceful degradation when registry is not available.
	 */
	public function test_graceful_degradation_when_registry_unavailable() {
		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Registry is available.' );
		}
		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * --json flag returns valid JSON when registry is available.
	 */
	public function test_json_flag_returns_valid_json() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Registry is not available.' );
		}
		$result = $this->command->execute( array(), array( 'json' => true ), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$decoded = json_decode( $result['message'], true );
		$this->assertNotNull( $decoded );
	}

	/**
	 * --show with nonexistent slug returns WP_Error.
	 */
	public function test_show_nonexistent_slug_returns_error() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Registry is not available.' );
		}
		$result = $this->command->execute(
			array(),
			array( 'show' => 'nonexistent_tool_xyz_999' ),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'tool_not_found', $result->get_error_code() );
	}

	/**
	 * Cleanup.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
