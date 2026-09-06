<?php
/**
 * Tests for the Job Queue Manager.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Tests for the job queue manager.
 */
class WP_MCP_AI_Job_Queue_Manager_Test extends WP_UnitTestCase {

	/**
	 * Reset worker state and the queue before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Job_Queue_Manager_Test_Worker::reset();
		WP_MCP_AI_Job_Queue_Manager::clear_queue();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Job_Queue_Manager::clear_queue();
		parent::tearDown();
	}

	/**
	 * Test enqueueing a job.
	 */
	public function test_enqueue_job() {
		$result = WP_MCP_AI_Job_Queue_Manager::enqueue_job(
			'test_job_1',
			array(
				'callable' => array( 'WP_MCP_AI_Job_Queue_Manager_Test_Worker', 'succeed' ),
				'args'     => array(),
				'priority' => WP_MCP_AI_Job_Queue_Manager::PRIORITY_HIGH,
			)
		);

		$this->assertTrue( $result );
	}

	/**
	 * Test enqueueing duplicate job fails.
	 */
	public function test_enqueue_duplicate_job() {
		$callable = array( 'WP_MCP_AI_Job_Queue_Manager_Test_Worker', 'succeed' );

		WP_MCP_AI_Job_Queue_Manager::enqueue_job(
			'test_job_1',
			array( 'callable' => $callable )
		);

		// Try to enqueue again.
		$result = WP_MCP_AI_Job_Queue_Manager::enqueue_job(
			'test_job_1',
			array( 'callable' => $callable )
		);

		$this->assertFalse( $result );
	}

	/**
	 * Test enqueueing invalid job fails.
	 */
	public function test_enqueue_invalid_job() {
		$result = WP_MCP_AI_Job_Queue_Manager::enqueue_job(
			'invalid_job',
			array( 'callable' => 'not_a_callable' )
		);

		$this->assertFalse( $result );
	}

	/**
	 * Test processing queue.
	 */
	public function test_process_queue() {
		WP_MCP_AI_Job_Queue_Manager::enqueue_job(
			'test_job',
			array( 'callable' => array( 'WP_MCP_AI_Job_Queue_Manager_Test_Worker', 'succeed' ) )
		);

		$result = WP_MCP_AI_Job_Queue_Manager::process_queue( 3 );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['processed'] );
		$this->assertTrue( WP_MCP_AI_Job_Queue_Manager_Test_Worker::$executed );
	}

	/**
	 * Test queue respects concurrency limit.
	 */
	public function test_process_queue_concurrency_limit() {
		$job_count      = 5;
		$max_concurrent = 2;

		for ( $i = 1; $i <= $job_count; ++$i ) {
			WP_MCP_AI_Job_Queue_Manager::enqueue_job(
				"job_{$i}",
				array(
					'callable' => array( 'WP_MCP_AI_Job_Queue_Manager_Test_Worker', 'succeed' ),
				)
			);
		}

		$result = WP_MCP_AI_Job_Queue_Manager::process_queue( $max_concurrent );

		$this->assertIsArray( $result );
		$this->assertLessThanOrEqual( $max_concurrent, $result['processed'] );
	}

	/**
	 * Test queue priority ordering.
	 */
	public function test_queue_priority_ordering() {
		$worker = 'WP_MCP_AI_Job_Queue_Manager_Test_Worker';

		// Enqueue jobs with different priorities.
		WP_MCP_AI_Job_Queue_Manager::enqueue_job(
			'low_priority',
			array(
				'callable' => array( $worker, 'record_low' ),
				'priority' => WP_MCP_AI_Job_Queue_Manager::PRIORITY_LOW,
			)
		);

		WP_MCP_AI_Job_Queue_Manager::enqueue_job(
			'high_priority',
			array(
				'callable' => array( $worker, 'record_high' ),
				'priority' => WP_MCP_AI_Job_Queue_Manager::PRIORITY_HIGH,
			)
		);

		WP_MCP_AI_Job_Queue_Manager::enqueue_job(
			'normal_priority',
			array(
				'callable' => array( $worker, 'record_normal' ),
				'priority' => WP_MCP_AI_Job_Queue_Manager::PRIORITY_NORMAL,
			)
		);

		// Process all jobs.
		WP_MCP_AI_Job_Queue_Manager::process_queue( 10 );

		// High priority should execute first.
		$this->assertSame( 'high', WP_MCP_AI_Job_Queue_Manager_Test_Worker::$execution_order[0] );
	}

	/**
	 * Test queue statistics.
	 */
	public function test_get_queue_stats() {
		$stats = WP_MCP_AI_Job_Queue_Manager::get_queue_stats();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total', $stats );
		$this->assertArrayHasKey( 'active', $stats );
		$this->assertArrayHasKey( 'pending', $stats );
		$this->assertArrayHasKey( 'failed', $stats );
		$this->assertSame( 0, $stats['total'] );

		// Enqueue a job.
		WP_MCP_AI_Job_Queue_Manager::enqueue_job(
			'test_job',
			array(
				'callable' => array( 'WP_MCP_AI_Job_Queue_Manager_Test_Worker', 'succeed' ),
			)
		);

		$stats = WP_MCP_AI_Job_Queue_Manager::get_queue_stats();
		$this->assertSame( 1, $stats['total'] );
		$this->assertSame( 1, $stats['pending'] );
	}

	/**
	 * Test clearing queue.
	 */
	public function test_clear_queue() {
		$worker = 'WP_MCP_AI_Job_Queue_Manager_Test_Worker';

		// Enqueue jobs.
		WP_MCP_AI_Job_Queue_Manager::enqueue_job(
			'job_1',
			array(
				'callable' => array( $worker, 'succeed' ),
			)
		);

		WP_MCP_AI_Job_Queue_Manager::enqueue_job(
			'job_2',
			array(
				'callable' => array( $worker, 'succeed' ),
			)
		);

		// Verify queue has jobs.
		$stats = WP_MCP_AI_Job_Queue_Manager::get_queue_stats();
		$this->assertSame( 2, $stats['total'] );

		// Clear queue.
		WP_MCP_AI_Job_Queue_Manager::clear_queue();

		// Verify queue is empty.
		$stats = WP_MCP_AI_Job_Queue_Manager::get_queue_stats();
		$this->assertSame( 0, $stats['total'] );
	}

	/**
	 * Test job with exception handling.
	 */
	public function test_job_exception_handling() {
		WP_MCP_AI_Job_Queue_Manager::enqueue_job(
			'exception_job',
			array( 'callable' => array( 'WP_MCP_AI_Job_Queue_Manager_Test_Worker', 'explode' ) )
		);

		$result = WP_MCP_AI_Job_Queue_Manager::process_queue( 1 );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['processed'] );

		// Job should be in failed state after retries.
		$stats = WP_MCP_AI_Job_Queue_Manager::get_queue_stats();
		$this->assertGreaterThanOrEqual( 0, $stats['failed'] );
	}

	/**
	 * Test job arguments are passed correctly.
	 */
	public function test_job_arguments() {
		WP_MCP_AI_Job_Queue_Manager::enqueue_job(
			'args_job',
			array(
				'callable' => array( 'WP_MCP_AI_Job_Queue_Manager_Test_Worker', 'record_args' ),
				'args'     => array( 'value1', 'value2' ),
			)
		);

		WP_MCP_AI_Job_Queue_Manager::process_queue( 1 );

		$this->assertIsArray( WP_MCP_AI_Job_Queue_Manager_Test_Worker::$received_args );
		$this->assertSame( array( 'value1', 'value2' ), WP_MCP_AI_Job_Queue_Manager_Test_Worker::$received_args );
	}
}
