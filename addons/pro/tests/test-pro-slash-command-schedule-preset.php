<?php
/**
 * Tests for WP_MCP_AI_Pro_Slash_Command_Schedule_Preset.
 *
 * @package MCP_AI_WPooS
 */

/**
 * Test class for Pro slash command /schedule-preset.
 */
class Test_Pro_Slash_Command_Schedule_Preset extends WP_UnitTestCase {

	/** Summary.
	 *
	 * @var WP_MCP_AI_Pro_Slash_Command_Schedule_Preset
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

		if ( ! class_exists( 'WP_MCP_AI_Pro_Slash_Command_Schedule_Preset' ) ) {
			require_once dirname( __DIR__ ) . '/includes/slash-commands/commands/class-wp-mcp-ai-pro-slash-command-schedule-preset.php';
		}

		$this->command   = new WP_MCP_AI_Pro_Slash_Command_Schedule_Preset();
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
	 * Test that subscriber cannot browse presets.
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
	 * Test graceful degradation when service class is missing.
	 */
	public function test_missing_service_class(): void {
		if ( class_exists( 'WP_MCP_AI_Pro_Schedule_Presets' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Schedule_Presets is loaded; skipping degradation test.' );
		}

		wp_set_current_user( $this->editor_id );

		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->editor_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'service_unavailable', $result->get_error_code() );
	}

	/**
	 * Test that install requires manage_options.
	 */
	public function test_install_requires_manage_options(): void {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Presets' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Schedule_Presets not loaded.' );
		}

		wp_set_current_user( $this->editor_id );

		$result = $this->command->execute(
			array(),
			array( 'install' => 'some-preset' ),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'forbidden', $result->get_error_code() );
	}

	/**
	 * Test --show with an empty ID returns WP_Error.
	 */
	public function test_show_empty_id_returns_error(): void {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Presets' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Schedule_Presets not loaded.' );
		}

		wp_set_current_user( $this->editor_id );

		$result = $this->command->execute(
			array(),
			array( 'show' => '' ),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_id', $result->get_error_code() );
	}
}
