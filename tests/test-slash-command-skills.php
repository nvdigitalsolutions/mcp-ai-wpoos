<?php
/**
 * Tests for WP_MCP_AI_Slash_Command_Skills.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the /skills slash command.
 */
class Test_Slash_Command_Skills extends WP_UnitTestCase {

	/**
	 * Command instance.
	 *
	 * @var WP_MCP_AI_Slash_Command_Skills
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
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-skills.php';
		$this->command   = new WP_MCP_AI_Slash_Command_Skills();
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
	 * Default list action returns a success array.
	 */
	public function test_list_returns_success_array() {
		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertIsArray( $result['data'] );
	}

	/**
	 * --install requires manage_options.
	 */
	public function test_install_requires_manage_options() {
		$result = $this->command->execute(
			array(),
			array( 'install' => 'my-skill' ),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * --install without registry returns service error.
	 */
	public function test_install_without_registry_returns_service_error() {
		if ( class_exists( 'WP_MCP_AI_Skill_Pack_Registry' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Skill_Pack_Registry is available.' );
		}
		wp_set_current_user( $this->admin_id );
		$result = $this->command->execute(
			array(),
			array( 'install' => 'my-skill' ),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'service_unavailable', $result->get_error_code() );
	}

	/**
	 * --json flag returns valid JSON.
	 */
	public function test_json_flag_returns_valid_json() {
		$result = $this->command->execute( array(), array( 'json' => true ), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$decoded = json_decode( $result['message'], true );
		$this->assertNotNull( $decoded );
	}

	/**
	 * Bundled skills fallback produces non-empty list.
	 */
	public function test_bundled_skills_fallback_returns_entries() {
		if ( class_exists( 'WP_MCP_AI_Skill_Pack_Registry' ) ) {
			$this->markTestSkipped( 'Registry available; bundled fallback may not be tested in isolation.' );
		}
		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		// bundled-skills directory exists; list should have at least 1 item.
		$this->assertNotEmpty( $result['data'] );
	}

	/**
	 * Cleanup.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
