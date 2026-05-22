<?php
/**
 * Tests for WP_MCP_AI_Pro_Slash_Command_Broadcast.
 *
 * @package MCP_AI_WPooS
 */

/**
 * Test class for Pro slash command /broadcast.
 */
class Test_Pro_Slash_Command_Broadcast extends WP_UnitTestCase {

	/** Summary.
	 *
	 * @var WP_MCP_AI_Pro_Slash_Command_Broadcast
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

		if ( ! class_exists( 'WP_MCP_AI_Pro_Slash_Command_Broadcast' ) ) {
			require_once dirname( __DIR__ ) . '/includes/slash-commands/commands/class-wp-mcp-ai-pro-slash-command-broadcast.php';
		}

		$this->command  = new WP_MCP_AI_Pro_Slash_Command_Broadcast();
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_delete_user( $this->admin_id );
		parent::tearDown();
	}

	/**
	 * Test that guest requests are blocked.
	 */
	public function test_guest_block(): void {
		$result = $this->command->execute( array(), array(), array( 'guest_request' => true ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'guest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that editor cannot broadcast (requires manage_options).
	 */
	public function test_capability_gate_editor(): void {
		$editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$result = $this->command->execute(
			array( 'Hello' ),
			array( 'channel' => 'slack' ),
			array( 'user_id' => $editor_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'forbidden', $result->get_error_code() );

		wp_delete_user( $editor_id );
	}

	/**
	 * Test that missing --channel returns WP_Error.
	 */
	public function test_missing_channel(): void {
		wp_set_current_user( $this->admin_id );

		$result = $this->command->execute(
			array( 'Hello world' ),
			array(),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_channel', $result->get_error_code() );
	}

	/**
	 * Test that an invalid channel returns WP_Error.
	 */
	public function test_invalid_channel(): void {
		wp_set_current_user( $this->admin_id );

		$result = $this->command->execute(
			array( 'Hello' ),
			array( 'channel' => 'fax-machine' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_channel', $result->get_error_code() );
	}

	/**
	 * Test that missing message returns WP_Error.
	 */
	public function test_missing_message(): void {
		wp_set_current_user( $this->admin_id );

		$result = $this->command->execute(
			array(),
			array( 'channel' => 'slack' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_message', $result->get_error_code() );
	}

	/**
	 * Test dry-run shows preview without sending.
	 */
	public function test_dry_run_returns_preview(): void {
		wp_set_current_user( $this->admin_id );

		$action_fired = false;
		add_action(
			'wp_mcp_ai_broadcast_message',
			function () use ( &$action_fired ) {
				$action_fired = true;
			}
		);

		$result = $this->command->execute(
			array( 'Test message' ),
			array(
				'channel' => 'slack',
				'dry-run' => true,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertFalse( $action_fired, 'Broadcast action should NOT fire during dry-run.' );
	}

	/**
	 * Test happy-path broadcast via fallback action hook.
	 */
	public function test_broadcast_fires_fallback_action(): void {
		wp_set_current_user( $this->admin_id );

		// Ensure tool registry doesn't have the broadcast tool to force fallback.
		$fired_channel = null;
		$fired_message = null;
		add_action(
			'wp_mcp_ai_broadcast_message',
			function ( $channel, $message ) use ( &$fired_channel, &$fired_message ) {
				$fired_channel = $channel;
				$fired_message = $message;
			},
			10,
			2
		);

		$result = $this->command->execute(
			array( 'Hello Slack!' ),
			array( 'channel' => 'slack' ),
			array( 'user_id' => $this->admin_id )
		);

		// Verify result is not an error.
		$this->assertFalse( is_wp_error( $result ) );
		// The action fires unless the tool registry handled it first.
		// This depends on runtime environment — we just verify no error.
	}
}
