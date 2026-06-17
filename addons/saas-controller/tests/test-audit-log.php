<?php
/**
 * Tests for NVOOS_SaaS_Controller_Audit_Log.
 *
 * @package NV_oOS_SaaS_Controller
 */

/**
 * Tests for audit log persistence, retrieval, and sanitisation.
 *
 * @covers NVOOS_SaaS_Controller_Audit_Log
 */
class Test_NVOOS_SaaS_Controller_Audit_Log extends WP_UnitTestCase {

	/**
	 * Set up test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		NVOOS_SaaS_Controller_Audit_Log::reset_for_tests();
		remove_all_filters( 'nvoos_saas_controller_audit_log_record' );
		remove_all_filters( 'nvoos_saas_controller_audit_log_max_entries' );
	}

	/**
	 * Tear down test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		NVOOS_SaaS_Controller_Audit_Log::reset_for_tests();
		remove_all_filters( 'nvoos_saas_controller_audit_log_record' );
		remove_all_filters( 'nvoos_saas_controller_audit_log_max_entries' );
		parent::tearDown();
	}

	/**
	 * Test that record persists a sanitised entry.
	 *
	 * @return void
	 */
	public function test_record_persists_sanitised_entry() {
		$log = NVOOS_SaaS_Controller_Audit_Log::instance();
		$entry = $log->record(
			array(
				'channel'    => 'cloudflare',
				'action'     => 'list_d1_databases',
				'target'     => 'acct123',
				'status'     => 'ok',
				'latency_ms' => 42,
				'message'    => '3 items returned.',
			)
		);
		$this->assertNotNull( $entry );
		$this->assertSame( 'cloudflare', $entry['channel'] );
		$this->assertSame( 'list_d1_databases', $entry['action'] );
		$this->assertSame( 'ok', $entry['status'] );
		$this->assertSame( 42, $entry['latency_ms'] );
		$this->assertGreaterThan( 0, $entry['ts'] );

		$recent = $log->get_recent( 10 );
		$this->assertCount( 1, $recent );
		$this->assertSame( 'cloudflare', $recent[0]['channel'] );
	}

	/**
	 * Test that invalid channel falls back to internal.
	 *
	 * @return void
	 */
	public function test_invalid_channel_falls_back_to_internal() {
		$log = NVOOS_SaaS_Controller_Audit_Log::instance();
		$entry = $log->record(
			array(
				'channel' => 'arbitrary_garbage',
				'action' => 'x',
				'status' => 'ok',
			)
		);
		$this->assertSame( 'internal', $entry['channel'] );
	}

	/**
	 * Test that invalid status falls back to error.
	 *
	 * @return void
	 */
	public function test_invalid_status_falls_back_to_error() {
		$log = NVOOS_SaaS_Controller_Audit_Log::instance();
		$entry = $log->record(
			array(
				'channel' => 'internal',
				'action' => 'x',
				'status' => 'sideways',
			)
		);
		$this->assertSame( 'error', $entry['status'] );
	}

	/**
	 * Test that message is truncated at 512 characters.
	 *
	 * @return void
	 */
	public function test_message_is_truncated_at_512_chars() {
		$log     = NVOOS_SaaS_Controller_Audit_Log::instance();
		$payload = str_repeat( 'a', 600 );
		$entry   = $log->record(
			array(
				'channel' => 'internal',
				'action' => 'x',
				'status' => 'ok',
				'message' => $payload,
			)
		);
		$this->assertSame( 512, strlen( $entry['message'] ) );
	}

	/**
	 * Test that get_recent returns newest entries first.
	 *
	 * @return void
	 */
	public function test_get_recent_returns_newest_first() {
		$log = NVOOS_SaaS_Controller_Audit_Log::instance();
		$log->record(
			array(
				'channel' => 'internal',
				'action' => 'first',
				'status' => 'ok',
			)
		);
		$log->record(
			array(
				'channel' => 'internal',
				'action' => 'second',
				'status' => 'ok',
			)
		);
		$log->record(
			array(
				'channel' => 'internal',
				'action' => 'third',
				'status' => 'ok',
			)
		);
		$recent = $log->get_recent( 10 );
		$this->assertSame( 'third', $recent[0]['action'] );
		$this->assertSame( 'second', $recent[1]['action'] );
		$this->assertSame( 'first', $recent[2]['action'] );
	}

