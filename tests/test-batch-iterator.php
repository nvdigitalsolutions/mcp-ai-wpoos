<?php
/**
 * Tests for WP_MCP_AI_Batch_Iterator.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test cases for the Batch Iterator service.
 *
 * @covers WP_MCP_AI_Batch_Iterator
 */
class Test_Batch_Iterator extends WP_UnitTestCase {

	/**
	 * Clear DLQ + checkpoints before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_dead_letter_queue' );
	}

	/**
	 * Clean up checkpoint options after each test.
	 */
	public function tearDown(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp_mcp_ai_migration_checkpoint_%'" );
		delete_option( 'wp_mcp_ai_dead_letter_queue' );
		parent::tearDown();
	}

	/**
	 * Paged_iterate yields posts in chunks of the requested size.
	 */
	public function test_paged_iterate_yields_chunks() {
		$factory = $this->factory();
		$ids     = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$ids[] = $factory->post->create(
				array(
					'post_type'   => 'post',
					'post_status' => 'publish',
				)
			);
		}

		$iterator = new WP_MCP_AI_Batch_Iterator( 'test-paged-' . wp_rand() );

		$batches = array();
		foreach ( $iterator->paged_iterate(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'orderby'     => 'ID',
				'order'       => 'ASC',
			),
			5
		) as $batch ) {
			$batches[] = count( $batch );
		}

		// 12 items in batches of 5 => 5,5,2.
		$this->assertSame( array( 5, 5, 2 ), $batches );
	}

	/**
	 * Seek_iterate advances the cursor monotonically.
	 */
	public function test_seek_iterate_advances_cursor() {
		global $wpdb;
		$factory = $this->factory();

		$created = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$created[] = $factory->post->create(
				array(
					'post_type'   => 'post',
					'post_status' => 'publish',
					'post_title'  => 'seek-test-' . $i,
				)
			);
		}
		sort( $created );

		$iterator = new WP_MCP_AI_Batch_Iterator( 'test-seek-' . wp_rand() );

		$seen = array();
		foreach ( $iterator->seek_iterate( $wpdb->posts, 'ID', "post_title LIKE 'seek-test-%'", 3 ) as $rows ) {
			foreach ( $rows as $row ) {
				$seen[] = (int) $row->ID;
			}
		}

		$this->assertSame( $created, $seen, 'All matching rows should be visited in id order.' );
		$cp = $iterator->get_checkpoint();
		$this->assertGreaterThanOrEqual( max( $created ), (int) $cp['last_id'] );
	}

	/**
	 * Resume rehydrates last_id from the persisted checkpoint.
	 */
	public function test_checkpoint_persists_and_resumes() {
		$run_id   = 'test-resume-' . wp_rand();
		$iterator = new WP_MCP_AI_Batch_Iterator( $run_id );
		$iterator->set_last_id( 4242 );
		$iterator->save_checkpoint();

		$resumed = WP_MCP_AI_Batch_Iterator::resume( $run_id );
		$cp      = $resumed->get_checkpoint();

		$this->assertSame( 4242, (int) $cp['last_id'] );

		$resumed->complete();
		$this->assertFalse( get_option( 'wp_mcp_ai_migration_checkpoint_' . sanitize_key( $run_id ) ) );
	}

	/**
	 * Process_item routes failures to the DLQ instead of aborting.
	 */
	public function test_process_item_isolates_failures_to_dlq() {
		$iterator = new WP_MCP_AI_Batch_Iterator( 'test-dlq-' . wp_rand() );

		$ok = $iterator->process_item(
			1,
			static function () {
				return true;
			}
		);
		$this->assertTrue( $ok );

		$bad = $iterator->process_item(
			2,
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);
		$this->assertFalse( $bad );

		$cp = $iterator->get_checkpoint();
		$this->assertSame( 1, (int) $cp['processed'] );
		$this->assertSame( 1, (int) $cp['errors'] );

		$dlq = WP_MCP_AI_Dead_Letter_Queue::get_all();
		$this->assertNotEmpty( $dlq, 'DLQ should contain the failed item.' );

		$found = false;
		foreach ( $dlq as $entry ) {
			if ( false !== strpos( $entry['failure_reason'], 'boom' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'DLQ entry should record the exception message.' );
	}

	/**
	 * WP_Error returned from a callback is treated as a failure.
	 */
	public function test_process_item_handles_wp_error() {
		$iterator = new WP_MCP_AI_Batch_Iterator(
			'test-wperror-' . wp_rand(),
			array( 'dlq_enabled' => false )
		);

		$result = $iterator->process_item(
			'item-1',
			static function () {
				return new WP_Error( 'failed', 'nope' );
			}
		);

		$this->assertFalse( $result );
		$cp = $iterator->get_checkpoint();
		$this->assertSame( 0, (int) $cp['processed'] );
		$this->assertSame( 1, (int) $cp['errors'] );
	}

	/**
	 * Max_items hard ceiling stops paged iteration early.
	 */
	public function test_paged_iterate_honours_max_items() {
		$factory = $this->factory();
		for ( $i = 0; $i < 8; $i++ ) {
			$factory->post->create(
				array(
					'post_type'   => 'post',
					'post_status' => 'publish',
				)
			);
		}

		$iterator = new WP_MCP_AI_Batch_Iterator(
			'test-max-' . wp_rand(),
			array( 'max_items' => 3 )
		);

		$count = 0;
		foreach ( $iterator->paged_iterate(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			),
			3
		) as $batch ) {
			$count += count( $batch );
		}

		// First batch (size 3) yields 3 items, then loop stops because max_items reached.
		$this->assertSame( 3, $count );
	}

	/**
	 * Batch size is clamped to a sane range.
	 */
	public function test_batch_size_filter_is_applied() {
		$captured = null;
		add_filter(
			'wp_mcp_ai_batch_size',
			static function ( $size, $run_id ) use ( &$captured ) {
				$captured = array( $size, $run_id );
				return $size;
			},
			10,
			2
		);

		$iterator = new WP_MCP_AI_Batch_Iterator( 'test-filter' );
		$iterator->paged_iterate( array( 'post_type' => 'post' ), 25 )->current(); // trigger.

		$this->assertNotNull( $captured );
		$this->assertSame( 25, $captured[0] );
		$this->assertSame( 'test-filter', $captured[1] );
	}
}
