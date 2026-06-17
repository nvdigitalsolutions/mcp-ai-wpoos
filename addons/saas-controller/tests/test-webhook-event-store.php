<?php
/**
 * Tests for NVOOS_SaaS_Controller_Webhook_Event_Store (Phase 7).
 *
 * @package NV_oOS_SaaS_Controller
 */

/**
 * Tests for webhook event storage and retrieval.
 *
 * @covers NVOOS_SaaS_Controller_Webhook_Event_Store
 */
class Test_NVOOS_SaaS_Controller_Webhook_Event_Store extends WP_UnitTestCase {

	/**
	 * Set up test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( NVOOS_SaaS_Controller_Webhook_Event_Store::OPTION );
		NVOOS_SaaS_Controller_Webhook_Event_Store::reset_for_tests();
		remove_all_filters( 'nvoos_saas_controller_webhook_events_max_entries' );
	}

	/**
	 * Tear down test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( NVOOS_SaaS_Controller_Webhook_Event_Store::OPTION );
		NVOOS_SaaS_Controller_Webhook_Event_Store::reset_for_tests();
		remove_all_filters( 'nvoos_saas_controller_webhook_events_max_entries' );
		parent::tearDown();
	}

	/**
	 * Test that record persists a sanitised entry.
	 *
	 * @return void
	 */
	public function test_record_persists_sanitised_entry() {
		$store = NVOOS_SaaS_Controller_Webhook_Event_Store::instance();
		$entry = $store->record(
			array(
				'provider'         => 'stripe',
				'event_id'         => 'evt_1',
				'event_type'       => 'invoice.paid',
				'timestamp'        => 1700000000,
				'signature_status' => 'verified',
				'message'          => 'Verified.',
			)
		);
		$this->assertNotNull( $entry );
		$this->assertSame( 'stripe', $entry['provider'] );
		$this->assertSame( 'evt_1', $entry['event_id'] );
		$this->assertSame( 'invoice.paid', $entry['event_type'] );
		$this->assertSame( 1700000000, $entry['event_timestamp'] );
		$this->assertSame( 'verified', $entry['signature_status'] );
		$this->assertGreaterThan( 0, $entry['ts'] );
	}

	/**
	 * Test that record rejects an unknown provider.
	 *
	 * @return void
	 */
	public function test_record_rejects_unknown_provider() {
		$store  = NVOOS_SaaS_Controller_Webhook_Event_Store::instance();
		$result = $store->record(
			array(
				'provider' => 'paypal',
				'event_id' => 'evt_1',
			)
		);
		$this->assertNull( $result );
		$this->assertSame( 0, $store->count() );
	}

	/**
	 * Test that record rejects a missing event ID.
	 *
	 * @return void
	 */
	public function test_record_rejects_missing_event_id() {
		$store  = NVOOS_SaaS_Controller_Webhook_Event_Store::instance();
		$result = $store->record(
			array(
				'provider' => 'stripe',
				'event_id' => '',
			)
		);
		$this->assertNull( $result );
		$this->assertSame( 0, $store->count() );
	}

	/**
	 * Test that record is idempotent by event ID.
	 *
	 * @return void
	 */
	public function test_record_is_idempotent_by_event_id() {
		$store = NVOOS_SaaS_Controller_Webhook_Event_Store::instance();
		$store->record(
			array(
				'provider'   => 'stripe',
				'event_id'   => 'evt_dup',
				'event_type' => 'invoice.paid',
			)
		);
		$store->record(
			array(
				'provider'   => 'stripe',
				'event_id'   => 'evt_dup',
				'event_type' => 'invoice.paid',
			)
		);
		$store->record(
			array(
				'provider'   => 'stripe',
				'event_id'   => 'evt_dup',
				'event_type' => 'invoice.paid',
			)
		);
		$this->assertSame( 1, $store->count() );
	}

	/**
	 * Test that find_by_event_id returns an existing entry.
	 *
	 * @return void
	 */
	public function test_find_by_event_id_returns_existing_entry() {
		$store = NVOOS_SaaS_Controller_Webhook_Event_Store::instance();
		$store->record(
			array(
				'provider'   => 'stripe',
				'event_id'   => 'evt_find',
				'event_type' => 'customer.created',
			)
		);
		$found = $store->find_by_event_id( 'stripe', 'evt_find' );
		$this->assertIsArray( $found );
		$this->assertSame( 'evt_find', $found['event_id'] );
		$this->assertNull( $store->find_by_event_id( 'stripe', 'evt_missing' ) );
	}

	/**
	 * Test that get_recent returns newest entries first.
	 *
	 * @return void
	 */
	public function test_get_recent_returns_newest_first() {
		$store = NVOOS_SaaS_Controller_Webhook_Event_Store::instance();
		for ( $i = 1; $i <= 3; $i++ ) {
			$store->record(
				array(
					'provider'   => 'stripe',
					'event_id'   => 'evt_' . $i,
					'event_type' => 'invoice.paid',
				)
			);
		}
		$recent = $store->get_recent( 10 );
		$this->assertCount( 3, $recent );
		$this->assertSame( 'evt_3', $recent[0]['event_id'] );
		$this->assertSame( 'evt_2', $recent[1]['event_id'] );
		$this->assertSame( 'evt_1', $recent[2]['event_id'] );
	}

	/**
	 * Test that the ring buffer caps at the filtered max.
	 *
	 * @return void
	 */
	public function test_ring_buffer_caps_at_filtered_max() {
		add_filter(
			'nvoos_saas_controller_webhook_events_max_entries',
			function () {
				return 3;
			}
		);
		$store = NVOOS_SaaS_Controller_Webhook_Event_Store::instance();
		for ( $i = 1; $i <= 5; $i++ ) {
			$store->record(
				array(
					'provider' => 'stripe',
					'event_id' => 'evt_' . $i,
				)
			);
		}
		$this->assertSame( 3, $store->count() );
		// Newest three retained: evt_3, evt_4, evt_5.
		$this->assertNull( $store->find_by_event_id( 'stripe', 'evt_1' ) );
		$this->assertNull( $store->find_by_event_id( 'stripe', 'evt_2' ) );
		$this->assertNotNull( $store->find_by_event_id( 'stripe', 'evt_5' ) );
	}

	/**
	 * Test that clear empties the store.
	 *
	 * @return void
	 */
	public function test_clear_empties_the_store() {
		$store = NVOOS_SaaS_Controller_Webhook_Event_Store::instance();
		$store->record(
			array(
				'provider' => 'stripe',
				'event_id' => 'evt_x',
			)
		);
		$this->assertSame( 1, $store->count() );
		$store->clear();
		$this->assertSame( 0, $store->count() );
	}

	/**
	 * Test that the message field is capped at 512 chars.
	 *
	 * @return void
	 */
	public function test_message_field_is_capped_at_512_chars() {
		$store = NVOOS_SaaS_Controller_Webhook_Event_Store::instance();
		$entry = $store->record(
			array(
				'provider' => 'stripe',
				'event_id' => 'evt_long',
				'message'  => str_repeat( 'a', 1000 ),
			)
		);
		$this->assertLessThanOrEqual( 512, strlen( $entry['message'] ) );
	}
}
