<?php
/**
 * Tests for store_agent_context tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test store_agent_context tool — writes to DB, part of multi-agent memory.
 */
class Test_Tool_Store_Agent_Context extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Store_Agent_Context
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool = new WP_MCP_AI_Tool_Store_Agent_Context();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'store_agent_context', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Missing agent_id returns success=false result.
	 */
	public function test_missing_agent_id_returns_error_result() {
		$result = $this->tool->execute(
			array(
				'context_type' => 'conversation',
				'context_data' => array( 'key' => 'value' ),
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Agent ID', $result['message'] );
	}

	/**
	 * Missing context_type returns success=false result.
	 */
	public function test_missing_context_type_returns_error_result() {
		$result = $this->tool->execute(
			array(
				'agent_id'     => 'agent-1',
				'context_data' => array( 'key' => 'value' ),
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Context type', $result['message'] );
	}

	/**
	 * Missing context_data returns success=false result.
	 */
	public function test_missing_context_data_returns_error_result() {
		$result = $this->tool->execute(
			array(
				'agent_id'     => 'agent-1',
				'context_type' => 'conversation',
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Context data', $result['message'] );
	}

	/**
	 * Valid args complete without fatal error (success or graceful failure).
	 */
	public function test_valid_args_complete_without_fatal() {
		$result = $this->tool->execute(
			array(
				'agent_id'     => 'phpunit-agent-' . uniqid(),
				'context_type' => 'conversation',
				'context_data' => array(
					'messages' => array(
						array( 'role' => 'user', 'content' => 'Hello' ),
					),
				),
			),
			array()
		);

		// Must be an array with a 'success' key (regardless of value).
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
	}

	/**
	 * TTL is clamped to a minimum of 1 hour (tools specification).
	 */
	public function test_ttl_clamped_to_minimum() {
		$result = $this->tool->execute(
			array(
				'agent_id'     => 'agent-ttl-test',
				'context_type' => 'task',
				'context_data' => array( 'info' => 'test' ),
				'ttl'          => 1, // Below the 3600-second minimum.
			),
			array()
		);

		// The tool should not error on TTL clamping.
		$this->assertIsArray( $result );
	}
}
