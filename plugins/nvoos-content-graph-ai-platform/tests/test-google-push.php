<?php
/**
 * Google Calendar push port tests (Wave E4, sub-cluster 3).
 *
 * Characterization suite for the ported `GoogleCalendarPush`: the
 * `rest_api_init` route registration, the header-token verification
 * permission callback with its error codes, the high-water-mark
 * deduplicated notification handler that defers the read to a one-off
 * scheduled sync, the HTTPS / public-host eligibility gate with its
 * filter, the channel record lifecycle, the write-before-watch flow,
 * the credential-loss-safe teardown, and the replacement-first renewal
 * callback. HTTP is intercepted via `pre_http_request`. Runs in both
 * matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Google\GoogleCalendarPush;
use NvoosContentGraphAiPlatform\Google\GoogleCalendarSync;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixture shares this file with its test case.

/**
 * Seam exposing protected members for contract testing.
 */
class GoogleCalendarPushSeam extends GoogleCalendarPush {

	/**
	 * Expose save_channel().
	 *
	 * @param string $channel_id Channel identifier.
	 * @param array  $record     Channel record.
	 * @return void
	 */
	public static function seam_save_channel( $channel_id, array $record ) {
		self::save_channel( $channel_id, $record );
	}

	/**
	 * Expose forget_channel().
	 *
	 * @param string $channel_id Channel identifier.
	 * @return void
	 */
	public static function seam_forget_channel( $channel_id ) {
		self::forget_channel( $channel_id );
	}
}

/**
 * Google Calendar push receiver characterization.
 */
