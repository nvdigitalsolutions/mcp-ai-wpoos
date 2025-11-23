<?php
/**
 * Test async executor job notifier integration.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that async executor fires notification hooks for any tool.
 */
class Test_Async_Executor_Job_Notifier_Integration extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';

		// Initialize services.
		WP_MCP_AI_Job_Notifier::init();
	}

	/**
	 * Test that job_completed hook is fired when async tool completes.
	 */
	public function test_completion_hook_fired_for_async_tool() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();

		// Track if hook was called.
		$hook_called    = false;
		$hook_job_id    = null;
		$hook_result    = null;
		$hook_metadata  = null;

		add_action(
			'wp_mcp_ai_job_completed',
			function( $id, $result, $meta ) use ( &$hook_called, &$hook_job_id, &$hook_result, &$hook_metadata ) {
				$hook_called   = true;
				$hook_job_id   = $id;
				$hook_result   = $result;
				$hook_metadata = $meta;
			},
			10,
			3
		);

		// Create a mock tool that returns success.
		$mock_tool = new class() implements WP_MCP_AI_Tool_Interface {
			public function get_slug() {
				return 'test_async_tool';
			}
			public function get_name() {
				return 'Test Async Tool';
			}
			public function get_description() {
				return 'Test tool for async executor';
			}
			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}
			public function execute( array $arguments = array(), array $context = array() ) {
				return array(
					'success' => true,
					'data'    => 'Test result data',
				);
			}
		};

		// Queue the tool.
		$job_id = $executor->queue_tool( 'test_async_tool', array(), array( 'user_id' => 1 ) );
		$this->assertIsString( $job_id, 'Job ID should be returned' );

		// Create mock tool registry.
		$registry = new class( $mock_tool ) {
			protected $tool;
			public function __construct( $tool ) {
				$this->tool = $tool;
			}
			public function get_tool( $slug ) {
				return $this->tool;
			}
		};

		// Use reflection to inject registry and execute tool.
		$reflection      = new ReflectionClass( $executor );
		$registry_prop   = $reflection->getProperty( 'registry' );
		$registry_prop->setAccessible( true );
		$registry_prop->setValue( $executor, $registry );

		// Execute the async tool.
		$executor->execute_async_tool( $job_id );

		// Verify hook was called.
		$this->assertTrue( $hook_called, 'wp_mcp_ai_job_completed hook should be fired for async tools' );
		$this->assertEquals( $job_id, $hook_job_id, 'Job ID should match' );
		$this->assertIsArray( $hook_result, 'Result should be an array' );
		$this->assertTrue( $hook_result['success'], 'Result should indicate success' );
		$this->assertIsArray( $hook_metadata, 'Metadata should be an array' );
		$this->assertEquals( 'test_async_tool', $hook_metadata['tool'], 'Tool slug should match' );

		// Verify Job Notifier cached the status.
		$cached_status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
		$this->assertIsArray( $cached_status, 'Job status should be cached' );
		$this->assertEquals( 'completed', $cached_status['status'], 'Status should be completed' );
	}

	/**
	 * Test that job_failed hook is fired when async tool fails.
	 */
	public function test_failure_hook_fired_for_async_tool() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();

		// Track if hook was called.
		$hook_called = false;
		$hook_error  = null;

		add_action(
			'wp_mcp_ai_job_failed',
			function( $id, $error, $meta ) use ( &$hook_called, &$hook_error ) {
				$hook_called = true;
				$hook_error  = $error;
			},
			10,
			3
		);

		// Create a mock tool that returns an error.
		$mock_tool = new class() implements WP_MCP_AI_Tool_Interface {
			public function get_slug() {
				return 'test_failing_tool';
			}
			public function get_name() {
				return 'Test Failing Tool';
			}
			public function get_description() {
				return 'Test tool that fails';
			}
			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}
			public function execute( array $arguments = array(), array $context = array() ) {
				return new WP_Error( 'test_error', 'Tool execution failed intentionally' );
			}
		};

		// Queue the tool.
		$job_id = $executor->queue_tool( 'test_failing_tool', array(), array( 'user_id' => 1 ) );

		// Create mock tool registry.
		$registry = new class( $mock_tool ) {
			protected $tool;
			public function __construct( $tool ) {
				$this->tool = $tool;
			}
			public function get_tool( $slug ) {
				return $this->tool;
			}
		};

		// Use reflection to inject registry and execute tool.
		$reflection      = new ReflectionClass( $executor );
		$registry_prop   = $reflection->getProperty( 'registry' );
		$registry_prop->setAccessible( true );
		$registry_prop->setValue( $executor, $registry );

		// Execute the async tool.
		$executor->execute_async_tool( $job_id );

		// Verify hook was called.
		$this->assertTrue( $hook_called, 'wp_mcp_ai_job_failed hook should be fired for failed async tools' );
		$this->assertWPError( $hook_error, 'Error should be a WP_Error' );
		$this->assertEquals( 'Tool execution failed intentionally', $hook_error->get_error_message() );

		// Verify Job Notifier cached the status.
		$cached_status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
		$this->assertIsArray( $cached_status, 'Job status should be cached' );
		$this->assertEquals( 'failed', $cached_status['status'], 'Status should be failed' );
	}

	/**
	 * Test that exception in tool execution fires failure hook.
	 */
	public function test_exception_triggers_failure_hook() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();

		// Track if hook was called.
		$hook_called = false;

		add_action(
			'wp_mcp_ai_job_failed',
			function( $id ) use ( &$hook_called ) {
				$hook_called = true;
			},
			10,
			3
		);

		// Create a mock tool that throws an exception.
		$mock_tool = new class() implements WP_MCP_AI_Tool_Interface {
			public function get_slug() {
				return 'test_exception_tool';
			}
			public function get_name() {
				return 'Test Exception Tool';
			}
			public function get_description() {
				return 'Test tool that throws exception';
			}
			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}
			public function execute( array $arguments = array(), array $context = array() ) {
				throw new Exception( 'Intentional exception for testing' );
			}
		};

		// Queue the tool.
		$job_id = $executor->queue_tool( 'test_exception_tool', array(), array( 'user_id' => 1 ) );

		// Create mock tool registry.
		$registry = new class( $mock_tool ) {
			protected $tool;
			public function __construct( $tool ) {
				$this->tool = $tool;
			}
			public function get_tool( $slug ) {
				return $this->tool;
			}
		};

		// Use reflection to inject registry and execute tool.
		$reflection      = new ReflectionClass( $executor );
		$registry_prop   = $reflection->getProperty( 'registry' );
		$registry_prop->setAccessible( true );
		$registry_prop->setValue( $executor, $registry );

		// Execute the async tool.
		$executor->execute_async_tool( $job_id );

		// Verify hook was called.
		$this->assertTrue( $hook_called, 'Exception in tool should trigger wp_mcp_ai_job_failed hook' );
	}
}
