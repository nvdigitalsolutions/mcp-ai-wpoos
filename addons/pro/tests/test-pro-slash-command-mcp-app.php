<?php
/**
 * Tests for WP_MCP_AI_Pro_Slash_Command_Mcp_App.
 *
 * @package MCP_AI_WPooS
 */

/**
 * Test class for Pro slash command /mcp-app.
 */
class Test_Pro_Slash_Command_Mcp_App extends WP_UnitTestCase {

	/** Summary.
	 *
	 * @var WP_MCP_AI_Pro_Slash_Command_Mcp_App
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

		if ( ! class_exists( 'WP_MCP_AI_Pro_Slash_Command_Mcp_App' ) ) {
			require_once dirname( __DIR__ ) . '/includes/slash-commands/commands/class-wp-mcp-ai-pro-slash-command-mcp-app.php';
		}

		$this->command  = new WP_MCP_AI_Pro_Slash_Command_Mcp_App();
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
	 * Test that editor cannot use /mcp-app (requires manage_options).
	 */
	public function test_capability_gate_editor(): void {
		$editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$result = $this->command->execute( array(), array(), array( 'user_id' => $editor_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'forbidden', $result->get_error_code() );

		wp_delete_user( $editor_id );
	}

	/**
	 * Test graceful degradation when MCP App Registry not loaded.
	 */
	public function test_missing_registry(): void {
		if ( class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_MCP_App_Registry is loaded; skipping degradation test.' );
		}

		wp_set_current_user( $this->admin_id );

		$result = $this->command->execute( array(), array(), array( 'user_id' => $this->admin_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'service_unavailable', $result->get_error_code() );
	}

	/**
	 * Test that --test without a known label returns WP_Error.
	 */
	public function test_test_with_unknown_label(): void {
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_MCP_App_Registry not loaded.' );
		}

		wp_set_current_user( $this->admin_id );

		$result = $this->command->execute(
			array(),
			array( 'test' => 'nonexistent-app-label' ),
			array(
				'user_id'      => $this->admin_id,
				'assistant_id' => 0,
			)
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'not_found', $result->get_error_code() );
	}

	/**
	 * Test default list action returns a result (not WP_Error) when registry available.
	 */
	public function test_list_with_registry(): void {
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_MCP_App_Registry not loaded.' );
		}

		wp_set_current_user( $this->admin_id );

		$result = $this->command->execute(
			array(),
			array(),
			array(
				'user_id'      => $this->admin_id,
				'assistant_id' => 0,
			)
		);

		$this->assertFalse( is_wp_error( $result ) );
	}
}
