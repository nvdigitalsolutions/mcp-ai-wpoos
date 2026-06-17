<?php
/**
 * Tests for WP_MCP_AI_Pro_Slash_Command_Run.
 *
 * @package MCP_AI_WPooS
 */

/**
 * Test class for Pro slash command /run.
 */
class Test_Pro_Slash_Command_Run extends WP_UnitTestCase {

	/** Summary.
	 *
	 * @var WP_MCP_AI_Pro_Slash_Command_Run
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

		if ( ! class_exists( 'WP_MCP_AI_Pro_Slash_Command_Run' ) ) {
			require_once dirname( __DIR__ ) . '/includes/slash-commands/commands/class-wp-mcp-ai-pro-slash-command-run.php';
		}

		$this->command   = new WP_MCP_AI_Pro_Slash_Command_Run();
		$this->editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_delete_user( $this->editor_id );
		delete_option( 'wp_mcp_ai_pro_workflows' );
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
	 * Test that subscriber cannot run workflows.
	 */
	public function test_capability_gate_subscriber(): void {
		$sub_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub_id );

		$result = $this->command->execute( array( 'my-workflow' ), array(), array( 'user_id' => $sub_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'forbidden', $result->get_error_code() );

		wp_delete_user( $sub_id );
	}

	/**
	 * Test --list returns string or array (no WP_Error) even when no workflows exist.
	 */
	public function test_list_with_no_workflows(): void {
		wp_set_current_user( $this->editor_id );
		delete_option( 'wp_mcp_ai_pro_workflows' );

		$result = $this->command->execute(
			array(),
			array( 'list' => true ),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertFalse( is_wp_error( $result ) );
	}

	/**
	 * Test that a missing workflow returns WP_Error.
	 */
	public function test_missing_workflow_returns_error(): void {
		wp_set_current_user( $this->editor_id );
		update_option( 'wp_mcp_ai_pro_workflows', array() );

		$result = $this->command->execute(
			array( 'nonexistent-workflow' ),
			array(),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'not_found', $result->get_error_code() );
	}

	/**
	 * Test dry-run with a real workflow returns preview (no execution).
	 */
	public function test_dry_run_returns_preview(): void {
		wp_set_current_user( $this->editor_id );

		$wf_id = 'test-wf-1';
		update_option(
			'wp_mcp_ai_pro_workflows',
			array(
				$wf_id => array(
					'name'  => 'Test Workflow',
					'nodes' => array( array( 'id' => 'n1' ), array( 'id' => 'n2' ) ),
					'edges' => array( array( 'id' => 'e1' ) ),
				),
			)
		);

		$action_fired = false;
		add_action(
			'wp_mcp_ai_run_workflow_builder',
			function () use ( &$action_fired ) {
				$action_fired = true;
			}
		);

		$result = $this->command->execute(
			array( $wf_id ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertFalse( $action_fired, 'Action should NOT fire during dry-run.' );
	}

	/**
	 * Test happy-path run fires the workflow action.
	 */
	public function test_run_fires_action(): void {
		wp_set_current_user( $this->editor_id );

		$wf_id = 'test-wf-2';
		update_option(
			'wp_mcp_ai_pro_workflows',
			array(
				$wf_id => array(
					'name'  => 'My Flow',
					'nodes' => array(),
					'edges' => array(),
				),
			)
		);

		$fired_id = null;
		add_action(
			'wp_mcp_ai_run_workflow_builder',
			function ( $id ) use ( &$fired_id ) {
				$fired_id = $id;
			}
		);

		$result = $this->command->execute(
			array( $wf_id ),
			array(),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertEquals( $wf_id, $fired_id );
	}
}