class Test_Google_Push extends \WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( GoogleCalendarPush::CHANNELS_OPTION );
		delete_option( GoogleCalendarSync::STATE_OPTION );
		delete_option( 'wp_mcp_ai_settings' );

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
		delete_option( GoogleCalendarPush::CHANNELS_OPTION );
		delete_option( GoogleCalendarSync::STATE_OPTION );

		parent::tearDown();
	}

	// Route registration.

	/**
	 * The webhook route registers on `rest_api_init` under the documented
	 * namespace and route.
	 */
	public function test_webhook_route_registers_on_rest_api_init() {
		$push = new GoogleCalendarPush();

		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/' . GoogleCalendarPush::REST_NAMESPACE . GoogleCalendarPush::REST_ROUTE, $routes );
		unset( $push );
	}

	// Verification.

	/**
	 * Missing notification headers are rejected as unauthenticated.
	 */
	public function test_verify_rejects_missing_headers() {
		$push   = new GoogleCalendarPush();
		$result = $push->verify_notification( new \WP_REST_Request() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_calendar_push_unauthenticated', $result->get_error_code() );
	}

	/**
	 * An unknown channel is rejected, because there is nothing to authenticate
	 * against.
	 */
	public function test_verify_rejects_unknown_channel() {
		$push    = new GoogleCalendarPush();
		$request = $this->notification_request( 'unknown-channel', 'token_1' );

		$result = $push->verify_notification( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_calendar_push_unknown_channel', $result->get_error_code() );
	}

	/**
	 * A mismatched token is rejected even when the channel is known.
	 */
	public function test_verify_rejects_token_mismatch() {
		GoogleCalendarPushSeam::seam_save_channel(
			'chan_1',
			$this->channel_record( 'chan_1', 'real-token' )
		);

		$push    = new GoogleCalendarPush();
		$request = $this->notification_request( 'chan_1', 'wrong-token' );

		$result = $push->verify_notification( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_calendar_push_bad_token', $result->get_error_code() );
	}

	/**
	 * A matching channel + token pair authenticates.
	 */
	public function test_verify_accepts_a_matching_token() {
		GoogleCalendarPushSeam::seam_save_channel(
			'chan_1',
			$this->channel_record( 'chan_1', 'real-token' )
		);

		$push    = new GoogleCalendarPush();
		$request = $this->notification_request( 'chan_1', 'real-token' );

		$this->assertTrue( $push->verify_notification( $request ) );
	}

	// Notification handling.

	/**
	 * The `sync` handshake is acknowledged without scheduling work.
	 */
	public function test_sync_handshake_is_acknowledged_only() {
		$push    = new GoogleCalendarPush();
		$request = $this->notification_request( 'chan_1', 'token', 'sync' );

		$response = $push->handle_notification( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'acknowledged' => true ), $response->get_data() );
		$this->assertFalse( wp_next_scheduled( GoogleCalendarSync::SYNC_HOOK, array( '', 'primary' ) ) );
	}

	/**
	 * An `exists` notification schedules a one-off incremental sync for the
	 * channel's target, deferred 5 seconds.
	 */
	public function test_exists_notification_schedules_a_sync() {
		GoogleCalendarPushSeam::seam_save_channel(
			'chan_1',
			$this->channel_record( 'chan_1', 'token', 'conn_a', 'team@group.calendar.google.com' )
		);

		$push    = new GoogleCalendarPush();
		$request = $this->notification_request( 'chan_1', 'token', 'exists', 10 );

		$response = $push->handle_notification( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotFalse(
			wp_next_scheduled( GoogleCalendarSync::SYNC_HOOK, array( 'conn_a', 'team@group.calendar.google.com' ) )
		);
	}

	/**
	 * A message number at or below the high-water mark is a duplicate and must
	 * not re-schedule work. This absorbs the channel-renewal overlap window.
	 */
	public function test_duplicate_notification_is_absorbed() {
		GoogleCalendarPushSeam::seam_save_channel(
			'chan_1',
			$this->channel_record( 'chan_1', 'token', 'conn_a', 'primary' )
		);

		set_transient( 'wp_mcp_ai_gcal_msgnum_' . md5( 'chan_1' ), 10, HOUR_IN_SECONDS );

		$push    = new GoogleCalendarPush();
		$request = $this->notification_request( 'chan_1', 'token', 'exists', 5 );

		$response = $push->handle_notification( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'duplicate' => true ), $response->get_data() );
		$this->assertFalse( wp_next_scheduled( GoogleCalendarSync::SYNC_HOOK, array( 'conn_a', 'primary' ) ) );
	}

	// Eligibility.

	/**
	 * The test environment is served over HTTP, so push eligibility must fail
	 * with the requires-https error rather than silently claiming readiness.
	 */
	public function test_eligibility_rejects_non_https() {
		$result = GoogleCalendarPush::is_push_eligible();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_calendar_push_requires_https', $result->get_error_code() );
	}

	/**
	 * The eligibility filter can force push readiness, which the watch flow
	 * relies on for local HTTPS reverse-proxied sites. The hard HTTPS gate
	 * still fires first, so the test rewrites the home URL to HTTPS.
	 */
	public function test_eligibility_filter_forces_readiness() {
		$this->force_https_home();
		add_filter( 'wp_mcp_ai_google_calendar_push_eligible', '__return_true' );

		$this->assertTrue( GoogleCalendarPush::is_push_eligible() );
	}

	// Channel lifecycle.

	/**
	 * Channel records round-trip through the options store.
	 */
	public function test_channel_records_round_trip() {
		GoogleCalendarPushSeam::seam_save_channel(
			'chan_1',
			$this->channel_record( 'chan_1', 'token', 'conn_a', 'primary' )
		);

		$channels = GoogleCalendarPush::get_channels();
		$this->assertCount( 1, $channels );

		$channel = GoogleCalendarPush::get_channel( 'chan_1' );
		$this->assertSame( 'token', $channel['token'] );
		$this->assertSame( 'conn_a', $channel['connection_id'] );

		GoogleCalendarPushSeam::seam_forget_channel( 'chan_1' );

		$this->assertSame( array(), GoogleCalendarPush::get_channel( 'chan_1' ) );
	}

	// Watch flow.

	/**
	 * `watch` writes the channel record before calling Google (surviving the
	 * sync-handshake race) and stores the returned resource/expiration both in
	 * the record and in the sync state.
	 */
	public function test_watch_writes_channel_before_the_api_call() {
		$this->force_https_home();
		add_filter( 'wp_mcp_ai_google_calendar_push_eligible', '__return_true' );
		add_filter(
			'wp_mcp_ai_google_calendar_access_token',
			function () {
				return 'literal-token';
			}
		);

		$record_written_before_call = false;

		add_filter(
			'pre_http_request',
			function () use ( &$record_written_before_call ) {
				$record_written_before_call = count( GoogleCalendarPush::get_channels() ) > 0;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'resourceId' => 'res_1',
							'expiration' => (string) ( ( time() + 604800 ) * 1000 ),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$record = GoogleCalendarPush::watch( '', 'primary' );

		$this->assertIsArray( $record );
		$this->assertTrue( $record_written_before_call, 'The channel must be persisted before watch is called.' );
		$this->assertSame( 'res_1', $record['resource_id'] );
		$this->assertGreaterThan( time(), $record['expiration'] );

		$this->assertCount( 1, GoogleCalendarPush::get_channels() );

		$state = GoogleCalendarSync::get_state( '', 'primary' );
		$this->assertSame( $record['channel_id'], $state['channel_id'] );
		$this->assertSame( 'res_1', $state['channel_resource_id'] );
	}

	/**
	 * A failed `watch` forgets the pre-written channel so the store never
	 * keeps records Google does not know about.
	 */
	public function test_failed_watch_forgets_the_channel() {
		$this->force_https_home();
		add_filter( 'wp_mcp_ai_google_calendar_push_eligible', '__return_true' );
		add_filter(
			'wp_mcp_ai_google_calendar_access_token',
			function () {
				return 'literal-token';
			}
		);

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 403 ),
					'body'     => wp_json_encode( array( 'error' => array( 'errors' => array( array( 'reason' => 'forbidden' ) ) ) ) ),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = GoogleCalendarPush::watch( '', 'primary' );

		$this->assertWPError( $result );
		$this->assertSame( array(), GoogleCalendarPush::get_channels() );
	}

	/**
	 * An ineligible site fails `watch` before any credential work.
	 */
	public function test_watch_requires_eligibility() {
		$result = GoogleCalendarPush::watch( '', 'primary' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_calendar_push_requires_https', $result->get_error_code() );
	}

	// Teardown.

	/**
	 * Stopping an unknown channel fails with the documented code.
	 */
	public function test_stop_unknown_channel_fails() {
		$result = GoogleCalendarPush::stop( 'never-existed' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_calendar_push_channel_not_found', $result->get_error_code() );
	}

	/**
	 * A stopped channel is removed locally and its high-water mark cleared.
	 */
	public function test_stop_removes_the_channel_and_dedupe_mark() {
		GoogleCalendarPushSeam::seam_save_channel(
			'chan_1',
			$this->channel_record( 'chan_1', 'token', '', 'primary' )
		);
		set_transient( 'wp_mcp_ai_gcal_msgnum_' . md5( 'chan_1' ), 5, HOUR_IN_SECONDS );

		add_filter(
			'wp_mcp_ai_google_calendar_access_token',
			function () {
				return 'literal-token';
			}
		);

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$this->assertTrue( GoogleCalendarPush::stop( 'chan_1' ) );
		$this->assertSame( array(), GoogleCalendarPush::get_channels() );
		$this->assertFalse( get_transient( 'wp_mcp_ai_gcal_msgnum_' . md5( 'chan_1' ) ) );
	}

	/**
	 * When credentials are gone, teardown must still drop the local record
	 * rather than leaving an unreferenceable channel behind.
	 */
	public function test_stop_drops_the_record_when_credentials_are_gone() {
		GoogleCalendarPushSeam::seam_save_channel(
			'chan_1',
			$this->channel_record( 'chan_1', 'token', '', 'primary' )
		);

		$result = GoogleCalendarPush::stop( 'chan_1' );

		$this->assertWPError( $result, 'Credential resolution fails, but the record must still be dropped.' );
		$this->assertSame( array(), GoogleCalendarPush::get_channels() );
	}

	/**
	 * `stop_all_for_connection` targets only the owning connection.
	 */
	public function test_stop_all_scopes_to_the_connection() {
		GoogleCalendarPushSeam::seam_save_channel(
			'chan_1',
			$this->channel_record( 'chan_1', 'token', 'conn_a', 'primary' )
		);
		GoogleCalendarPushSeam::seam_save_channel(
			'chan_2',
			$this->channel_record( 'chan_2', 'token', 'conn_b', 'primary' )
		);

		// No credentials configured: both stops fail resolution but still
		// drop their local records; the count reflects the connection scope.
		$stopped = GoogleCalendarPush::stop_all_for_connection( 'conn_a' );

		$this->assertSame( 1, $stopped );
		$this->assertSame( array(), GoogleCalendarPush::get_channel( 'chan_1' ) );
		$this->assertSame( 'token', GoogleCalendarPush::get_channel( 'chan_2' )['token'] );
	}

	// Renewal.

	/**
	 * Renewal only replaces channels inside the threshold; a failed
	 * replacement leaves the old channel in place so a transient failure
	 * cannot silently end notifications.
	 */
	public function test_failed_renewal_keeps_the_old_channel() {
		GoogleCalendarPushSeam::seam_save_channel(
			'chan_1',
			array_merge(
				$this->channel_record( 'chan_1', 'token', '', 'primary' ),
				array( 'expiration' => 0 )
			)
		);

		// Ineligible site: the replacement watch fails immediately.
		GoogleCalendarPush::renew_expiring_channels();

		$this->assertSame( 'token', GoogleCalendarPush::get_channel( 'chan_1' )['token'] );
	}

	/**
	 * Channels with plenty of remaining life are not touched.
	 */
	public function test_renewal_skips_healthy_channels() {
		GoogleCalendarPushSeam::seam_save_channel(
			'chan_1',
			array_merge(
				$this->channel_record( 'chan_1', 'token', '', 'primary' ),
				array( 'expiration' => time() + GoogleCalendarPush::CHANNEL_TTL )
			)
		);

		$watch_called = false;

		add_filter( 'wp_mcp_ai_google_calendar_push_eligible', '__return_true' );
		add_filter(
			'pre_http_request',
			function () use ( &$watch_called ) {
				$watch_called = true;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'resourceId' => 'res_1' ) ),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		GoogleCalendarPush::renew_expiring_channels();

		$this->assertFalse( $watch_called, 'Healthy channels must not trigger a watch call.' );
	}

	// Helpers.

	/**
	 * Rewrite the home/siteurl options to HTTPS so the eligibility gate can
	 * reach its final filter stage.
	 *
	 * @return void
	 */
	protected function force_https_home() {
		update_option( 'siteurl', 'https://example.org' );
		update_option( 'home', 'https://example.org' );
	}

	/**
	 * Build a notification request with the Google push headers.
	 *
	 * @param string $channel_id     Channel identifier.
	 * @param string $token          Channel token.
	 * @param string $resource_state Resource state header.
	 * @param int    $message_number Message number header.
	 * @return \WP_REST_Request
	 */
	protected function notification_request( $channel_id, $token, $resource_state = 'sync', $message_number = 1 ) {
		$request = new \WP_REST_Request();
		$request->set_header( 'x_goog_channel_id', $channel_id );
		$request->set_header( 'x_goog_channel_token', $token );
		$request->set_header( 'x_goog_resource_state', $resource_state );
		$request->set_header( 'x_goog_message_number', (string) $message_number );

		return $request;
	}

	/**
	 * Build a channel record.
	 *
	 * @param string $channel_id    Channel identifier.
	 * @param string $token         Channel token.
	 * @param string $connection_id Connection ID.
	 * @param string $calendar_id   Calendar identifier.
	 * @return array<string,mixed>
	 */
	protected function channel_record( $channel_id, $token, $connection_id = '', $calendar_id = 'primary' ) {
		return array(
			'channel_id'    => $channel_id,
			'token'         => $token,
			'connection_id' => $connection_id,
			'calendar_id'   => $calendar_id,
			'resource_id'   => '',
			'expiration'    => time() + GoogleCalendarPush::CHANNEL_TTL,
			'created_at'    => time(),
		);
	}
}
