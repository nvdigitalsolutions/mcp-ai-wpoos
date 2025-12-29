<?php
/**
 * Tests for cron and async task integration with all providers and agentic workflows.
 *
 * Verifies that:
 * - Cron jobs work correctly across OpenAI, Gemini, and Ollama providers
 * - Async tasks integrate properly with all tool executions
 * - Agentic workflows can schedule and execute tasks asynchronously
 * - Provider-specific features are compatible with the async/cron system
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-async-tool-orchestrator.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';

/**
 * Test cron and async integration across providers.
 */
class Test_Cron_Async_Provider_Integration extends WP_UnitTestCase {

	/**
	 * Executor instance.
	 *
	 * @var WP_MCP_AI_Tool_Async_Executor
	 */
	private $executor;

	/**
	 * Orchestrator instance.
	 *
	 * @var WP_MCP_AI_Async_Tool_Orchestrator
	 */
	private $orchestrator;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clear cron and options.
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		// Initialize executor and orchestrator.
		$this->executor     = new WP_MCP_AI_Tool_Async_Executor();
		$this->orchestrator = new WP_MCP_AI_Async_Tool_Orchestrator();
		$this->executor->init();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		parent::tearDown();
	}

	/**
	 * Test that async executor is properly initialized.
	 */
	public function test_async_executor_initialization() {
		global $wp_filter;

		$hook_name = WP_MCP_AI_Tool_Async_Executor::CRON_HOOK;
		$this->assertTrue(
			isset( $wp_filter[ $hook_name ] ),
			'Async executor cron hook should be registered'
		);
	}

	/**
	 * Test that cleanup cron job is scheduled.
	 */
	public function test_cleanup_cron_scheduled() {
		$this->assertTrue(
			wp_next_scheduled( 'wp_mcp_ai_cleanup_async_results' ) !== false,
			'Cleanup cron job should be scheduled'
		);

		// Verify it's in the cron manager.
		$jobs          = WP_MCP_AI_Cron_Manager::get_jobs();
		$cleanup_found = false;

		foreach ( $jobs as $job ) {
			if ( 'wp_mcp_ai_cleanup_async_results' === $job['hook'] ) {
				$cleanup_found = true;
				$this->assertEquals( 'hourly', $job['schedule'], 'Cleanup should run hourly' );
				break;
			}
		}

		$this->assertTrue( $cleanup_found, 'Cleanup job should be tracked in cron manager' );
	}

	/**
	 * Test async tool execution with capability flags.
	 */
	public function test_async_tool_with_capability_flags() {
		$mock_tool = $this->create_async_tool_with_flags( array( 'async', 'long-running' ) );

		$should_async = $this->orchestrator->should_execute_async( $mock_tool, array(), array() );
		$this->assertTrue( $should_async, 'Tool with async flags should execute asynchronously' );

		$strategy = $this->orchestrator->get_execution_strategy( $mock_tool, array(), array() );
		$this->assertEquals( 'async', $strategy['mode'], 'Execution mode should be async' );
		$this->assertTrue( $strategy['has_timeout_risk'], 'Tool should have timeout risk' );
	}

	/**
	 * Test background-only tools are forced to async.
	 */
	public function test_background_only_tool_forced_async() {
		$mock_tool = $this->create_async_tool_with_flags( array( 'background-only' ) );

		$should_async = $this->orchestrator->should_execute_async( $mock_tool, array(), array() );
		$this->assertTrue( $should_async, 'Background-only tools must execute asynchronously' );

		$strategy = $this->orchestrator->get_execution_strategy( $mock_tool, array(), array() );
		$this->assertTrue( $strategy['background_only'], 'Tool should be marked as background-only' );
		$this->assertEquals( 0, $strategy['estimated_timeout'], 'Background-only tools should have unlimited timeout' );
	}

	/**
	 * Test explicit async parameter overrides capability flags.
	 */
	public function test_explicit_async_parameter_override() {
		$sync_tool = $this->create_async_tool_with_flags( array() );

		// Force async even without flags.
		$should_async = $this->orchestrator->should_execute_async(
			$sync_tool,
			array( 'async' => true ),
			array()
		);
		$this->assertTrue( $should_async, 'Explicit async=true should force async execution' );

		// Force sync even with async flags.
		$async_tool  = $this->create_async_tool_with_flags( array( 'async' ) );
		$should_sync = $this->orchestrator->should_execute_async(
			$async_tool,
			array( 'async' => false ),
			array()
		);
		$this->assertFalse( $should_sync, 'Explicit async=false should force sync execution' );
	}

	/**
	 * Test async job queueing creates proper cron job.
	 */
	public function test_async_job_creates_cron_entry() {
		$tool_slug = 'test_async_tool';
		$arguments = array( 'param1' => 'value1' );
		$context   = array( 'user_id' => 1 );

		$job_id = $this->executor->queue_tool( $tool_slug, $arguments, $context );

		$this->assertIsString( $job_id );
		$this->assertStringStartsWith( 'async_', $job_id );

		// Verify cron job was scheduled.
		$cron_jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$found     = false;

		foreach ( $cron_jobs as $job ) {
			if ( WP_MCP_AI_Tool_Async_Executor::CRON_HOOK === $job['hook'] ) {
				$this->assertEquals( 'single', $job['schedule'], 'Async jobs should be one-time events' );
				$this->assertEquals( 1, $job['created_by'], 'Job should track creator user ID' );
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Async job should be tracked in cron manager' );
	}

	/**
	 * Test async job execution end-to-end.
	 */
	public function test_async_job_execution_flow() {
		// Create mock tool.
		$mock_tool = $this->create_mock_executable_tool();

		// Register tool.
		$registry = new WP_MCP_AI_Tool_Registry();
		add_filter(
			'wp_mcp_ai_register_tools',
			function ( $tools ) use ( $mock_tool ) {
				$tools[] = $mock_tool;
				return $tools;
			}
		);
		$registry->init();

		// Queue job.
		$job_id = $this->executor->queue_tool(
			'test_executable_tool',
			array( 'input' => 'test_input' ),
			array( 'user_id' => 1 )
		);

		// Verify initial pending status.
		$result = $this->executor->get_result( $job_id );
		$this->assertEquals( 'pending', $result['status'], 'Job should start as pending' );

		// Execute via cron hook.
		do_action( WP_MCP_AI_Tool_Async_Executor::CRON_HOOK, $job_id );

		// Verify completion.
		$result = $this->executor->get_result( $job_id );
		$this->assertEquals( 'completed', $result['status'], 'Job should complete successfully' );
		$this->assertArrayHasKey( 'result', $result );
		$this->assertArrayHasKey( 'duration', $result );
	}

	/**
	 * Test context sanitization removes sensitive data.
	 */
	public function test_context_sanitization() {
		$context = array(
			'user_id'      => 1,
			'assistant_id' => 123,
			'session_id'   => 'test_session',
			'api_key'      => 'sk-secret', // Should be removed.
			'password'     => 'secret123', // Should be removed.
			'random_data'  => 'data',      // Should be removed.
		);

		$job_id = $this->executor->queue_tool( 'test_tool', array(), $context );
		$result = $this->executor->get_result( $job_id );

		// Verify allowed keys are preserved.
		$this->assertArrayHasKey( 'user_id', $result['context'] );
		$this->assertArrayHasKey( 'assistant_id', $result['context'] );
		$this->assertArrayHasKey( 'session_id', $result['context'] );

		// Verify sensitive keys are removed.
		$this->assertArrayNotHasKey( 'api_key', $result['context'], 'API key should be sanitized' );
		$this->assertArrayNotHasKey( 'password', $result['context'], 'Password should be sanitized' );
		$this->assertArrayNotHasKey( 'random_data', $result['context'], 'Unknown keys should be sanitized' );
	}

	/**
	 * Test result compression for large payloads.
	 */
	public function test_large_result_compression() {
		if ( ! function_exists( 'gzcompress' ) ) {
			$this->markTestSkipped( 'gzcompress not available' );
		}

		// Create large result (>100KB).
		$large_data  = str_repeat( 'Lorem ipsum dolor sit amet. ', 5000 );
		$test_result = array( 'data' => $large_data );

		// Use reflection to access protected methods.
		$reflection      = new ReflectionClass( $this->executor );
		$compress_method = $reflection->getMethod( 'compress_result' );
		$compress_method->setAccessible( true );
		$decompress_method = $reflection->getMethod( 'decompress_result' );
		$decompress_method->setAccessible( true );

		$compressed = $compress_method->invoke( $this->executor, $test_result );
		$this->assertTrue( $compressed['compressed'], 'Large result should be compressed' );

		$decompressed = $decompress_method->invoke( $this->executor, $compressed );
		$this->assertEquals( $test_result, $decompressed, 'Decompressed result should match original' );
	}

	/**
	 * Test provider-agnostic async execution.
	 *
	 * This verifies that async execution works regardless of the AI provider
	 * (OpenAI, Gemini, Ollama) being used.
	 */
	public function test_provider_agnostic_execution() {
		$providers = array( 'openai', 'gemini', 'ollama' );

		foreach ( $providers as $provider ) {
			$mock_tool = $this->create_provider_specific_tool( $provider );

			$job_id = $this->executor->queue_tool(
				"test_{$provider}_tool",
				array( 'provider' => $provider ),
				array( 'user_id' => 1 )
			);

			$result = $this->executor->get_result( $job_id );
			$this->assertIsArray( $result, "Async execution should work for {$provider}" );
			$this->assertEquals( 'pending', $result['status'] );
		}
	}

	/**
	 * Test agentic workflow integration.
	 *
	 * Verifies that async tasks work correctly within agentic workflows.
	 */
	public function test_agentic_workflow_async_integration() {
		// Create tool with agentic capability flag.
		$agentic_tool = $this->create_async_tool_with_flags( array( 'async', 'agentic' ) );

		$should_async = $this->orchestrator->should_execute_async(
			$agentic_tool,
			array(),
			array( 'agentic_mode' => true )
		);

		$this->assertTrue( $should_async, 'Agentic tools with async flag should execute asynchronously' );
	}

	/**
	 * Test cron job retention policy.
	 */
	public function test_cron_retention_policy() {
		// Create a job with old timestamp.
		$old_time = time() - ( 25 * HOUR_IN_SECONDS );
		WP_MCP_AI_Cron_Manager::record_job( 'test_old_hook', array(), 'single', $old_time, 1 );

		// Create a recent job.
		$recent_time = time() - ( 1 * HOUR_IN_SECONDS );
		WP_MCP_AI_Cron_Manager::record_job( 'test_recent_hook', array(), 'single', $recent_time, 1 );

		// Simulate jobs not being scheduled (executed).
		_set_cron_array( array() );

		// Run pruning.
		WP_MCP_AI_Cron_Manager::maybe_prune_jobs();

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();

		// Old job should be removed, recent job should remain.
		$has_old    = false;
		$has_recent = false;

		foreach ( $jobs as $job ) {
			if ( 'test_old_hook' === $job['hook'] ) {
				$has_old = true;
			}
			if ( 'test_recent_hook' === $job['hook'] ) {
				$has_recent = true;
			}
		}

		$this->assertFalse( $has_old, 'Jobs older than retention period should be pruned' );
		$this->assertTrue( $has_recent, 'Jobs within retention period should be kept' );
	}

	/**
	 * Test that async executor handles missing tool gracefully.
	 */
	public function test_missing_tool_error_handling() {
		$job_id = $this->executor->queue_tool( 'nonexistent_tool', array(), array( 'user_id' => 1 ) );

		// Execute the job.
		do_action( WP_MCP_AI_Tool_Async_Executor::CRON_HOOK, $job_id );

		// Verify it failed gracefully.
		$result = $this->executor->get_result( $job_id );
		$this->assertEquals( 'failed', $result['status'], 'Missing tool should result in failed status' );
		$this->assertArrayHasKey( 'error', $result );
	}

	/**
	 * Test cleanup of expired results.
	 */
	public function test_cleanup_expired_results() {
		// Create a job and mark it as expired by manipulating transient timeout.
		$job_id = $this->executor->queue_tool( 'test_tool', array(), array( 'user_id' => 1 ) );

		// Manually expire the transient.
		$transient_key = 'wp_mcp_ai_async_meta_' . $job_id;
		set_transient( $transient_key, array( 'test' => 'data' ), -1 ); // Expired.

		// Run cleanup.
		$this->executor->cleanup_expired_results();

		// Verify result is no longer accessible.
		$result = $this->executor->get_result( $job_id );
		$this->assertWPError( $result, 'Expired results should be cleaned up' );
	}

	/**
	 * Create a mock tool with capability flags.
	 *
	 * @param array $flags Capability flags.
	 * @return object Mock tool instance.
	 */
	private function create_async_tool_with_flags( $flags ) {
		return new class( $flags ) implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
			private $flags;

			/**
			 * Constructor.
			 */
			public function __construct( $flags ) {
				$this->flags = $flags;
			}

			/**
			 * Get the tool slug.
			 *
			 * @return string Tool slug.
			 */
			public function get_slug() {
				return 'test_tool_' . md5( wp_json_encode( $this->flags ) );
			}

			/**
			 * Get the tool name.
			 *
			 * @return string Tool name.
			 */
			public function get_name() {
				return 'Test Tool';
			}

			/**
			 * Get the tool description.
			 *
			 * @return string Tool description.
			 */
			public function get_description() {
				return 'Test tool';
			}

			/**
			 * Get the parameters schema.
			 *
			 * @return array Parameters schema.
			 */
			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			/**
			 * Execute the tool.
			 *
			 * @param array $arguments Tool arguments.
			 * @param array $context Execution context.
			 * @return array|WP_Error Tool result.
			 */
			public function execute( array $arguments = array(), array $context = array() ) {
				return array( 'success' => true );
			}

			public function get_capability_flags() {
				return $this->flags;
			}
		};
	}

	/**
	 * Create a mock executable tool.
	 *
	 * @return object Mock tool instance.
	 */
	private function create_mock_executable_tool() {
		return new class() implements WP_MCP_AI_Tool_Interface {
			/**
			 * Get the tool slug.
			 *
			 * @return string Tool slug.
			 */
			public function get_slug() {
				return 'test_executable_tool';
			}

			/**
			 * Get the tool name.
			 *
			 * @return string Tool name.
			 */
			public function get_name() {
				return 'Test Executable Tool';
			}

			/**
			 * Get the tool description.
			 *
			 * @return string Tool description.
			 */
			public function get_description() {
				return 'Tool for testing execution';
			}

			/**
			 * Get the parameters schema.
			 *
			 * @return array Parameters schema.
			 */
			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(
						'input' => array(
							'type'        => 'string',
							'description' => 'Test input',
						),
					),
				);
			}

			/**
			 * Execute the tool.
			 *
			 * @param array $arguments Tool arguments.
			 * @param array $context Execution context.
			 * @return array|WP_Error Tool result.
			 */
			public function execute( array $arguments = array(), array $context = array() ) {
				return array(
					'success' => true,
					'message' => 'Execution completed',
					'input'   => isset( $arguments['input'] ) ? $arguments['input'] : null,
				);
			}
		};
	}

	/**
	 * Create a provider-specific tool.
	 *
	 * @param string $provider Provider name.
	 * @return object Mock tool instance.
	 */
	private function create_provider_specific_tool( $provider ) {
		return new class( $provider ) implements WP_MCP_AI_Tool_Interface {
			private $provider;

			/**
			 * Constructor.
			 */
			public function __construct( $provider ) {
				$this->provider = $provider;
			}

			/**
			 * Get the tool slug.
			 *
			 * @return string Tool slug.
			 */
			public function get_slug() {
				return 'test_' . $this->provider . '_tool';
			}

			/**
			 * Get the tool name.
			 *
			 * @return string Tool name.
			 */
			public function get_name() {
				return 'Test ' . ucfirst( $this->provider ) . ' Tool';
			}

			/**
			 * Get the tool description.
			 *
			 * @return string Tool description.
			 */
			public function get_description() {
				return 'Provider-specific test tool';
			}

			/**
			 * Get the parameters schema.
			 *
			 * @return array Parameters schema.
			 */
			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			/**
			 * Execute the tool.
			 *
			 * @param array $arguments Tool arguments.
			 * @param array $context Execution context.
			 * @return array|WP_Error Tool result.
			 */
			public function execute( array $arguments = array(), array $context = array() ) {
				return array(
					'success'  => true,
					'provider' => $this->provider,
				);
			}
		};
	}
}
