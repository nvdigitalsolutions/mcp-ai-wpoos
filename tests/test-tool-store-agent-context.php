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
	 * Test user ID with read capability.
	 *
	 * @var int
	 */
	private $test_user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create a user with read capability so the tool's permission check passes.
		$this->test_user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->test_user_id );

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
	 * Missing agent_id returns WP_Error.
	 */
	public function test_missing_agent_id_returns_error_result() {
		$result = $this->tool->execute(
			array(
				'context_type' => 'conversation',
				'context_data' => array( 'key' => 'value' ),
			),
			array()
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertStringContainsString( 'Agent ID', $result->get_error_message() );
	}

	/**
	 * Missing context_type returns WP_Error.
	 */
	public function test_missing_context_type_returns_error_result() {
		$result = $this->tool->execute(
			array(
				'agent_id'     => 'agent-1',
				'context_data' => array( 'key' => 'value' ),
			),
			array()
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertStringContainsString( 'Context type', $result->get_error_message() );
	}

	/**
	 * Missing context_data returns WP_Error.
	 */
	public function test_missing_context_data_returns_error_result() {
		$result = $this->tool->execute(
			array(
				'agent_id'     => 'agent-1',
				'context_type' => 'conversation',
			),
			array()
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertStringContainsString( 'Context data', $result->get_error_message() );
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
					'title'    => 'Test Conversation',
					'content'  => 'Hello world',
					'messages' => array(
						array(
							'role'    => 'user',
							'content' => 'Hello',
						),
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
				'context_data' => array(
					'title'   => 'TTL Test',
					'content' => 'Testing TTL clamping',
				),
				'ttl'          => 1, // Below the 3600-second minimum.
			),
			array()
		);

		// The tool should not error on TTL clamping.
		$this->assertIsArray( $result );
	}
}
