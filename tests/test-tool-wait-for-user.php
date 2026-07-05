<?php
/**
 * Tests for wait_for_user tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test wait_for_user tool functionality.
 */
class Test_Tool_Wait_For_User extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Wait_For_User
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool = new WP_MCP_AI_Tool_Wait_For_User();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'wait_for_user', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Tool definition structure is valid.
	 */
	public function test_tool_definition() {
		$definition = $this->tool->get_definition();

		$this->assertIsArray( $definition );
		$this->assertArrayHasKey( 'name', $definition );
		$this->assertArrayHasKey( 'description', $definition );
		$this->assertArrayHasKey( 'required_capability', $definition );
		$this->assertArrayHasKey( 'parameters', $definition );
		$this->assertSame( 'read', $definition['required_capability'] );
	}

	/**
	 * Execute returns success envelope with waiting action.
	 */
	public function test_execute_returns_waiting_signal() {
		$result = $this->tool->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'action', $result );
		$this->assertSame( 'waiting', $result['action'] );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertNotEmpty( $result['message'] );
	}

	/**
	 * Execute ignores any arguments and still returns success.
	 */
	public function test_execute_ignores_arguments() {
		$result = $this->tool->execute(
			array( 'unexpected' => 'value' ),
			array( 'user_id' => 1, 'assistant_id' => 42 )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'waiting', $result['action'] );
	}

	/**
	 * Execute never returns WP_Error (no-op tool).
	 */
	public function test_execute_never_returns_error() {
		$result = $this->tool->execute( array(), array( 'user_id' => 0 ) );

		$this->assertIsArray( $result );
		$this->assertNotWPError( $result );
	}
}
