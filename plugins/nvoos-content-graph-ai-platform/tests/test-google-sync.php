<?php
/**
 * Google Calendar sync port tests (Wave E4, sub-cluster 3).
 *
 * Characterization suite for the ported `GoogleCalendarSync`: the
 * per-target state store, the full/incremental `run()` with the
 * transparent `410 fullSyncRequired` downgrade, the failure counter,
 * the cancelled-exception-aware `classify_events()`, the jittered
 * interval and per-site offset, the custom cron schedule registration,
 * the recurring scheduling, and the per-mode `get_sync_targets()`
 * enumeration (settings surface monolith-only, filter surface both).
 * HTTP is intercepted via `pre_http_request`. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Google\GoogleCalendarSync;

/**
 * Google Calendar sync engine characterization.
 */
class Test_Google_Sync extends \WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( GoogleCalendarSync::STATE_OPTION );

		// Monolith: the base admin-settings component caches its merged
		// settings statically; reset so cross-test writes are visible.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			\WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		GoogleCalendarSync::unschedule();
		delete_option( GoogleCalendarSync::STATE_OPTION );

		parent::tearDown();
	}

	// State store.

	/**
	 * State round-trips per connection and calendar without collision.
	 */
	public function test_sync_state_is_isolated_per_target() {
		GoogleCalendarSync::save_state( 'conn_a', 'primary', array( 'sync_token' => 'TOKEN_A' ) );
		GoogleCalendarSync::save_state( 'conn_b', 'primary', array( 'sync_token' => 'TOKEN_B' ) );
		GoogleCalendarSync::save_state( 'conn_a', 'other@group.calendar.google.com', array( 'sync_token' => 'TOKEN_C' ) );

		$this->assertSame( 'TOKEN_A', GoogleCalendarSync::get_state( 'conn_a', 'primary' )['sync_token'] );
		$this->assertSame( 'TOKEN_B', GoogleCalendarSync::get_state( 'conn_b', 'primary' )['sync_token'] );
		$this->assertSame( 'TOKEN_C', GoogleCalendarSync::get_state( 'conn_a', 'other@group.calendar.google.com' )['sync_token'] );

		GoogleCalendarSync::delete_state( 'conn_a', 'primary' );
		$this->assertSame( '', GoogleCalendarSync::get_state( 'conn_a', 'primary' )['sync_token'] );
		$this->assertSame( 'TOKEN_B', GoogleCalendarSync::get_state( 'conn_b', 'primary' )['sync_token'] );
	}

	/**
	 * An empty connection ID maps to the `settings` state namespace, matching
	 * the base plugin's settings-level connection.
	 */
	public function test_state_key_namespaces_the_settings_connection() {
		$key = GoogleCalendarSync::state_key( '', 'primary' );

		$this->assertStringStartsWith( 'settings|', $key );
	}

	/**
	 * Unread state fills every default key so consumers never touch undefined
	 * indexes.
	 */
	public function test_state_defaults_are_always_present() {
		$state = GoogleCalendarSync::get_state( 'conn_x', 'primary' );

		$this->assertSame( '', $state['sync_token'] );
		$this->assertSame( 0, $state['failure_count'] );
		$this->assertSame( 0, $state['last_full_sync_at'] );
		$this->assertSame( 'conn_x', $state['connection_id'] );
		$this->assertSame( 'primary', $state['calendar_id'] );
	}

	// Cancelled-event routing.

	/**
	 * A cancelled exception of a live recurring event must be retained, while a
	 * plain cancelled event must be deleted locally. Conflating the two either
	 * resurrects deleted events or makes cancelled occurrences reappear.
	 */
	public function test_cancelled_events_are_routed_by_recurring_parent() {
		$classified = GoogleCalendarSync::classify_events(
			array(
				array(
					'id'     => 'live',
					'status' => 'confirmed',
				),
				array(
					'id'                => 'suppressed_instance',
					'status'            => 'cancelled',
					'recurringEventId'  => 'series_1',
					'originalStartTime' => array( 'dateTime' => '2026-06-01T09:00:00Z' ),
				),
				array(
					'id'     => 'truly_deleted',
					'status' => 'cancelled',
				),
			)
		);

		$this->assertCount( 1, $classified['upserted'] );
		$this->assertSame( 'live', $classified['upserted'][0]['id'] );

		$this->assertCount( 1, $classified['suppressed'] );
		$this->assertSame( 'series_1', $classified['suppressed'][0]['recurring_event_id'] );
		$this->assertJson( $classified['suppressed'][0]['original_start_time'] );

		$this->assertSame( array( 'truly_deleted' ), $classified['deleted'] );
	}

	/**
	 * Events without an ID cannot be keyed locally and must be skipped.
	 */
	public function test_events_without_id_are_skipped() {
		$classified = GoogleCalendarSync::classify_events(
			array(
				array( 'status' => 'confirmed' ),
				'not-an-array',
			)
		);

		$this->assertSame( array(), $classified['upserted'] );
		$this->assertSame( array(), $classified['deleted'] );
	}

	// Scheduling.

	/**
	 * The interval must be jittered so a fleet of sites never syncs in lockstep,
	 * which Google names as a quota-exhausting anti-pattern.
	 */
	public function test_sync_interval_is_jittered_within_bounds() {
		$base   = 21600;
		$spread = (int) round( $base * 0.25 );
		$seen   = array();

		for ( $i = 0; $i < 40; $i++ ) {
			$value = GoogleCalendarSync::jittered_interval( $base );

			$this->assertGreaterThanOrEqual( $base - $spread, $value );
			$this->assertLessThanOrEqual( $base + $spread, $value );

			$seen[ $value ] = true;
		}

		$this->assertGreaterThan( 1, count( $seen ), 'Interval must actually vary.' );
	}

	/**
	 * The interval must never fall below the WP-Cron floor.
	 */
	public function test_sync_interval_has_a_floor() {
		$this->assertGreaterThanOrEqual( 300, GoogleCalendarSync::jittered_interval( 1 ) );
	}

	/**
	 * The per-site offset stays inside its window so initial syncs spread out
	 * instead of stacking at midnight.
	 */
	public function test_site_offset_stays_inside_the_window() {
		$offset = GoogleCalendarSync::site_offset( 3600 );

		$this->assertGreaterThanOrEqual( 0, $offset );
		$this->assertLessThan( 3600, $offset );
	}

	/**
	 * The custom cron schedule is registered under the documented slug with a
	 * jittered interval.
	 */
	public function test_cron_schedule_is_registered() {
		$schedules = GoogleCalendarSync::register_cron_schedule( array() );

		$this->assertArrayHasKey( 'wp_mcp_ai_google_calendar_sync_interval', $schedules );
		$this->assertGreaterThanOrEqual( 300, $schedules['wp_mcp_ai_google_calendar_sync_interval']['interval'] );
	}

	/**
	 * Scheduling the safety-net sync is idempotent and uses the custom
	 * interval slug. The cron-schedules filter is registered here directly
	 * because the test harness fires `init` before the platform plugin
	 * loads, so the bootstrap's filter registration never runs in-process.
	 */
	public function test_schedule_is_idempotent() {
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Test-only registration of the custom interval; the bootstrap filter never runs in-process because `init` fired before the platform plugin loaded.
		add_filter( 'cron_schedules', array( GoogleCalendarSync::class, 'register_cron_schedule' ) );

		$this->assertFalse( wp_next_scheduled( GoogleCalendarSync::SYNC_HOOK ) );

		GoogleCalendarSync::schedule();
		$first = wp_next_scheduled( GoogleCalendarSync::SYNC_HOOK );

		GoogleCalendarSync::schedule();
		$second = wp_next_scheduled( GoogleCalendarSync::SYNC_HOOK );

		$this->assertNotFalse( $first );
		$this->assertSame( $first, $second );

		GoogleCalendarSync::unschedule();

		$this->assertFalse( wp_next_scheduled( GoogleCalendarSync::SYNC_HOOK ) );
	}

	// Runs.

	/**
	 * A full run resolves credentials, walks the page, stores the sync token,
	 * and reports per-event counts.
	 */
	public function test_full_run_reports_and_persists() {
		$this->seed_settings();

		$this->mock_google_flow(
			array(
				'items'         => array(
					array(
						'id'     => 'evt_1',
						'status' => 'confirmed',
					),
					array(
						'id'     => 'evt_2',
						'status' => 'cancelled',
					),
				),
				'nextSyncToken' => 'TOKEN_FULL',
			)
		);

		$report = GoogleCalendarSync::run( '', 'primary', array( 'force_full' => true ) );

		$this->assertIsArray( $report );
		$this->assertSame( 'TOKEN_FULL', $report['next_sync_token'] );
		$this->assertSame( 'full', $report['mode'] );
		$this->assertCount( 1, $report['upserted'] );
		$this->assertSame( array( 'evt_2' ), $report['deleted'] );

		$state = GoogleCalendarSync::get_state( '', 'primary' );
		$this->assertSame( 'TOKEN_FULL', $state['sync_token'] );
		$this->assertSame( 0, $state['failure_count'] );
	}

	/**
	 * A stored sync token drives an incremental run.
	 */
	public function test_incremental_run_uses_the_stored_token() {
		$this->seed_settings();
		GoogleCalendarSync::save_state( '', 'primary', array( 'sync_token' => 'TOKEN_INC' ) );

		$seen_sync_token = null;

		$this->mock_google_flow(
			array(
				'items'         => array(),
				'nextSyncToken' => 'TOKEN_INC_2',
			),
			function ( $url ) use ( &$seen_sync_token ) {
				$seen_sync_token = (string) wp_parse_url( $url, PHP_URL_QUERY );
			}
		);

		$report = GoogleCalendarSync::run( '', 'primary' );

		$this->assertIsArray( $report );
		$this->assertSame( 'incremental', $report['mode'] );
		$this->assertStringContainsString( 'syncToken=TOKEN_INC', $seen_sync_token );
	}

	/**
	 * A `410 fullSyncRequired` response downgrades to a full resync within the
	 * same call and fires the invalidation action so listeners purge state.
	 */
	public function test_stale_token_downgrades_to_full_resync() {
		$this->seed_settings();
		GoogleCalendarSync::save_state( '', 'primary', array( 'sync_token' => 'STALE' ) );

		$events_calls = 0;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$events_calls ) {
				unset( $preempt, $args );

				// The refresh-token mint always succeeds.
				if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'access_token' => 'access',
								'expires_in'   => 3600,
							)
						),
						'headers'  => array(),
					);
				}

				++$events_calls;

				if ( 1 === $events_calls ) {
					return array(
						'response' => array( 'code' => 410 ),
						'body'     => wp_json_encode( array( 'error' => array( 'errors' => array( array( 'reason' => 'fullSyncRequired' ) ) ) ) ),
						'headers'  => array(),
					);
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'items'         => array( array( 'id' => 'evt_1' ) ),
							'nextSyncToken' => 'TOKEN_NEW',
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$invalidation = 0;
		add_action(
			'wp_mcp_ai_google_calendar_full_sync_required',
			function () use ( &$invalidation ) {
				++$invalidation;
			}
		);

		$report = GoogleCalendarSync::run( '', 'primary' );

		$this->assertIsArray( $report );
		$this->assertSame( 'full_after_invalidation', $report['mode'] );
		$this->assertSame( 'TOKEN_NEW', $report['next_sync_token'] );
		$this->assertSame( 2, $events_calls );
		$this->assertSame( 1, $invalidation );
	}

	/**
	 * A failing run increments the failure counter and returns the error.
	 */
	public function test_failing_run_increments_the_failure_counter() {
		$this->seed_settings();
		GoogleCalendarSync::save_state( '', 'primary', array( 'sync_token' => 'TOKEN' ) );

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				unset( $preempt, $args );

				if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'access_token' => 'access',
								'expires_in'   => 3600,
							)
						),
						'headers'  => array(),
					);
				}

				return array(
					'response' => array( 'code' => 401 ),
					'body'     => wp_json_encode( array( 'error' => array( 'errors' => array( array( 'reason' => 'authError' ) ) ) ) ),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = GoogleCalendarSync::run( '', 'primary' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_calendar_unauthorized', $result->get_error_code() );

		$state = GoogleCalendarSync::get_state( '', 'primary' );
		$this->assertSame( 1, $state['failure_count'] );
	}

	/**
	 * A run without credentials fails before any HTTP request.
	 */
	public function test_run_requires_credentials() {
		$result = GoogleCalendarSync::run( '', 'primary' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_calendar_missing_credentials', $result->get_error_code() );
	}

	// Target enumeration.

	/**
	 * Target enumeration is per-mode: the settings surface exists only in
	 * monolith installs (where the base admin-settings component is loaded);
	 * standalone installs yield only the filter surface.
	 */
	public function test_sync_targets_follow_the_per_mode_surface() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'google_calendar_refresh_token'       => 'refresh',
				'google_calendar_default_calendar_id' => 'team@group.calendar.google.com',
			)
		);

		$targets = GoogleCalendarSync::get_sync_targets();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertCount( 1, $targets );
			$this->assertSame( '', $targets[0]['connection_id'] );
			$this->assertSame( 'team@group.calendar.google.com', $targets[0]['calendar_id'] );
		} else {
			$this->assertSame( array(), $targets, 'Standalone must not probe the base admin-settings component.' );
		}
	}

	/**
	 * The sync-targets filter is honored in both modes.
	 */
	public function test_sync_targets_filter_is_honored() {
		add_filter(
			'wp_mcp_ai_google_calendar_sync_targets',
			function () {
				return array(
					array(
						'connection_id' => 'filtered',
						'calendar_id'   => 'primary',
					),
				);
			}
		);

		$targets = GoogleCalendarSync::get_sync_targets();

		$this->assertCount( 1, $targets );
		$this->assertSame( 'filtered', $targets[0]['connection_id'] );
	}

	// Helpers.

	/**
	 * Seed a fully configured settings-level connection.
	 *
	 * @return void
	 */
	protected function seed_settings() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'google_calendar_client_id'     => 'cid',
				'google_calendar_client_secret' => 'secret',
				'google_calendar_refresh_token' => 'refresh',
			)
		);
	}

	/**
	 * Short-circuit the refresh-token mint and the events list endpoint with
	 * URL-aware responses, optionally observing the events request URL.
	 *
	 * @param array<string,mixed> $events_body Events list response body.
	 * @param callable|null       $observer    Optional callback receiving the events URL.
	 * @return void
	 */
	protected function mock_google_flow( array $events_body, $observer = null ) {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $events_body, $observer ) {
				unset( $preempt, $args );

				if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'access_token' => 'access',
								'expires_in'   => 3600,
							)
						),
						'headers'  => array(),
					);
				}

				if ( is_callable( $observer ) ) {
					$observer( $url );
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $events_body ),
					'headers'  => array(),
				);
			},
			10,
			3
		);
	}
}
