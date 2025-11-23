<?php
/**
 * Test for async executor assistant_id in cron job recording.
 *
 * Verifies that the async executor properly maintains assistant_id
 * context when queueing async tool executions.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

/**
 * Test assistant_id handling in async executor cron jobs.
 */
class WP_MCP_AI_Async_Executor_Assistant_ID_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Clear cron and manager state.
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );
	}

	public function tearDown(): void {
		// Clean up.
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		parent::tearDown();
	}

	/**
	 * Test that assistant_id is preserved when queueing async tool execution.
	 */
	public function test_assistant_id_in_async_tool_queue() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();

		// Queue a tool with assistant_id in context.
		$job_id = $executor->queue_tool(
			'test_tool',
			array(
				'param1' => 'value1',
			),
			array(
				'user_id'      => 1,
				'assistant_id' => 55,
			)
		);

		// Verify job was created.
		$this->assertIsString( $job_id );
		$this->assertStringStartsWith( 'async_', $job_id );

		// Verify assistant_id was recorded in cron manager.
		$job = WP_MCP_AI_Cron_Manager::get_job( $job_id );
		$this->assertNotNull( $job, 'Job should be recorded in cron manager' );
		$this->assertEquals( 55, $job['assistant_id'], 'Assistant ID should be preserved in async tool queue' );
	}

	/**
	 * Test that assistant_id defaults to 0 when not provided.
	 */
	public function test_assistant_id_defaults_to_zero() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();

		// Queue a tool without assistant_id in context.
		$job_id = $executor->queue_tool(
			'test_tool',
			array(
				'param1' => 'value1',
			),
			array(
				'user_id' => 1,
			)
		);

		// Verify job was created.
		$this->assertIsString( $job_id );

		// Verify assistant_id defaults to 0.
		$job = WP_MCP_AI_Cron_Manager::get_job( $job_id );
		$this->assertNotNull( $job );
		$this->assertEquals( 0, $job['assistant_id'], 'Assistant ID should default to 0 when not provided' );
	}

	/**
	 * Test that assistant_id is properly sanitized.
	 */
	public function test_assistant_id_sanitization() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();

		// Queue a tool with non-numeric assistant_id (should be sanitized).
		$job_id = $executor->queue_tool(
			'test_tool',
			array(
				'param1' => 'value1',
			),
			array(
				'user_id'      => 1,
				'assistant_id' => '123abc',
			)
		);

		// Verify assistant_id was sanitized to integer.
		$job = WP_MCP_AI_Cron_Manager::get_job( $job_id );
		$this->assertNotNull( $job );
		$this->assertIsInt( $job['assistant_id'], 'Assistant ID should be an integer' );
		$this->assertEquals( 123, $job['assistant_id'], 'Assistant ID should be sanitized to 123' );
	}
}
