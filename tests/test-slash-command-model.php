<?php
/**
 * Tests for WP_MCP_AI_Slash_Command_Model.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the /model slash command.
 */
class Test_Slash_Command_Model extends WP_UnitTestCase {

	/**
	 * Command instance.
	 *
	 * @var WP_MCP_AI_Slash_Command_Model
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
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-model.php';
		$this->command   = new WP_MCP_AI_Slash_Command_Model();
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
	 * --list returns a success array.
	 */
	public function test_list_returns_success_array() {
		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * --set without manage_options is rejected.
	 */
	public function test_set_requires_manage_options() {
		$result = $this->command->execute(
			array(),
			array( 'set' => 'gpt-4o', 'assistant-id' => 1 ),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * --set without assistant-id returns missing_assistant error.
	 */
	public function test_set_without_assistant_id_returns_error() {
		wp_set_current_user( $this->admin_id );
		$result = $this->command->execute(
			array(),
			array( 'set' => 'gpt-4o' ),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'missing_assistant', $result->get_error_code() );
	}

	/**
	 * --set with invalid assistant ID returns invalid_assistant error.
	 */
	public function test_set_with_invalid_assistant_returns_error() {
		wp_set_current_user( $this->admin_id );
		$result = $this->command->execute(
			array(),
			array( 'set' => 'gpt-4o', 'assistant-id' => 9999999 ),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_assistant', $result->get_error_code() );
	}

	/**
	 * --current with a valid assistant returns the stored model.
	 */
	public function test_current_returns_stored_model() {
		// Create a dummy assistant post.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_assistant',
				'post_title' => 'Test Assistant',
			)
		);
		update_post_meta( $assistant_id, '_wp_mcp_ai_model', 'claude-3-opus' );

		$result = $this->command->execute(
			array(),
			array( 'current' => true, 'assistant-id' => $assistant_id ),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'claude-3-opus', $result['data']['model'] );
	}

	/**
	 * --json flag returns valid JSON.
	 */
	public function test_json_flag_returns_valid_json() {
		$result = $this->command->execute( array(), array( 'json' => true ), array( 'user_id' => $this->editor_id ) );
		$this->assertNotWPError( $result );
		$decoded = json_decode( $result['message'], true );
		$this->assertNotNull( $decoded );
	}

	/**
	 * --discover without manage_options is rejected.
	 */
	public function test_discover_requires_manage_options() {
		$result = $this->command->execute(
			array(),
			array( 'discover' => true ),
			array( 'user_id' => $this->editor_id )
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * Cleanup.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
