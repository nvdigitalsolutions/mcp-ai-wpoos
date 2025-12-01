<?php
/**
 * Test async tool execution flow end-to-end.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test async tool execution flow.
 */
class Test_Async_Tool_Execution_Flow extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-async-tool-orchestrator.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';
		require_once WP_MCP_AI_PATH . 'includes/services-init.php';
	}

	/**
	 * Test that async executor uses singleton registry.
	 */
	public function test_async_executor_uses_singleton_registry() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		// Use reflection to access protected get_registry method.
		$reflection = new ReflectionClass( $executor );
		$method     = $reflection->getMethod( 'get_registry' );
		$method->setAccessible( true );

		$registry1 = $method->invoke( $executor );
		$registry2 = WP_MCP_AI_Tool_Registry::get_instance();

		$this->assertSame( $registry1, $registry2, 'Async executor should use singleton registry instance' );
	}

	/**
	 * Test that job queueing creates proper metadata structure.
	 */
	public function test_job_queueing_creates_metadata() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		$tool_slug = 'test_tool';
		$arguments = array( 'prompt' => 'test prompt' );
		$context   = array(
			'user_id'    => 1,
			'session_id' => 'test123',
		);

		$job_id = $executor->queue_tool( $tool_slug, $arguments, $context );

		$this->assertIsString( $job_id );
		$this->assertStringStartsWith( 'async_', $job_id );

		// Verify metadata was stored.
		$result = $executor->get_result( $job_id );

		$this->assertIsArray( $result );
		$this->assertEquals( 'pending', $result['status'] );
		$this->assertEquals( $tool_slug, $result['tool_slug'] );
		$this->assertEquals( $arguments, $result['arguments'] );
		$this->assertArrayHasKey( 'user_id', $result['context'] );
		$this->assertEquals( 1, $result['context']['user_id'] );
	}

	/**
	 * Test that context is properly sanitized.
	 */
	public function test_context_sanitization() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		$tool_slug = 'test_tool';
		$arguments = array();
		$context   = array(
			'user_id'       => 1,
			'assistant_id'  => 2,
			'session_id'    => 'test123',
			'sensitive_key' => 'should_be_removed', // Should not be preserved.
			'api_key'       => 'secret', // Should not be preserved.
		);

		$job_id = $executor->queue_tool( $tool_slug, $arguments, $context );
		$result = $executor->get_result( $job_id );

		$this->assertArrayHasKey( 'user_id', $result['context'] );
		$this->assertArrayHasKey( 'assistant_id', $result['context'] );
		$this->assertArrayHasKey( 'session_id', $result['context'] );
		$this->assertArrayNotHasKey( 'sensitive_key', $result['context'] );
		$this->assertArrayNotHasKey( 'api_key', $result['context'] );
	}

	/**
	 * Test result compression and decompression.
	 */
	public function test_result_compression() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		// Create a large result (>100KB to trigger compression).
		$large_data  = str_repeat( 'A', 150000 );
		$test_result = array(
			'data'    => $large_data,
			'url'     => 'https://example.com/image.png',
			'success' => true,
		);

		// Use reflection to test compress/decompress.
		$reflection        = new ReflectionClass( $executor );
		$compress_method   = $reflection->getMethod( 'compress_result' );
		$decompress_method = $reflection->getMethod( 'decompress_result' );
		$compress_method->setAccessible( true );
		$decompress_method->setAccessible( true );

		$compressed   = $compress_method->invoke( $executor, $test_result );
		$decompressed = $decompress_method->invoke( $executor, $compressed );

		if ( function_exists( 'gzcompress' ) ) {
			$this->assertTrue( $compressed['compressed'], 'Large result should be compressed' );
			$this->assertIsString( $compressed['data'], 'Compressed data should be a string' );
		}

		$this->assertEquals( $test_result, $decompressed, 'Decompressed result should match original' );
	}

	/**
	 * Test decompression error handling.
	 */
	public function test_decompression_error_handling() {
		if ( ! function_exists( 'gzcompress' ) ) {
			$this->markTestSkipped( 'gzcompress not available' );
		}

		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		// Create an invalid compressed result.
		$invalid_compressed = array(
			'compressed' => true,
			'data'       => 'invalid_base64_data!!!',
		);

		// Use reflection to test decompress.
		$reflection        = new ReflectionClass( $executor );
		$decompress_method = $reflection->getMethod( 'decompress_result' );
		$decompress_method->setAccessible( true );

		$result = $decompress_method->invoke( $executor, $invalid_compressed );

		$this->assertNull( $result, 'Invalid compressed data should return null' );
	}

	/**
	 * Test small result is not compressed.
	 */
	public function test_small_result_not_compressed() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		$small_result = array(
			'url'     => 'https://example.com/image.png',
			'success' => true,
		);

		// Use reflection to test compress.
		$reflection      = new ReflectionClass( $executor );
		$compress_method = $reflection->getMethod( 'compress_result' );
		$compress_method->setAccessible( true );

		$compressed = $compress_method->invoke( $executor, $small_result );

		$this->assertFalse( $compressed['compressed'], 'Small result should not be compressed' );
		$this->assertEquals( $small_result, $compressed['data'], 'Uncompressed data should match original' );
	}

	/**
	 * Test job_id generation is unique.
	 */
	public function test_job_id_uniqueness() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		$tool_slug = 'test_tool';
		$arguments = array( 'prompt' => 'test prompt' );
		$context   = array( 'user_id' => 1 );

		$job_id1 = $executor->queue_tool( $tool_slug, $arguments, $context );
		usleep( 100 ); // Small delay to ensure different microtime.
		$job_id2 = $executor->queue_tool( $tool_slug, $arguments, $context );

		$this->assertNotEquals( $job_id1, $job_id2, 'Job IDs should be unique even with same arguments' );
	}

	/**
	 * Test that missing job returns error.
	 */
	public function test_missing_job_returns_error() {
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		$result = $executor->get_result( 'async_nonexistent' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_job_not_found', $result->get_error_code() );
	}

	/**
	 * Test orchestrator decision for async tool.
	 */
	public function test_orchestrator_async_decision() {
		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

		// Create mock tool with async flag.
		$mock_tool = new class() implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
			public function get_slug() {
				return 'test_async_tool';
			}
			public function get_name() {
				return 'Test Async Tool';
			}
			public function get_description() {
				return 'Test tool';
			}
			public function get_parameters_schema() {
				return array();
			}
			public function execute( array $arguments = array(), array $context = array() ) {
				return array( 'success' => true );
			}
			public function get_capability_flags() {
				return array( 'async' );
			}
		};

		$orchestrator = new WP_MCP_AI_Async_Tool_Orchestrator();
		$should_async = $orchestrator->should_execute_async( $mock_tool, array(), array() );

		$this->assertTrue( $should_async, 'Tool with async flag should execute asynchronously' );
	}

	/**
	 * Test orchestrator decision for sync tool.
	 */
	public function test_orchestrator_sync_decision() {
		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

		// Create mock tool without async flag.
		$mock_tool = new class() implements WP_MCP_AI_Tool_Interface {
			public function get_slug() {
				return 'test_sync_tool';
			}
			public function get_name() {
				return 'Test Sync Tool';
			}
			public function get_description() {
				return 'Test tool';
			}
			public function get_parameters_schema() {
				return array();
			}
			public function execute( array $arguments = array(), array $context = array() ) {
				return array( 'success' => true );
			}
		};

		$orchestrator = new WP_MCP_AI_Async_Tool_Orchestrator();
		$should_async = $orchestrator->should_execute_async( $mock_tool, array(), array() );

		$this->assertFalse( $should_async, 'Tool without async flag should execute synchronously' );
	}

	/**
	 * Test explicit async parameter overrides orchestrator.
	 */
	public function test_explicit_async_parameter() {
		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

		$mock_tool = new class() implements WP_MCP_AI_Tool_Interface {
			public function get_slug() {
				return 'test_tool';
			}
			public function get_name() {
				return 'Test Tool';
			}
			public function get_description() {
				return 'Test tool';
			}
			public function get_parameters_schema() {
				return array();
			}
			public function execute( array $arguments = array(), array $context = array() ) {
				return array( 'success' => true );
			}
		};

		$orchestrator = new WP_MCP_AI_Async_Tool_Orchestrator();

		// Test explicit async=true.
		$should_async = $orchestrator->should_execute_async( $mock_tool, array( 'async' => true ), array() );
		$this->assertTrue( $should_async, 'Explicit async=true should force async execution' );

		// Test explicit async=false.
		$should_async = $orchestrator->should_execute_async( $mock_tool, array( 'async' => false ), array() );
		$this->assertFalse( $should_async, 'Explicit async=false should force sync execution' );
	}

	/**
	 * Test agentic loop context forces synchronous execution.
	 *
	 * When in an agentic loop, tools must execute synchronously so the LLM
	 * receives complete results (e.g., generated image URLs) before generating
	 * its response. This test ensures async-capable tools are forced sync
	 * when the agentic_loop context is set.
	 */
	public function test_agentic_loop_forces_sync_execution() {
		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

		// Create a mock tool with 'async' capability flag (like generate_openai_image).
		$mock_async_tool = new class() implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
			public function get_slug() {
				return 'async_capable_tool';
			}
			public function get_name() {
				return 'Async Capable Tool';
			}
			public function get_description() {
				return 'Tool with async capability';
			}
			public function get_parameters_schema() {
				return array();
			}
			public function execute( array $arguments = array(), array $context = array() ) {
				return array( 'success' => true );
			}
			public function get_capability_flags() {
				return array( 'async', 'write' );
			}
		};

		$orchestrator = new WP_MCP_AI_Async_Tool_Orchestrator();

		// Without agentic loop context, async tool should execute async.
		$should_async = $orchestrator->should_execute_async( $mock_async_tool, array(), array() );
		$this->assertTrue( $should_async, 'Async-capable tool should execute async without agentic loop' );

		// With agentic loop context, async tool should execute synchronously.
		$should_async_in_loop = $orchestrator->should_execute_async(
			$mock_async_tool,
			array(),
			array( 'agentic_loop' => true )
		);
		$this->assertFalse( $should_async_in_loop, 'Async-capable tool should execute sync in agentic loop' );

		// Background-only tools should still run async even in agentic loop.
		$mock_background_only_tool = new class() implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
			public function get_slug() {
				return 'background_only_tool';
			}
			public function get_name() {
				return 'Background Only Tool';
			}
			public function get_description() {
				return 'Tool that must run in background';
			}
			public function get_parameters_schema() {
				return array();
			}
			public function execute( array $arguments = array(), array $context = array() ) {
				return array( 'success' => true );
			}
			public function get_capability_flags() {
				return array( 'background-only', 'long-running' );
			}
		};

		// Background-only tools must run async even in agentic loop.
		$should_async_background = $orchestrator->should_execute_async(
			$mock_background_only_tool,
			array(),
			array( 'agentic_loop' => true )
		);
		$this->assertTrue( $should_async_background, 'Background-only tools must run async even in agentic loop' );
	}
}
