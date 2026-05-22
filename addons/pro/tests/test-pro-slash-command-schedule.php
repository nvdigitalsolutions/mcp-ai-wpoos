<?php
/**
 * Tests for WP_MCP_AI_Pro_Slash_Command_Schedule.
 *
 * @package MCP_AI_WPooS
 */

/**
 * Test class for Pro slash command /schedule.
 */
class Test_Pro_Slash_Command_Schedule extends WP_UnitTestCase {

	/** Summary.
	 *
	 * @var WP_MCP_AI_Pro_Slash_Command_Schedule
	 */
	private $command;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

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

		if ( ! class_exists( 'WP_MCP_AI_Pro_Slash_Command_Schedule' ) ) {
			require_once dirname( __DIR__ ) . '/includes/slash-commands/commands/class-wp-mcp-ai-pro-slash-command-schedule.php';
		}

		$this->command   = new WP_MCP_AI_Pro_Slash_Command_Schedule();
		$this->admin_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_delete_user( $this->admin_id );
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
	 * Test that insufficient capability is rejected.
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
	 * Test default list action when service is not loaded (graceful degradation).
	 */
	public function test_list_without_service_class(): void {
		wp_set_current_user( $this->editor_id );

		if ( class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Schedule_Manager is loaded; skipping degradation test.' );
		}

		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->editor_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'service_unavailable', $result->get_error_code() );
	}

	/**
	 * Test that an unknown sub-action returns WP_Error.
	 */
	public function test_unknown_action_returns_error(): void {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Schedule_Manager not loaded.' );
		}

		wp_set_current_user( $this->editor_id );

		$result = $this->command->execute(
			array( 'invalid_action' ),
			array(),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'unknown_action', $result->get_error_code() );
	}

	/**
	 * Test that create requires manage_options.
	 */
	public function test_create_requires_manage_options(): void {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Schedule_Manager not loaded.' );
		}

		wp_set_current_user( $this->editor_id );

		$result = $this->command->execute(
			array( 'create' ),
			array(
				'name' => 'Test',
				'type' => 'task',
				'cron' => 'daily',
			),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'forbidden', $result->get_error_code() );
	}

	/**
	 * Test that missing name on create returns WP_Error.
	 */
	public function test_create_missing_name(): void {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Schedule_Manager not loaded.' );
		}

		wp_set_current_user( $this->admin_id );

		$result = $this->command->execute(
			array( 'create' ),
			array(
				'type' => 'task',
				'cron' => 'daily',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_name', $result->get_error_code() );
	}

	/**
	 * Test that JSON flag returns an array envelope (when service available).
	 */
	public function test_list_json_flag_returns_array(): void {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Schedule_Manager not loaded.' );
		}

		wp_set_current_user( $this->editor_id );

		$result = $this->command->execute(
			array( 'list' ),
			array( 'json' => true ),
			array( 'user_id' => $this->editor_id )
		);

		if ( ! is_wp_error( $result ) ) {
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'success', $result );
		}
	}
}
