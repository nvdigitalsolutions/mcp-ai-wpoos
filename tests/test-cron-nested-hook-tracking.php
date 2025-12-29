<?php
/**
 * Tests for nested cron hook tracking in the cron manager.
 *
 * Validates that all plugin cron hooks (including nested/recursive hooks)
 * are properly tracked in WP_MCP_AI_Cron_Manager.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
require_once WP_MCP_AI_PATH . 'includes/crawler/class-wp-mcp-ai-crawler.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier.php';

/**
 * Test cron nested hook tracking.
 */
class WP_MCP_AI_Cron_Nested_Hook_Tracking_Test extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clear cron and cron manager state.
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clear cron and cron manager state.
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		parent::tearDown();
	}

	/**
	 * Test crawler register_remote_job tracks initial cron job.
	 */
	public function test_crawler_register_remote_job_tracks_cron() {
		$task_id = 'test_task_' . uniqid();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Register a crawler job.
		$result = WP_MCP_AI_Crawler::register_remote_job(
			$task_id,
			array(
				'base_url'      => 'https://example.com',
				'poll_interval' => 30,
				'context'       => array(
					'user_id' => $user_id,
				),
			)
		);

		$this->assertTrue( $result, 'Crawler job should be registered successfully' );

		// Verify cron event was scheduled.
		$scheduled = wp_next_scheduled( WP_MCP_AI_Crawler::CRON_HOOK, array( $task_id ) );
		$this->assertNotFalse( $scheduled, 'Cron event should be scheduled for crawler poll' );

		// Verify job was tracked in cron manager.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertNotEmpty( $jobs, 'Cron manager should have tracked jobs' );

		// Find the crawler job.
		$found = false;
		foreach ( $jobs as $job ) {
			if ( isset( $job['hook'] ) && WP_MCP_AI_Crawler::CRON_HOOK === $job['hook'] ) {
				$found = true;
				$this->assertEquals( $user_id, $job['created_by'], 'Job should be tracked with correct user_id' );
				$this->assertEquals( 'single', $job['schedule'], 'Job should be tracked as single event' );
				$this->assertIsArray( $job['args'], 'Job should have args' );
				$this->assertEquals( array( $task_id ), $job['args'], 'Job args should contain task_id' );
			}
		}

		$this->assertTrue( $found, 'Crawler cron job should be tracked in cron manager' );
	}

	/**
	 * Test crawler nested poll scheduling tracks subsequent cron jobs.
	 */
	public function test_crawler_nested_poll_tracks_cron() {
		$task_id = 'test_task_' . uniqid();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Create a job metadata structure similar to what the crawler uses.
		$job = array(
			'task_id'       => $task_id,
			'base_url'      => 'https://example.com',
			'status'        => 'polling',
			'poll_interval' => 30,
			'context'       => array(
				'user_id' => $user_id,
			),
		);

		// Call the protected schedule_next_poll method via reflection to test nested poll tracking.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Crawler' );
		$method     = $reflection->getMethod( 'schedule_next_poll' );
		$method->setAccessible( true );
		$method->invoke( null, $task_id, $job );

		// Verify cron event was scheduled.
		$scheduled = wp_next_scheduled( WP_MCP_AI_Crawler::CRON_HOOK, array( $task_id ) );
		$this->assertNotFalse( $scheduled, 'Nested cron event should be scheduled' );

		// Verify job was tracked in cron manager.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertNotEmpty( $jobs, 'Cron manager should track nested poll job' );

		// Find the crawler job.
		$found = false;
		foreach ( $jobs as $job_data ) {
			if ( isset( $job_data['hook'] ) && WP_MCP_AI_Crawler::CRON_HOOK === $job_data['hook'] ) {
				$found = true;
				$this->assertEquals( $user_id, $job_data['created_by'], 'Nested job should be tracked with correct user_id' );
			}
		}

		$this->assertTrue( $found, 'Crawler nested poll should be tracked in cron manager' );
	}

	/**
	 * Test job notifier webhook scheduling tracks cron jobs.
	 */
	public function test_job_notifier_webhook_tracks_cron() {
		$job_id  = 'test_job_' . uniqid();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Set current user for the webhook dispatch.
		wp_set_current_user( $user_id );

		// Register a webhook for the job.
		WP_MCP_AI_Job_Notifier::register_webhook(
			$job_id,
			'https://example.com/webhook',
			array( 'started', 'completed' )
		);

		// Trigger a job started event with user metadata.
		do_action(
			'wp_mcp_ai_job_started',
			$job_id,
			array(
				'user_id' => $user_id,
				'tool'    => 'test_tool',
			)
		);

		// Verify webhook cron event was scheduled.
		$cron              = _get_cron_array();
		$webhook_scheduled = false;
		foreach ( $cron as $timestamp => $events ) {
			if ( isset( $events['wp_mcp_ai_send_webhook'] ) ) {
				$webhook_scheduled = true;
				break;
			}
		}

		$this->assertTrue( $webhook_scheduled, 'Webhook cron event should be scheduled' );

		// Verify job was tracked in cron manager.
		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		$this->assertNotEmpty( $jobs, 'Cron manager should track webhook jobs' );

		// Find the webhook job.
		$found = false;
		foreach ( $jobs as $job_data ) {
			if ( isset( $job_data['hook'] ) && 'wp_mcp_ai_send_webhook' === $job_data['hook'] ) {
				$found = true;
				$this->assertEquals( $user_id, $job_data['created_by'], 'Webhook job should be tracked with correct user_id' );
				$this->assertEquals( 'single', $job_data['schedule'], 'Webhook job should be single event' );
			}
		}

		$this->assertTrue( $found, 'Job notifier webhook should be tracked in cron manager' );
	}

	/**
	 * Test that multiple nested polls are all tracked.
	 */
	public function test_multiple_nested_polls_tracked() {
		$task_id = 'test_task_' . uniqid();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Register initial job.
		WP_MCP_AI_Crawler::register_remote_job(
			$task_id,
			array(
				'base_url'      => 'https://example.com',
				'poll_interval' => 30,
				'context'       => array(
					'user_id' => $user_id,
				),
			)
		);

		$initial_jobs  = WP_MCP_AI_Cron_Manager::get_jobs();
		$initial_count = count( $initial_jobs );

		// Simulate a nested poll by calling schedule_next_poll again.
		$job = array(
			'task_id'       => $task_id,
			'base_url'      => 'https://example.com',
			'status'        => 'polling',
			'poll_interval' => 30,
			'context'       => array(
				'user_id' => $user_id,
			),
		);

		$reflection = new ReflectionClass( 'WP_MCP_AI_Crawler' );
		$method     = $reflection->getMethod( 'schedule_next_poll' );
		$method->setAccessible( true );

		// The initial call already scheduled a poll, so this should update the existing record.
		// Since the job_id is based on hook + args, and args contain the same task_id,.
		// it should update the existing record rather than create a new one.
		$method->invoke( null, $task_id, $job );

		$updated_jobs = WP_MCP_AI_Cron_Manager::get_jobs();

		// The count should remain the same since it's the same job_id.
		$this->assertEquals(
			$initial_count,
			count( $updated_jobs ),
			'Nested poll with same task_id should update existing job record'
		);
	}

	/**
	 * Test webhook tracking with metadata user_id.
	 */
	public function test_webhook_tracking_with_metadata_user_id() {
		$job_id  = 'test_job_' . uniqid();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Register a webhook.
		WP_MCP_AI_Job_Notifier::register_webhook(
			$job_id,
			'https://example.com/webhook',
			array( 'completed' )
		);

		// Trigger job completed with user_id in metadata.
		do_action(
			'wp_mcp_ai_job_completed',
			$job_id,
			array( 'result' => 'success' ),
			array(
				'user_id' => $user_id,
				'tool'    => 'test_tool',
			)
		);

		// Verify job was tracked with correct user_id from metadata.
		$jobs  = WP_MCP_AI_Cron_Manager::get_jobs();
		$found = false;
		foreach ( $jobs as $job_data ) {
			if ( isset( $job_data['hook'] ) && 'wp_mcp_ai_send_webhook' === $job_data['hook'] ) {
				$found = true;
				$this->assertEquals(
					$user_id,
					$job_data['created_by'],
					'Webhook should be tracked with user_id from metadata'
				);
			}
		}

		$this->assertTrue( $found, 'Webhook job should be tracked with metadata user_id' );
	}
}
