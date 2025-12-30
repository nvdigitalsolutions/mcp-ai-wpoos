<?php
/**
 * Tests for WP_MCP_AI_Crawler class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for crawler coordinator.
 *
 * @group crawler
 */
class WP_MCP_AI_Crawler_Tests extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clear any existing jobs.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp_mcp_ai_crawl4ai_job_%'" );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clear all cron events.
		$timestamp = wp_next_scheduled( WP_MCP_AI_Crawler::CRON_HOOK, array( 'test_task_123' ) );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, WP_MCP_AI_Crawler::CRON_HOOK, array( 'test_task_123' ) );
		}

		// Clear jobs.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp_mcp_ai_crawl4ai_job_%'" );

		parent::tearDown();
	}

	/**
	 * Test init registers hooks.
	 */
	public function test_init_registers_hooks() {
		WP_MCP_AI_Crawler::init();

		$this->assertTrue(
			has_action( WP_MCP_AI_Crawler::CRON_HOOK, array( 'WP_MCP_AI_Crawler', 'handle_poll_event' ) ) !== false
		);
	}

	/**
	 * Test register_remote_job with valid data.
	 */
	public function test_register_remote_job_success() {
		$task_id  = 'test_task_123';
		$job_args = array(
			'base_url'      => 'http://example.com/api',
			'arguments'     => array( 'url' => 'https://test.com' ),
			'context'       => array( 'user_id' => 1 ),
			'poll_interval' => 30,
			'wait_timeout'  => 600,
		);

		$result = WP_MCP_AI_Crawler::register_remote_job( $task_id, $job_args );

		$this->assertTrue( $result );
	}

	/**
	 * Test register_remote_job with empty task ID.
	 */
	public function test_register_remote_job_empty_task_id() {
		$job_args = array(
			'base_url' => 'http://example.com/api',
		);

		$result = WP_MCP_AI_Crawler::register_remote_job( '', $job_args );

		$this->assertFalse( $result );
	}

	/**
	 * Test register_remote_job with empty base URL.
	 */
	public function test_register_remote_job_empty_base_url() {
		$task_id  = 'test_task_123';
		$job_args = array();

		$result = WP_MCP_AI_Crawler::register_remote_job( $task_id, $job_args );

		$this->assertFalse( $result );
	}

	/**
	 * Test register_remote_job with invalid base URL.
	 */
	public function test_register_remote_job_invalid_base_url() {
		$task_id  = 'test_task_123';
		$job_args = array(
			'base_url' => 'not a valid url',
		);

		$result = WP_MCP_AI_Crawler::register_remote_job( $task_id, $job_args );

		// Should sanitize to empty and return false.
		$this->assertFalse( $result );
	}

	/**
	 * Test register_remote_job applies default poll interval.
	 */
	public function test_register_remote_job_default_poll_interval() {
		$task_id  = 'test_task_123';
		$job_args = array(
			'base_url' => 'http://example.com/api',
		);

		$result = WP_MCP_AI_Crawler::register_remote_job( $task_id, $job_args );
		$this->assertTrue( $result );

		$job = WP_MCP_AI_Crawler::get_job_status( $task_id );
		$this->assertIsArray( $job );
		$this->assertEquals( WP_MCP_AI_Crawler::DEFAULT_POLL_INTERVAL, $job['poll_interval'] );
	}

	/**
	 * Test register_remote_job applies default max runtime.
	 */
	public function test_register_remote_job_default_max_runtime() {
		$task_id  = 'test_task_123';
		$job_args = array(
			'base_url' => 'http://example.com/api',
		);

		$result = WP_MCP_AI_Crawler::register_remote_job( $task_id, $job_args );
		$this->assertTrue( $result );

		$job = WP_MCP_AI_Crawler::get_job_status( $task_id );
		$this->assertIsArray( $job );
		$this->assertEquals( WP_MCP_AI_Crawler::DEFAULT_MAX_RUNTIME, $job['max_runtime'] );
	}

	/**
	 * Test register_remote_job with custom poll interval.
	 */
	public function test_register_remote_job_custom_poll_interval() {
		$task_id  = 'test_task_123';
		$job_args = array(
			'base_url'      => 'http://example.com/api',
			'poll_interval' => 60,
		);

		WP_MCP_AI_Crawler::register_remote_job( $task_id, $job_args );

		$job = WP_MCP_AI_Crawler::get_job_status( $task_id );
		$this->assertEquals( 60, $job['poll_interval'] );
	}

	/**
	 * Test register_remote_job enforces minimum poll interval.
	 */
	public function test_register_remote_job_minimum_poll_interval() {
		$task_id  = 'test_task_123';
		$job_args = array(
			'base_url'      => 'http://example.com/api',
			'poll_interval' => 1, // Too low.
		);

		WP_MCP_AI_Crawler::register_remote_job( $task_id, $job_args );

		$job = WP_MCP_AI_Crawler::get_job_status( $task_id );
		$this->assertGreaterThanOrEqual( 5, $job['poll_interval'] );
	}

	/**
	 * Test register_remote_job enforces minimum max runtime.
	 */
	public function test_register_remote_job_minimum_max_runtime() {
		$task_id  = 'test_task_123';
		$job_args = array(
			'base_url'     => 'http://example.com/api',
			'wait_timeout' => 10, // Too low.
		);

		WP_MCP_AI_Crawler::register_remote_job( $task_id, $job_args );

		$job = WP_MCP_AI_Crawler::get_job_status( $task_id );
		$this->assertGreaterThanOrEqual( 60, $job['max_runtime'] );
	}

	/**
	 * Test get_job_status with non-existent job.
	 */
	public function test_get_job_status_nonexistent() {
		$job = WP_MCP_AI_Crawler::get_job_status( 'nonexistent_task' );
		$this->assertNull( $job );
	}

	/**
	 * Test get_job_status returns correct structure.
	 */
	public function test_get_job_status_structure() {
		$task_id  = 'test_task_123';
		$job_args = array(
			'base_url'  => 'http://example.com/api',
			'arguments' => array( 'url' => 'https://test.com' ),
			'context'   => array( 'user_id' => 1 ),
		);

		WP_MCP_AI_Crawler::register_remote_job( $task_id, $job_args );
		$job = WP_MCP_AI_Crawler::get_job_status( $task_id );

		$this->assertIsArray( $job );
		$this->assertArrayHasKey( 'task_id', $job );
		$this->assertArrayHasKey( 'base_url', $job );
		$this->assertArrayHasKey( 'status', $job );
		$this->assertArrayHasKey( 'created_at', $job );
		$this->assertArrayHasKey( 'updated_at', $job );
		$this->assertArrayHasKey( 'poll_interval', $job );
		$this->assertArrayHasKey( 'max_runtime', $job );
		$this->assertArrayHasKey( 'arguments', $job );
		$this->assertArrayHasKey( 'context', $job );
	}

	/**
	 * Test job stores arguments correctly.
	 */
	public function test_job_stores_arguments() {
		$task_id   = 'test_task_123';
		$arguments = array(
			'url'    => 'https://test.com',
			'option' => 'value',
		);

		$job_args = array(
			'base_url'  => 'http://example.com/api',
			'arguments' => $arguments,
		);

		WP_MCP_AI_Crawler::register_remote_job( $task_id, $job_args );
		$job = WP_MCP_AI_Crawler::get_job_status( $task_id );

		$this->assertEquals( $arguments, $job['arguments'] );
	}

	/**
	 * Test job stores context correctly.
	 */
	public function test_job_stores_context() {
		$task_id = 'test_task_123';
		$context = array(
			'user_id'      => 1,
			'assistant_id' => 5,
		);

		$job_args = array(
			'base_url' => 'http://example.com/api',
			'context'  => $context,
		);

		WP_MCP_AI_Crawler::register_remote_job( $task_id, $job_args );
		$job = WP_MCP_AI_Crawler::get_job_status( $task_id );

		$this->assertEquals( $context, $job['context'] );
	}

	/**
	 * Test class constants exist.
	 */
	public function test_class_constants() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Crawler' );

		$this->assertTrue( $reflection->hasConstant( 'JOB_STORAGE_PREFIX' ) );
		$this->assertTrue( $reflection->hasConstant( 'CRON_HOOK' ) );
		$this->assertTrue( $reflection->hasConstant( 'DEFAULT_POLL_INTERVAL' ) );
		$this->assertTrue( $reflection->hasConstant( 'DEFAULT_MAX_RUNTIME' ) );
	}

	/**
	 * Test task_id is sanitized.
	 */
	public function test_task_id_sanitization() {
		$task_id  = 'test<script>alert("xss")</script>task';
		$job_args = array(
			'base_url' => 'http://example.com/api',
		);

		WP_MCP_AI_Crawler::register_remote_job( $task_id, $job_args );

		// Task ID should be sanitized.
		$job = WP_MCP_AI_Crawler::get_job_status( 'testscriptalert(xss)/scripttask' );
		$this->assertIsArray( $job );
	}
}
