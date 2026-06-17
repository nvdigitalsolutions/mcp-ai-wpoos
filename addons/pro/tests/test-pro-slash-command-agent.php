<?php
/**
 * Tests for WP_MCP_AI_Pro_Slash_Command_Agent.
 *
 * @package MCP_AI_WPooS
 */

/**
 * Test class for Pro slash command /agent.
 */
class Test_Pro_Slash_Command_Agent extends WP_UnitTestCase {

	/** Summary.
	 *
	 * @var WP_MCP_AI_Pro_Slash_Command_Agent
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

		if ( ! class_exists( 'WP_MCP_AI_Pro_Slash_Command_Agent' ) ) {
			require_once dirname( __DIR__ ) . '/includes/slash-commands/commands/class-wp-mcp-ai-pro-slash-command-agent.php';
		}

		$this->command   = new WP_MCP_AI_Pro_Slash_Command_Agent();
		$this->editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_delete_user( $this->editor_id );
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
	 * Test that subscriber cannot use /agent.
	 */
	public function test_capability_gate_subscriber(): void {
		$sub_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub_id );

		$result = $this->command->execute( array(), array(), array( 'user_id' => $sub_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'forbidden', $result->get_error_code() );

		wp_delete_user( $sub_id );
	}

	/**
	 * Test graceful degradation when A2A Task Manager not loaded.
	 */
	public function test_list_without_task_manager(): void {
		if ( class_exists( 'WP_MCP_AI_A2A_Task_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_A2A_Task_Manager is loaded; skipping degradation test.' );
		}

		wp_set_current_user( $this->editor_id );

		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->editor_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'service_unavailable', $result->get_error_code() );
	}

	/**
	 * Test graceful degradation when A2A Client not loaded.
	 */
	public function test_discover_without_client(): void {
		if ( class_exists( 'WP_MCP_AI_A2A_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_A2A_Client is loaded; skipping degradation test.' );
		}

		wp_set_current_user( $this->editor_id );

		$result = $this->command->execute(
			array(),
			array( 'discover' => 'https://example.com/agent' ),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'service_unavailable', $result->get_error_code() );
	}

	/**
	 * Test that --send without --message returns WP_Error.
	 */
	public function test_send_without_message(): void {
		if ( ! class_exists( 'WP_MCP_AI_A2A_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_A2A_Client not loaded.' );
		}

		wp_set_current_user( $this->editor_id );

		$result = $this->command->execute(
			array(),
			array( 'send' => 'https://example.com/agent' ),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_message', $result->get_error_code() );
	}

	/**
	 * Test that --cancel without task ID returns WP_Error.
	 */
	public function test_cancel_without_task_id(): void {
		if ( ! class_exists( 'WP_MCP_AI_A2A_Task_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_A2A_Task_Manager not loaded.' );
		}

		wp_set_current_user( $this->editor_id );

		$result = $this->command->execute(
			array(),
			array( 'cancel' => '' ),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_id', $result->get_error_code() );
	}
}
