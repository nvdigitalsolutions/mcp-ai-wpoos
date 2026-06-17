<?php
/**
 * Tests for WP_MCP_AI_Slash_Command_Session (/clear, /reset, /resume).
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the session slash commands.
 */
class Test_Slash_Command_Session extends WP_UnitTestCase {

	/**
	 * Command instance.
	 *
	 * @var WP_MCP_AI_Slash_Command_Session
	 */
	private $command;

	/**
	 * Subscriber user ID (capability: read).
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-session.php';
		$this->command       = new WP_MCP_AI_Slash_Command_Session();
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->subscriber_id );
	}

	// -----------------------------------------------------------------------
	// /clear
	// -----------------------------------------------------------------------

	/**
	 * Guest clear is blocked.
	 */
	public function test_clear_blocks_guest() {
		$result = $this->command->clear( array(), array(), array( 'guest_request' => true ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'guest_forbidden', $result->get_error_code() );
	}

	/**
	 * /clear happy path returns clear_chat action.
	 */
	public function test_clear_returns_clear_chat_action() {
		$result = $this->command->clear( array(), array(), array( 'user_id' => $this->subscriber_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'clear_chat', $result['action'] );
	}

	// -----------------------------------------------------------------------
	// /reset
	// -----------------------------------------------------------------------

	/**
	 * Guest reset is blocked.
	 */
	public function test_reset_blocks_guest() {
		$result = $this->command->reset( array(), array(), array( 'guest_request' => true ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'guest_forbidden', $result->get_error_code() );
	}

	/**
	 * /reset happy path returns reset_session action.
	 */
	public function test_reset_returns_reset_session_action() {
		$result = $this->command->reset( array(), array(), array( 'user_id' => $this->subscriber_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'reset_session', $result['action'] );
	}

	/**
	 * /reset fires the wp_mcp_ai_session_reset action.
	 */
	public function test_reset_fires_hook() {
		$fired = false;
		add_action(
			'wp_mcp_ai_session_reset',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$this->command->reset( array(), array(), array( 'user_id' => $this->subscriber_id, 'assistant_id' => 0 ) );
		$this->assertTrue( $fired );
	}

	// -----------------------------------------------------------------------
	// /resume
	// -----------------------------------------------------------------------

	/**
	 * Guest resume is blocked.
	 */
	public function test_resume_blocks_guest() {
		$result = $this->command->resume( array(), array(), array( 'guest_request' => true ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'guest_forbidden', $result->get_error_code() );
	}

	/**
	 * /resume happy path returns resume_session action.
	 */
	public function test_resume_returns_resume_session_action() {
		$result = $this->command->resume( array(), array(), array( 'user_id' => $this->subscriber_id ) );
		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'resume_session', $result['action'] );
	}

	/**
	 * Unauthenticated user (user_id = 0) is rejected from all three commands.
	 */
	public function test_unauthenticated_user_rejected() {
		wp_set_current_user( 0 );
		$context = array( 'user_id' => 0 );

		$clear  = $this->command->clear( array(), array(), $context );
		$reset  = $this->command->reset( array(), array(), $context );
		$resume = $this->command->resume( array(), array(), $context );

		$this->assertWPError( $clear );
		$this->assertWPError( $reset );
		$this->assertWPError( $resume );
	}

	/**
	 * Cleanup.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
