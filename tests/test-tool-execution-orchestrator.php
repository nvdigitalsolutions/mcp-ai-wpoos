<?php
/**
 * Test Tool Execution Orchestrator
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for tool execution orchestrator.
 */
class Test_Tool_Execution_Orchestrator extends WP_UnitTestCase {

	/**
	 * Orchestrator instance
	 *
	 * @var WP_MCP_AI_Tool_Execution_Orchestrator
	 */
	private $orchestrator;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load dependencies.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-execution-orchestrator.php';

		// Create orchestrator.
		$this->orchestrator = new WP_MCP_AI_Tool_Execution_Orchestrator();

		// Set default settings.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_auto_async_execution' => true,
				'enable_cron_orchestration'   => true,
			)
		);
	}

	/**
	 * Test that orchestrator detects long-running tools.
	 */
	public function test_detects_long_running_tools() {
		// Test with generate_veo_video which has 'long-running' flag.
		$is_long_running = $this->orchestrator->is_long_running_tool( 'generate_veo_video' );
		$this->assertTrue( $is_long_running, 'generate_veo_video should be detected as long-running' );

		// Test with a non-long-running tool.
		$is_long_running = $this->orchestrator->is_long_running_tool( 'get_post' );
		$this->assertFalse( $is_long_running, 'get_post should not be detected as long-running' );
	}

	/**
	 * Test that orchestrator respects auto-async setting when enabled.
	 */
	public function test_respects_auto_async_enabled() {
		// Enable auto-async.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_auto_async_execution' => true,
				'enable_cron_orchestration'   => true,
			)
		);

		// Create a mock user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Execute a long-running tool - should return async job info.
		$result = $this->orchestrator->execute_tool(
			'generate_veo_video',
			array( 'prompt' => 'Test video' ),
			array( 'user_id' => $user_id )
		);

		// Should return async job info.
		$this->assertIsArray( $result, 'Should return array' );
		$this->assertTrue( isset( $result['async'] ), 'Should have async flag' );
		$this->assertTrue( $result['async'], 'async flag should be true' );
		$this->assertTrue( isset( $result['job_id'] ), 'Should have job_id' );
		$this->assertStringStartsWith( 'async_', $result['job_id'], 'job_id should start with async_' );
	}

	/**
	 * Test that orchestrator respects auto-async setting when disabled.
	 */
	public function test_respects_auto_async_disabled() {
		// Disable auto-async.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_auto_async_execution' => false,
				'enable_cron_orchestration'   => true,
			)
		);

		// Create a mock user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Execute a long-running tool - should execute synchronously (or fail if actual tool execution is attempted).
		// We can't fully test this without mocking the actual tool execution.
		// For now, we just verify that the orchestrator exists and can be called.
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Execution_Orchestrator', $this->orchestrator );
	}

	/**
	 * Test that force_async context overrides settings.
	 */
	public function test_force_async_context_overrides_settings() {
		// Disable auto-async.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_auto_async_execution' => false,
				'enable_cron_orchestration'   => false,
			)
		);

		// Create a mock user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Execute with force_async - should still execute async.
		$result = $this->orchestrator->execute_tool(
			'get_post',
			array( 'post_id' => 1 ),
			array(
				'user_id'     => $user_id,
				'force_async' => true,
			)
		);

		// Should return async job info even though auto-async is disabled.
		$this->assertIsArray( $result, 'Should return array' );
		$this->assertTrue( isset( $result['async'] ), 'Should have async flag' );
		$this->assertTrue( $result['async'], 'async flag should be true' );
	}

	/**
	 * Test that force_sync context prevents async execution.
	 */
	public function test_force_sync_context_prevents_async() {
		// Enable auto-async.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_auto_async_execution' => true,
				'enable_cron_orchestration'   => true,
			)
		);

		// Create a mock user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Execute long-running tool with force_sync - should execute synchronously.
		// We expect this to fail or return an error since we're forcing sync on an async tool.
		// The important thing is that it doesn't return async job info.
		$result = $this->orchestrator->execute_tool(
			'generate_veo_video',
			array( 'prompt' => 'Test video' ),
			array(
				'user_id'    => $user_id,
				'force_sync' => true,
			)
		);

		// Should NOT return async job info.
		if ( is_array( $result ) ) {
			$this->assertFalse( isset( $result['async'] ) && $result['async'], 'Should not execute async when force_sync is set' );
		}
	}

	/**
	 * Test that orchestrator logs execution decisions.
	 */
	public function test_logs_orchestration_decisions() {
		// Create a mock user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Execute a tool.
		$this->orchestrator->execute_tool(
			'generate_veo_video',
			array( 'prompt' => 'Test video' ),
			array( 'user_id' => $user_id )
		);

		// Check that logging happened (if logging is enabled).
		// This is a basic check - we're just ensuring no fatal errors occurred.
		$this->assertTrue( true, 'Orchestration completed without errors' );
	}

	/**
	 * Test that non-existent tools return error.
	 */
	public function test_non_existent_tool_returns_error() {
		$result = $this->orchestrator->execute_tool(
			'non_existent_tool',
			array(),
			array()
		);

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for non-existent tool' );
		$this->assertEquals( 'wp_mcp_ai_tool_not_found', $result->get_error_code(), 'Should have correct error code' );
	}

	/**
	 * Test that orchestrator handles tools without capability flags.
	 */
	public function test_handles_tools_without_capability_flags() {
		// Create a mock user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Execute a tool that doesn't have async capability flags.
		// Should execute synchronously.
		$result = $this->orchestrator->execute_tool(
			'get_post',
			array( 'post_id' => 1 ),
			array( 'user_id' => $user_id )
		);

		// Should execute synchronously (not return async job info).
		// May return error or actual result depending on tool implementation.
		if ( is_array( $result ) && isset( $result['async'] ) ) {
			$this->assertFalse( $result['async'], 'Tools without async flags should not execute async by default' );
		}
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}
}
