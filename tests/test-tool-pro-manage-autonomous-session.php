<?php
/**
 * Tests for WP_MCP_AI_Pro_Tool_Manage_Autonomous_Session.
 *
 * This tool does NOT implement WP_MCP_AI_Tool_Interface.  On an unrecognised
 * action it returns an array with 'success' => false.  IMPORTANT: the tool
 * accesses $arguments['action'] directly without a null-check, so the 'action'
 * key MUST be present in arguments; tests always include it.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the manage_autonomous_session pro tool.
 */
class Test_Tool_Pro_Manage_Autonomous_Session extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Pro_Tool_Manage_Autonomous_Session
	 */
	private $tool;

	/**
	 * Admin user ID used across tests.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Manage_Autonomous_Session' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-manage-autonomous-session.php';
		}

		$this->tool = new WP_MCP_AI_Pro_Tool_Manage_Autonomous_Session();
	}

	// -----------------------------------------------------------------------
	// get_slug / get_definition
	// -----------------------------------------------------------------------

	/**
	 * Test that get_slug returns the expected string.
	 */
	public function test_get_slug_returns_manage_autonomous_session() {
		$this->assertSame( 'manage_autonomous_session', $this->tool->get_slug() );
	}

	/**
	 * Test that get_definition returns required keys.
	 */
	public function test_get_definition_returns_required_keys() {
		$def = $this->tool->get_definition();

		$this->assertArrayHasKey( 'name', $def );
		$this->assertArrayHasKey( 'description', $def );
		$this->assertSame( 'manage_autonomous_session', $def['name'] );
	}

	// -----------------------------------------------------------------------
	// execute – invalid action
	// -----------------------------------------------------------------------

	/**
	 * Test that an unrecognised action returns a failure array.
	 */
	public function test_invalid_action_returns_failure_array() {
		$result = $this->tool->execute(
			array( 'action' => 'fly_to_mars' ),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Unknown action', $result['error'] );
	}

	// -----------------------------------------------------------------------
	// execute – start without plan_id
	// -----------------------------------------------------------------------

	/**
	 * Test that starting a session without plan_id returns a failure array.
	 */
	public function test_start_missing_plan_id_returns_failure_array() {
		$result = $this->tool->execute(
			array( 'action' => 'start' ),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'plan_id', $result['error'] );
	}

	// -----------------------------------------------------------------------
	// execute – pause without session_id
	// -----------------------------------------------------------------------

	/**
	 * Test that pausing without a session_id returns a failure array.
	 */
	public function test_pause_missing_session_id_returns_failure_array() {
		$result = $this->tool->execute(
			array( 'action' => 'pause' ),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	// -----------------------------------------------------------------------
	// execute – stop without session_id
	// -----------------------------------------------------------------------

	/**
	 * Test that stopping without a session_id returns a failure array.
	 */
	public function test_stop_missing_session_id_returns_failure_array() {
		$result = $this->tool->execute(
			array( 'action' => 'stop' ),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	// -----------------------------------------------------------------------
	// execute – start happy path
	// -----------------------------------------------------------------------

	/**
	 * Test that starting a session with a valid plan_id returns a session_id.
	 */
	public function test_start_with_plan_id_returns_session_id() {
		$result = $this->tool->execute(
			array(
				'action'  => 'start',
				'plan_id' => 42,
			),
			array()
		);

		$this->assertIsArray( $result );
		// Success or stored: should have a session_id key.
		$this->assertArrayHasKey( 'session_id', $result );
		$this->assertNotEmpty( $result['session_id'] );
	}
}
