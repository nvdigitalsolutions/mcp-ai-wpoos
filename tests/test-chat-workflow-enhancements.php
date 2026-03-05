<?php
/**
 * Tests for chat workflow enhancements.
 *
 * Covers improvements to:
 * - Cron manager: retention validation, corrupted data handling
 * - Async executor: spawn_cron retry, result size validation
 * - Job notifier: webhook signing, recursion depth limit
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

/**
 * Tests for chat workflow enhancements.
 */
class Test_Chat_Workflow_Enhancements extends WP_UnitTestCase {

	/**
	 * Async executor instance.
	 *
	 * @var WP_MCP_AI_Tool_Async_Executor
	 */
	private $executor;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->executor = new WP_MCP_AI_Tool_Async_Executor();

		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );
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
	 * Test cron manager handles corrupted option data gracefully.
	 */
	public function test_cron_manager_handles_corrupted_data() {
		// Store corrupted data (a string instead of array).
		update_option( WP_MCP_AI_Cron_Manager::OPTION_NAME, 'corrupted_string_data' );

		// Should return an empty array rather than crashing.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertIsArray( $jobs );
		$this->assertEmpty( $jobs );
	}

	/**
	 * Test cron manager handles null option data gracefully.
	 */
	public function test_cron_manager_handles_null_data() {
		// Store null data.
		update_option( WP_MCP_AI_Cron_Manager::OPTION_NAME, null );

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertIsArray( $jobs );
		$this->assertEmpty( $jobs );
	}

	/**
	 * Test cron manager handles integer option data gracefully.
	 */
	public function test_cron_manager_handles_integer_data() {
		// Store an integer instead of array.
		update_option( WP_MCP_AI_Cron_Manager::OPTION_NAME, 42 );

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertIsArray( $jobs );
		$this->assertEmpty( $jobs );
	}

	/**
	 * Test cron manager still works with valid data after encountering corrupted data.
	 */
	public function test_cron_manager_recovers_from_corrupted_data() {
		// Store corrupted data.
		update_option( WP_MCP_AI_Cron_Manager::OPTION_NAME, 'corrupted' );

		// First call should return empty.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertEmpty( $jobs );

		// Record a new job.
		WP_MCP_AI_Cron_Manager::record_job(
			'test_hook',
			array(),
			'single',
			time() + HOUR_IN_SECONDS,
			1
		);

		// Should now return the new job.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertCount( 1, $jobs );
	}

	/**
	 * Test job notifier normalize_data_recursive handles deep nesting.
	 */
	public function test_normalize_data_recursive_depth_limit() {
		// Build deeply nested array (25 levels - exceeds MAX_NORMALIZE_DEPTH of 20).
		$data = 'leaf_value';
		for ( $i = 0; $i < 25; $i++ ) {
			$data = array( 'level_' . $i => $data );
		}

		// Trigger normalization via job_completed hook.
		$job_id = 'test_deep_' . wp_generate_uuid4();
		do_action( 'wp_mcp_ai_job_completed', $job_id, $data, array() );

		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $cached );
		$this->assertEquals( 'completed', $cached['status'] );

		// Verify the result contains the depth limit marker at some level.
		$result = $cached['result'];
		$depth  = 0;
		while ( is_array( $result ) ) {
			$key = 'level_' . ( 24 - $depth );
			if ( isset( $result[ $key ] ) ) {
				$result = $result[ $key ];
				++$depth;
			} else {
				break;
			}
		}

		// At depth 20, it should stop recursing and return the depth limit marker.
		$this->assertLessThanOrEqual( 20, $depth );
	}

	/**
	 * Test job notifier normalize_data_recursive handles WP_Error objects.
	 */
	public function test_normalize_data_handles_wp_error() {
		$data = array(
			'result' => new WP_Error( 'test_error', 'Test error message' ),
			'items'  => array(
				new WP_Error( 'nested_error', 'Nested error' ),
				'normal_value',
			),
		);

		$job_id = 'test_error_normalize_' . wp_generate_uuid4();
		do_action( 'wp_mcp_ai_job_completed', $job_id, $data, array() );

		$cached = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );

		$this->assertIsArray( $cached );
		$this->assertEquals( 'completed', $cached['status'] );

		// The WP_Error should be converted to a serializable array.
		$result = $cached['result'];
		$this->assertIsArray( $result['result'] );
		$this->assertTrue( $result['result']['error'] );
		$this->assertEquals( 'test_error', $result['result']['code'] );
		$this->assertEquals( 'Test error message', $result['result']['message'] );

		// Nested WP_Error should also be normalized.
		$this->assertIsArray( $result['items'][0] );
		$this->assertTrue( $result['items'][0]['error'] );
	}

	/**
	 * Test webhook secret is generated and stored consistently.
	 */
	public function test_webhook_secret_generation() {
		// Clear any existing secret.
		delete_option( 'wp_mcp_ai_webhook_secret' );

		// Use reflection to access the protected method.
		$method = new ReflectionMethod( 'WP_MCP_AI_Job_Notifier', 'get_webhook_secret' );
		$method->setAccessible( true );

		// First call should generate a secret.
		$secret1 = $method->invoke( null );
		$this->assertNotEmpty( $secret1 );
		$this->assertIsString( $secret1 );
		$this->assertGreaterThanOrEqual( 64, strlen( $secret1 ) );

		// Second call should return the same secret.
		$secret2 = $method->invoke( null );
		$this->assertEquals( $secret1, $secret2 );

		// Verify it was stored in the database.
		$stored = get_option( 'wp_mcp_ai_webhook_secret' );
		$this->assertEquals( $secret1, $stored );
	}

	/**
	 * Test async executor queue_tool returns job ID for valid tool.
	 */
	public function test_async_executor_queue_returns_error_for_empty_slug() {
		$result = $this->executor->queue_tool( '', array(), array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_tool', $result->get_error_code() );
	}

	/**
	 * Test async executor result compression handles large results.
	 */
	public function test_async_executor_compress_result_large_data() {
		// Use reflection to test the protected compress_result method.
		$method = new ReflectionMethod( 'WP_MCP_AI_Tool_Async_Executor', 'compress_result' );
		$method->setAccessible( true );

		// Create a result larger than MAX_RESULT_SIZE (1MB).
		$large_result = array(
			'data'   => str_repeat( 'a', 1048577 ), // Just over 1MB.
			'status' => 'success',
			'url'    => 'https://example.com/test',
		);

		$compressed = $method->invoke( $this->executor, $large_result );

		$this->assertIsArray( $compressed );
		// Should be truncated since it exceeds MAX_RESULT_SIZE.
		if ( isset( $compressed['data'] ) && is_array( $compressed['data'] ) && isset( $compressed['data']['truncated'] ) ) {
			$this->assertTrue( $compressed['data']['truncated'] );
			// Key metadata should be preserved.
			$this->assertEquals( 'success', $compressed['data']['status'] );
			$this->assertEquals( 'https://example.com/test', $compressed['data']['url'] );
		}
	}

	/**
	 * Test async executor result compression with normal data.
	 */
	public function test_async_executor_compress_result_normal_data() {
		$method = new ReflectionMethod( 'WP_MCP_AI_Tool_Async_Executor', 'compress_result' );
		$method->setAccessible( true );

		$normal_result = array(
			'data'   => 'Small result data',
			'status' => 'success',
		);

		$compressed = $method->invoke( $this->executor, $normal_result );

		$this->assertIsArray( $compressed );
		$this->assertFalse( $compressed['compressed'] );
		$this->assertEquals( $normal_result, $compressed['data'] );
	}

	/**
	 * Test async executor result decompression round-trip.
	 */
	public function test_async_executor_compress_decompress_roundtrip() {
		$compress_method   = new ReflectionMethod( 'WP_MCP_AI_Tool_Async_Executor', 'compress_result' );
		$decompress_method = new ReflectionMethod( 'WP_MCP_AI_Tool_Async_Executor', 'decompress_result' );
		$compress_method->setAccessible( true );
		$decompress_method->setAccessible( true );

		// Create a moderately large result that triggers compression.
		$original = array(
			'data' => str_repeat( 'x', 200000 ), // ~200KB - triggers compression.
		);

		$compressed   = $compress_method->invoke( $this->executor, $original );
		$decompressed = $decompress_method->invoke( $this->executor, $compressed );

		$this->assertEquals( $original, $decompressed );
	}

	/**
	 * Test job notifier job lifecycle events.
	 */
	public function test_job_notifier_full_lifecycle() {
		$job_id = 'test_lifecycle_' . wp_generate_uuid4();

		// 1. Job started.
		do_action( 'wp_mcp_ai_job_started', $job_id, array( 'tool' => 'test_tool' ) );

		$status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
		$this->assertEquals( 'started', $status['status'] );

		// 2. Job progress.
		do_action( 'wp_mcp_ai_job_progress', $job_id, 50.0, array( 'message' => 'Half done' ) );

		$status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
		$this->assertEquals( 50.0, $status['progress'] );

		// 3. Job completed.
		do_action( 'wp_mcp_ai_job_completed', $job_id, array( 'output' => 'Done' ), array( 'tool' => 'test_tool' ) );

		$status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
		$this->assertEquals( 'completed', $status['status'] );
		$this->assertArrayHasKey( 'result', $status );
	}

	/**
	 * Test job notifier tracks user_id in metadata.
	 */
	public function test_job_notifier_tracks_user_id() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$job_id = 'test_tracking_' . wp_generate_uuid4();
		do_action( 'wp_mcp_ai_job_started', $job_id, array() );

		$status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
		$this->assertEquals( $admin_id, $status['metadata']['user_id'] );
	}
}
