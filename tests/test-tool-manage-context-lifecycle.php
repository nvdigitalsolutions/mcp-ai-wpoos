<?php
/**
 * Tests for manage_context_lifecycle tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test manage_context_lifecycle tool — controls memory refresh/prune/merge.
 */
class Test_Tool_Manage_Context_Lifecycle extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Manage_Context_Lifecycle
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool = new WP_MCP_AI_Tool_Manage_Context_Lifecycle();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'manage_context_lifecycle', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Missing action returns success=false with message.
	 */
	public function test_missing_action_returns_error_result() {
		$result = $this->tool->execute(
			array( 'agent_id' => 'agent-1' ),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Action', $result['message'] );
	}

	/**
	 * Missing agent_id returns success=false with message.
	 */
	public function test_missing_agent_id_returns_error_result() {
		$result = $this->tool->execute(
			array( 'action' => 'refresh' ),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Agent ID', $result['message'] );
	}

	/**
	 * Unknown action returns success=false with 'Invalid action' message.
	 */
	public function test_unknown_action_returns_invalid_action() {
		$result = $this->tool->execute(
			array(
				'action'   => 'unknown_action_' . uniqid(),
				'agent_id' => 'agent-1',
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid action', $result['message'] );
	}

	/**
	 * 'analyze' action for an unknown agent returns graceful result.
	 */
	public function test_analyze_action_returns_result() {
		$result = $this->tool->execute(
			array(
				'action'   => 'analyze',
				'agent_id' => 'phpunit-test-agent-' . uniqid(),
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
	}

	/**
	 * 'prune' action for an unknown agent returns graceful result.
	 */
	public function test_prune_action_returns_result() {
		$result = $this->tool->execute(
			array(
				'action'   => 'prune',
				'agent_id' => 'phpunit-test-agent-' . uniqid(),
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
	}
}