	/**
	 * Test that the ring buffer trims oldest entries.
	 *
	 * @return void
	 */
	public function test_ring_buffer_trims_oldest_entries() {
		add_filter(
			'nvoos_saas_controller_audit_log_max_entries',
			function () {
				return 3;
			}
		);
		$log = NVOOS_SaaS_Controller_Audit_Log::instance();
		for ( $i = 0; $i < 5; $i++ ) {
			$log->record(
				array(
					'channel' => 'internal',
					'action' => 'a' . $i,
					'status' => 'ok',
				)
			);
		}
		$recent = $log->get_recent( 10 );
		$this->assertCount( 3, $recent );
		$this->assertSame( 'a4', $recent[0]['action'] );
		$this->assertSame( 'a3', $recent[1]['action'] );
		$this->assertSame( 'a2', $recent[2]['action'] );
	}

	/**
	 * Test that a filter can suppress an entry.
	 *
	 * @return void
	 */
	public function test_filter_can_suppress_entry() {
		add_filter( 'nvoos_saas_controller_audit_log_record', '__return_false' );
		$log = NVOOS_SaaS_Controller_Audit_Log::instance();
		$result = $log->record(
			array(
				'channel' => 'internal',
				'action' => 'x',
				'status' => 'ok',
			)
		);
		$this->assertNull( $result );
		$this->assertCount( 0, $log->get_recent( 10 ) );
	}

	/**
	 * Test that clear removes all entries.
	 *
	 * @return void
	 */
	public function test_clear_removes_all_entries() {
		$log = NVOOS_SaaS_Controller_Audit_Log::instance();
		$log->record(
			array(
				'channel' => 'internal',
				'action' => 'x',
				'status' => 'ok',
			)
		);
		$this->assertSame( 1, $log->count() );
		$log->clear();
		$this->assertSame( 0, $log->count() );
	}

	/**
	 * Test that a corrupt option value is ignored.
	 *
	 * @return void
	 */
	public function test_corrupt_option_value_is_ignored() {
		update_option( NVOOS_SaaS_Controller_Audit_Log::OPTION, 'not-an-array' );
		$log = NVOOS_SaaS_Controller_Audit_Log::instance();
		$this->assertSame( 0, $log->count() );
		$entry = $log->record(
			array(
				'channel' => 'internal',
				'action' => 'x',
				'status' => 'ok',
			)
		);
		$this->assertNotNull( $entry );
		$this->assertSame( 1, $log->count() );
	}

	/**
	 * Test that action is truncated at 96 characters.
	 *
	 * @return void
	 */
	public function test_action_is_truncated_at_96_chars() {
		$log   = NVOOS_SaaS_Controller_Audit_Log::instance();
		$entry = $log->record(
			array(
				'channel' => 'internal',
				'action'  => str_repeat( 'b', 200 ),
				'status'  => 'ok',
			)
		);
		$this->assertSame( 96, strlen( $entry['action'] ) );
	}

	/**
	 * Test that get_recent with offset returns correct page.
	 *
	 * @return void
	 */
	public function test_get_recent_with_offset() {
		$log = NVOOS_SaaS_Controller_Audit_Log::instance();
		for ( $i = 0; $i < 5; $i++ ) {
			$log->record(
				array(
					'channel' => 'internal',
					'action' => 'item_' . $i,
					'status' => 'ok',
				)
			);
		}
		$page = $log->get_recent( 2, 2 );
		$this->assertCount( 2, $page );
		$this->assertSame( 'item_2', $page[0]['action'] );
		$this->assertSame( 'item_1', $page[1]['action'] );
	}
}
