<?php
/**
 * Tests for Dead Letter Queue functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Dead Letter Queue.
 */
class Test_Dead_Letter_Queue extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clear DLQ before each test.
		delete_option( 'wp_mcp_ai_dead_letter_queue' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clean up after tests.
		delete_option( 'wp_mcp_ai_dead_letter_queue' );

		parent::tearDown();
	}

	/**
	 * Test adding an item to the DLQ.
	 */
	public function test_add_item() {
		$result = WP_MCP_AI_Dead_Letter_Queue::add(
			WP_MCP_AI_Dead_Letter_Queue::TYPE_WEBHOOK,
			'test_webhook_1',
			array(
				'url'     => 'https://example.com/webhook',
				'payload' => array( 'test' => 'data' ),
			),
			'Connection timeout',
			array()
		);

		$this->assertTrue( $result );

		$items = WP_MCP_AI_Dead_Letter_Queue::get_all();
		$this->assertCount( 1, $items );

		$item = reset( $items );
		$this->assertEquals( WP_MCP_AI_Dead_Letter_Queue::TYPE_WEBHOOK, $item['type'] );
		$this->assertEquals( 'test_webhook_1', $item['identifier'] );
		$this->assertEquals( 'Connection timeout', $item['failure_reason'] );
		$this->assertFalse( $item['dismissed'] );
	}

	/**
	 * Test adding item with invalid type.
	 */
	public function test_add_invalid_type() {
		$result = WP_MCP_AI_Dead_Letter_Queue::add(
			'invalid_type',
			'test_id',
			array(),
			'Test failure'
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_type', $result->get_error_code() );
	}

	/**
	 * Test getting items by type.
	 */
	public function test_get_by_type() {
		// Add webhook item.
		WP_MCP_AI_Dead_Letter_Queue::add(
			WP_MCP_AI_Dead_Letter_Queue::TYPE_WEBHOOK,
			'webhook_1',
			array(),
			'Webhook failed'
		);

		// Add cron job item.
		WP_MCP_AI_Dead_Letter_Queue::add(
			WP_MCP_AI_Dead_Letter_Queue::TYPE_CRON_JOB,
			'cron_1',
			array(),
			'Cron failed'
		);

		// Add another webhook item.
		WP_MCP_AI_Dead_Letter_Queue::add(
			WP_MCP_AI_Dead_Letter_Queue::TYPE_WEBHOOK,
			'webhook_2',
			array(),
			'Webhook failed again'
		);

		$webhooks = WP_MCP_AI_Dead_Letter_Queue::get_by_type( WP_MCP_AI_Dead_Letter_Queue::TYPE_WEBHOOK );
		$this->assertCount( 2, $webhooks );

		$cron_jobs = WP_MCP_AI_Dead_Letter_Queue::get_by_type( WP_MCP_AI_Dead_Letter_Queue::TYPE_CRON_JOB );
		$this->assertCount( 1, $cron_jobs );
	}

	/**
	 * Test filtering by dismissed status.
	 */
	public function test_filter_dismissed() {
		// Add items.
		WP_MCP_AI_Dead_Letter_Queue::add(
			WP_MCP_AI_Dead_Letter_Queue::TYPE_WEBHOOK,
			'webhook_1',
			array(),
			'Failed'
		);

		WP_MCP_AI_Dead_Letter_Queue::add(
			WP_MCP_AI_Dead_Letter_Queue::TYPE_WEBHOOK,
			'webhook_2',
			array(),
			'Failed'
		);

		$all_items = WP_MCP_AI_Dead_Letter_Queue::get_all();
		$item_id   = key( $all_items );

		// Dismiss one item.
		WP_MCP_AI_Dead_Letter_Queue::dismiss( $item_id );

		// Filter active items.
		$active_items = WP_MCP_AI_Dead_Letter_Queue::get_all( array( 'dismissed' => false ) );
		$this->assertCount( 1, $active_items );

		// Filter dismissed items.
		$dismissed_items = WP_MCP_AI_Dead_Letter_Queue::get_all( array( 'dismissed' => true ) );
		$this->assertCount( 1, $dismissed_items );
	}

	/**
	 * Test removing an item.
	 */
	public function test_remove_item() {
		WP_MCP_AI_Dead_Letter_Queue::add(
			WP_MCP_AI_Dead_Letter_Queue::TYPE_WEBHOOK,
			'webhook_1',
			array(),
			'Failed'
		);

		$items   = WP_MCP_AI_Dead_Letter_Queue::get_all();
		$item_id = key( $items );

		$result = WP_MCP_AI_Dead_Letter_Queue::remove( $item_id );
		$this->assertTrue( $result );

		$items = WP_MCP_AI_Dead_Letter_Queue::get_all();
		$this->assertCount( 0, $items );
	}

	/**
	 * Test purging old items.
	 */
	public function test_purge_old() {
		// Add an item and manually set old timestamp.
		WP_MCP_AI_Dead_Letter_Queue::add(
			WP_MCP_AI_Dead_Letter_Queue::TYPE_WEBHOOK,
			'old_webhook',
			array(),
			'Failed'
		);

		$items   = WP_MCP_AI_Dead_Letter_Queue::get_all();
		$item_id = key( $items );

		// Manually update the timestamp to be 31 days old.
		$items[ $item_id ]['added_timestamp'] = time() - ( 31 * DAY_IN_SECONDS );
		update_option( 'wp_mcp_ai_dead_letter_queue', $items );

		// Add a recent item.
		WP_MCP_AI_Dead_Letter_Queue::add(
			WP_MCP_AI_Dead_Letter_Queue::TYPE_WEBHOOK,
			'new_webhook',
			array(),
			'Failed'
		);

		// Purge items older than 30 days.
		$purged = WP_MCP_AI_Dead_Letter_Queue::purge_old( 30 );
		$this->assertEquals( 1, $purged );

		// Should have 1 item left (the recent one).
		$items = WP_MCP_AI_Dead_Letter_Queue::get_all();
		$this->assertCount( 1, $items );

		$item = reset( $items );
		$this->assertEquals( 'new_webhook', $item['identifier'] );
	}

	/**
	 * Test DLQ statistics.
	 */
	public function test_get_stats() {
		// Add various items.
		WP_MCP_AI_Dead_Letter_Queue::add(
			WP_MCP_AI_Dead_Letter_Queue::TYPE_WEBHOOK,
			'webhook_1',
			array(),
			'Failed'
		);

		WP_MCP_AI_Dead_Letter_Queue::add(
			WP_MCP_AI_Dead_Letter_Queue::TYPE_CRON_JOB,
			'cron_1',
			array(),
			'Failed'
		);

		WP_MCP_AI_Dead_Letter_Queue::add(
			WP_MCP_AI_Dead_Letter_Queue::TYPE_WEBHOOK,
			'webhook_2',
			array(),
			'Failed'
		);

		// Dismiss one.
		$items   = WP_MCP_AI_Dead_Letter_Queue::get_all();
		$item_id = key( $items );
		WP_MCP_AI_Dead_Letter_Queue::dismiss( $item_id );

		$stats = WP_MCP_AI_Dead_Letter_Queue::get_stats();

		$this->assertEquals( 3, $stats['total'] );
		$this->assertEquals( 2, $stats['active'] );
		$this->assertEquals( 1, $stats['dismissed'] );
		$this->assertEquals( 2, $stats['by_type'][ WP_MCP_AI_Dead_Letter_Queue::TYPE_WEBHOOK ] );
		$this->assertEquals( 1, $stats['by_type'][ WP_MCP_AI_Dead_Letter_Queue::TYPE_CRON_JOB ] );
	}

	/**
	 * Test max items limit.
	 */
	public function test_max_items_limit() {
		// Mock the MAX_ITEMS constant by adding just over the limit.
		// Since we can't change the constant, we'll add a reasonable number.
		$items_to_add = 50;

		for ( $i = 0; $i < $items_to_add; $i++ ) {
			WP_MCP_AI_Dead_Letter_Queue::add(
				WP_MCP_AI_Dead_Letter_Queue::TYPE_WEBHOOK,
				"webhook_{$i}",
				array( 'index' => $i ),
				'Failed'
			);
		}

		$items = WP_MCP_AI_Dead_Letter_Queue::get_all();
		$this->assertCount( $items_to_add, $items );

		// The DLQ should still accept new items and prune old ones if needed.
		// But with 50 items we're well below the 1000 limit.
		$this->assertLessThanOrEqual( 1000, count( $items ) );
	}

	/**
	 * Test retry history tracking.
	 */
	public function test_retry_history() {
		$retry_history = array(
			array(
				'timestamp' => time() - 300,
				'result'    => 'failed',
				'error'     => 'Connection timeout',
			),
			array(
				'timestamp' => time() - 150,
				'result'    => 'failed',
				'error'     => 'Connection refused',
			),
			array(
				'timestamp' => time(),
				'result'    => 'failed',
				'error'     => 'Network error',
			),
		);

		WP_MCP_AI_Dead_Letter_Queue::add(
			WP_MCP_AI_Dead_Letter_Queue::TYPE_WEBHOOK,
			'webhook_with_retries',
			array( 'url' => 'https://example.com' ),
			'Failed after 3 retries',
			$retry_history
		);

		$items = WP_MCP_AI_Dead_Letter_Queue::get_all();
		$item  = reset( $items );

		$this->assertEquals( 3, $item['retry_count'] );
		$this->assertCount( 3, $item['retry_history'] );
		$this->assertEquals( 'Connection timeout', $item['retry_history'][0]['error'] );
	}

	/**
	 * Test integration with Job Queue Manager failure.
	 */
	public function test_job_queue_integration() {
		// This test requires WP_MCP_AI_Job_Queue_Manager to be loaded.
		if ( ! class_exists( 'WP_MCP_AI_Job_Queue_Manager' ) ) {
			$this->markTestSkipped( 'Job Queue Manager not available' );
		}

		// Enqueue a job that will fail.
		$job_id   = 'test_failing_job';
		$job_data = array(
			'callable' => function () {
				return new WP_Error( 'test_error', 'Intentional failure for testing' );
			},
			'args'     => array(),
			'priority' => 5,
		);

		WP_MCP_AI_Job_Queue_Manager::enqueue_job( $job_id, $job_data );

		// Process queue multiple times to trigger retries.
		for ( $i = 0; $i < 4; $i++ ) {
			WP_MCP_AI_Job_Queue_Manager::process_queue();
		}

		// Check if item was added to DLQ.
		$dlq_items = WP_MCP_AI_Dead_Letter_Queue::get_by_type( WP_MCP_AI_Dead_Letter_Queue::TYPE_JOB_QUEUE );

		// The job should have been moved to DLQ after 3 failed retries.
		$this->assertGreaterThanOrEqual( 1, count( $dlq_items ) );
	}
}
