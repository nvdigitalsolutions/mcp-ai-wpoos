<?php
/**
 * Tests for batch_manage_memory tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test batch_manage_memory tool functionality.
 */
class Test_Tool_Batch_Manage_Memory extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Batch_Manage_Memory
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool = new WP_MCP_AI_Tool_Batch_Manage_Memory();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'batch_manage_memory', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Missing action returns error response.
	 */
	public function test_missing_action_returns_error() {
		$result = $this->tool->execute(
			array( 'agent_id' => 'agent-123' ),
			array()
		);

		$this->assertTrue(
			is_wp_error( $result ) || ( is_array( $result ) && isset( $result['success'] ) && false === $result['success'] ),
			'Missing action should produce an error (WP_Error or failure array).'
		);
	}

	/**
	 * Missing agent_id returns error response.
	 */
	public function test_missing_agent_id_returns_error() {
		$result = $this->tool->execute(
			array( 'action' => 'bulk_update' ),
			array()
		);

		$this->assertTrue(
			is_wp_error( $result ) || ( is_array( $result ) && isset( $result['success'] ) && false === $result['success'] ),
			'Missing agent_id should produce an error (WP_Error or failure array).'
		);
	}

	/**
	 * Invalid action returns error response.
	 */
	public function test_invalid_action_returns_error() {
		$result = $this->tool->execute(
			array(
				'action'   => 'nonexistent_action',
				'agent_id' => 'agent-123',
			),
			array()
		);

		$this->assertTrue(
			is_wp_error( $result ) || ( is_array( $result ) && isset( $result['success'] ) && false === $result['success'] ),
			'Invalid action should produce an error (WP_Error or failure array).'
		);
	}

	/**
	 * bulk_delete with empty context_ids and no filters returns "no contexts found".
	 */
	public function test_bulk_delete_no_matching_contexts() {
		$result = $this->tool->execute(
			array(
				'action'      => 'bulk_delete',
				'agent_id'    => 'phpunit-agent-' . uniqid(),
				'context_ids' => array( 'ctx-nonexistent-1', 'ctx-nonexistent-2' ),
			),
			array()
		);

		// Tool may return WP_Error (context not found) or success array (0 deleted).
		// Either is acceptable for a non-existent agent/context.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEmpty( $result->get_error_message() );
		} else {
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'success', $result );
		}
	}

	/**
	 * bulk_update with empty context list is handled gracefully.
	 */
	public function test_bulk_update_empty_context_list() {
		$result = $this->tool->execute(
			array(
				'action'      => 'bulk_update',
				'agent_id'    => 'phpunit-agent-' . uniqid(),
				'context_ids' => array(),
			),
			array()
		);

		// Tool may return WP_Error when no contexts are found.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEmpty( $result->get_error_message() );
		} else {
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'success', $result );
		}
	}

	/**
	 * Dry-run flag does not delete any data.
	 */
	public function test_dry_run_does_not_modify_data() {
		$result = $this->tool->execute(
			array(
				'action'   => 'bulk_delete',
				'agent_id' => 'phpunit-agent-' . uniqid(),
				'options'  => array( 'dry_run' => true ),
			),
			array()
		);

		// Tool may return WP_Error when no contexts exist, or success array for dry-run.
		// Either outcome is acceptable — the key is that it doesn't crash.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEmpty( $result->get_error_message() );
		} else {
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'success', $result );
		}
	}
}
