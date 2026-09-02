<?php
/**
 * Tests for the shared Google Calendar foundation.
 *
 * Covers the invariants that are cheap to break and expensive to debug:
 * the sync-mode parameter split, HTTP 410 discrimination, retry
 * classification, scope implication, and cancelled-event routing.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-calendar-scopes.php';
require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-oauth-service.php';
require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-calendar-client.php';
require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-calendar-sync.php';

/**
 * Google Calendar foundation test case.
 */
class Test_Google_Calendar_Foundation extends WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		// Never actually sleep during retry tests.
		add_filter( 'wp_mcp_ai_google_calendar_retry_backoff', '__return_zero' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_google_calendar_retry_backoff', '__return_zero' );

		parent::tearDown();
	}

	// Scopes.

	/**
	 * The ACL scope is plural. `calendar.acl` (singular) does not exist in
	 * Google's API and would silently fail app verification.
	 */
	public function test_acls_scope_is_plural() {
		$this->assertSame(
			'https://www.googleapis.com/auth/calendar.acls',
			WP_MCP_AI_Google_Calendar_Scopes::SCOPE_ACLS
		);
	}

	/**
	 * The Minimal profile must use only non-sensitive scopes, because its whole
	 * purpose is to avoid Google's OAuth app verification.
	 */
	public function test_minimal_profile_requires_no_verification() {
		$this->assertFalse(
			WP_MCP_AI_Google_Calendar_Scopes::profile_requires_verification(
				WP_MCP_AI_Google_Calendar_Scopes::PROFILE_MINIMAL
			)
		);

		$scopes = WP_MCP_AI_Google_Calendar_Scopes::get_profile_scopes(
			WP_MCP_AI_Google_Calendar_Scopes::PROFILE_MINIMAL
		);

		$this->assertContains( WP_MCP_AI_Google_Calendar_Scopes::SCOPE_APP_CREATED, $scopes );
		$this->assertNotContains( WP_MCP_AI_Google_Calendar_Scopes::SCOPE_EVENTS, $scopes );
		$this->assertNotContains( WP_MCP_AI_Google_Calendar_Scopes::SCOPE_CALENDAR, $scopes );
	}

	/**
	 * Standard and Full both use sensitive scopes and therefore need review.
	 */
	public function test_standard_and_full_require_verification() {
		$this->assertTrue(
			WP_MCP_AI_Google_Calendar_Scopes::profile_requires_verification(
				WP_MCP_AI_Google_Calendar_Scopes::PROFILE_STANDARD
			)
		);
		$this->assertTrue(
			WP_MCP_AI_Google_Calendar_Scopes::profile_requires_verification(
				WP_MCP_AI_Google_Calendar_Scopes::PROFILE_FULL
			)
		);
	}

	/**
	 * An unknown profile slug must fall back to the default, not to nothing.
	 */
	public function test_unknown_profile_normalises_to_default() {
		$this->assertSame(
			WP_MCP_AI_Google_Calendar_Scopes::DEFAULT_PROFILE,
			WP_MCP_AI_Google_Calendar_Scopes::normalise_profile( 'not-a-real-profile' )
		);
		$this->assertSame(
			WP_MCP_AI_Google_Calendar_Scopes::DEFAULT_PROFILE,
			WP_MCP_AI_Google_Calendar_Scopes::normalise_profile( '' )
		);
	}

	/**
	 * Broader scopes must satisfy narrower requirements.
	 */
	public function test_broader_scopes_imply_narrower_ones() {
		$full = WP_MCP_AI_Google_Calendar_Scopes::SCOPE_CALENDAR;

		$this->assertTrue(
			WP_MCP_AI_Google_Calendar_Scopes::has_scope( $full, WP_MCP_AI_Google_Calendar_Scopes::SCOPE_EVENTS )
		);
		$this->assertTrue(
			WP_MCP_AI_Google_Calendar_Scopes::has_scope( $full, WP_MCP_AI_Google_Calendar_Scopes::SCOPE_EVENTS_READONLY )
		);
		$this->assertTrue(
			WP_MCP_AI_Google_Calendar_Scopes::has_scope( $full, WP_MCP_AI_Google_Calendar_Scopes::SCOPE_FREEBUSY )
		);
	}

	/**
	 * A narrower grant must not satisfy a broader requirement. This is the case
	 * that catches Google's granular consent, where a user approves a subset.
	 */
	public function test_narrower_scope_does_not_imply_broader_one() {
		$readonly = WP_MCP_AI_Google_Calendar_Scopes::SCOPE_EVENTS_READONLY;

		$this->assertFalse(
			WP_MCP_AI_Google_Calendar_Scopes::has_scope( $readonly, WP_MCP_AI_Google_Calendar_Scopes::SCOPE_EVENTS )
		);
		$this->assertTrue(
			WP_MCP_AI_Google_Calendar_Scopes::has_scope( $readonly, $readonly )
		);
	}

	/**
	 * Connections created before scope tracking have no recorded grant and must
	 * keep working rather than failing closed.
	 */
	public function test_empty_grant_is_permissive_for_legacy_connections() {
		$this->assertTrue(
			WP_MCP_AI_Google_Calendar_Scopes::has_scope( '', WP_MCP_AI_Google_Calendar_Scopes::SCOPE_EVENTS )
		);
	}

	/**
	 * Granted scopes arrive space-delimited from Google's token response.
	 */
	public function test_granted_scopes_parse_from_space_delimited_string() {
		$parsed = WP_MCP_AI_Google_Calendar_Scopes::parse_granted(
			"  https://www.googleapis.com/auth/calendar.events \n https://www.googleapis.com/auth/calendar.calendarlist.readonly  "
		);

		$this->assertCount( 2, $parsed );
		$this->assertContains( WP_MCP_AI_Google_Calendar_Scopes::SCOPE_EVENTS, $parsed );
	}

	/**
	 * Legacy saves hold %20-encoded separators: a past settings sanitizer ran
	 * esc_url_raw() over the space-delimited grant. The parser must heal them
	 * so the granted list renders per scope and has_scope() matches again.
	 */
	public function test_granted_scopes_parse_from_percent_encoded_string() {
		$parsed = WP_MCP_AI_Google_Calendar_Scopes::parse_granted(
			'https://www.googleapis.com/auth/calendar.events%20https://www.googleapis.com/auth/calendar.calendarlist.readonly'
		);

		$this->assertSame(
			array(
				WP_MCP_AI_Google_Calendar_Scopes::SCOPE_EVENTS,
				WP_MCP_AI_Google_Calendar_Scopes::SCOPE_CALENDARLIST_READONLY,
			),
			$parsed
		);
	}

	/**
	 * Scope checks must treat %20-encoded separators like real spaces so a
	 * corrupted legacy grant does not produce false "scope declined" warnings.
	 */
	public function test_has_scope_matches_percent_encoded_grant() {
		$this->assertTrue(
			WP_MCP_AI_Google_Calendar_Scopes::has_scope(
				'https://www.googleapis.com/auth/calendar.events%20https://www.googleapis.com/auth/calendar.calendarlist.readonly',
				WP_MCP_AI_Google_Calendar_Scopes::SCOPE_EVENTS
			)
		);
	}

	// Sync parameter split.

	/**
	 * A full sync may narrow by date.
	 */
	public function test_full_sync_permits_time_min() {
		$params = WP_MCP_AI_Google_Calendar_Client::build_sync_params(
			'full',
			array( 'timeMin' => '2026-01-01T00:00:00Z' )
		);

		$this->assertArrayHasKey( 'timeMin', $params );
		$this->assertArrayNotHasKey( 'syncToken', $params );
	}

	/**
	 * Every parameter Google rejects alongside `syncToken` must be stripped.
	 * Leaking any of these produces an HTTP 400 that silently halts sync.
	 *
	 * @dataProvider forbidden_sync_param_provider
	 *
	 * @param string $param Parameter name that is illegal with a sync token.
	 */
	public function test_incremental_sync_strips_forbidden_param( $param ) {
		$params = WP_MCP_AI_Google_Calendar_Client::build_sync_params(
			'incremental',
			array( $param => 'value' ),
			'TOKEN123'
		);

		$this->assertArrayNotHasKey( $param, $params );
		$this->assertSame( 'TOKEN123', $params['syncToken'] );
	}

	/**
	 * Data provider covering all eight forbidden parameters.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function forbidden_sync_param_provider() {
		$cases = array();

		foreach ( WP_MCP_AI_Google_Calendar_Client::SYNC_FORBIDDEN_PARAMS as $param ) {
			$cases[ $param ] = array( $param );
		}

		return $cases;
	}

	/**
	 * Deletions must be returned during incremental sync so clients can purge,
	 * so `showDeleted=false` is not permitted.
	 */
	public function test_incremental_sync_forces_show_deleted() {
		$params = WP_MCP_AI_Google_Calendar_Client::build_sync_params(
			'incremental',
			array( 'showDeleted' => 'false' ),
			'TOKEN123'
		);

		$this->assertSame( 'true', $params['showDeleted'] );
	}

	/**
	 * `maxResults` must be clamped to Google's accepted range.
	 */
	public function test_max_results_is_clamped() {
		$this->assertSame( 2500, WP_MCP_AI_Google_Calendar_Client::clamp_max_results( 99999 ) );
		$this->assertSame( 250, WP_MCP_AI_Google_Calendar_Client::clamp_max_results( 0 ) );
		$this->assertSame( 250, WP_MCP_AI_Google_Calendar_Client::clamp_max_results( -5 ) );
		$this->assertSame( 10, WP_MCP_AI_Google_Calendar_Client::clamp_max_results( 10 ) );
	}

	// Error classification.

	/**
	 * `410 fullSyncRequired` must be distinguishable so callers wipe local state.
	 */
	public function test_410_full_sync_required_is_classified() {
		$this->mock_response( 410, array( 'error' => array( 'errors' => array( array( 'reason' => 'fullSyncRequired' ) ) ) ) );

		$client = new WP_MCP_AI_Google_Calendar_Client( 'test-token' );
		$result = $client->list_events( 'primary', array( 'syncToken' => 'stale' ) );

		$this->assertWPError( $result );
		$this->assertTrue( WP_MCP_AI_Google_Calendar_Client::is_full_sync_required( $result ) );
	}

	/**
	 * `410 deleted` on a DELETE is a success, not a failure. Treating it as an
	 * error makes idempotent deletes look broken.
	 */
	public function test_410_deleted_on_delete_is_success() {
		$this->mock_response( 410, array( 'error' => array( 'errors' => array( array( 'reason' => 'deleted' ) ) ) ) );

		$client = new WP_MCP_AI_Google_Calendar_Client( 'test-token' );
		$result = $client->delete_event( 'primary', 'evt_1' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['already_deleted'] );
	}

	/**
	 * A bare 403 is an authorisation failure and must not be retried.
	 */
	public function test_bare_403_is_not_retried() {
		$calls = 0;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$calls ) {
				unset( $args, $url );
				++$calls;

				return array(
					'response' => array( 'code' => 403 ),
					'body'     => wp_json_encode(
						array( 'error' => array( 'errors' => array( array( 'reason' => 'forbiddenForNonOrganizer' ) ) ) )
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$client = new WP_MCP_AI_Google_Calendar_Client( 'test-token' );
		$result = $client->list_events( 'primary' );

		$this->assertWPError( $result );
		$this->assertSame( 1, $calls, 'A non-rate-limit 403 must not be retried.' );
		$this->assertSame( 'wp_mcp_ai_calendar_not_organizer', $result->get_error_code() );
	}

	/**
	 * `403 rateLimitExceeded` is functionally identical to 429 and must be
	 * retried with backoff.
	 */
	public function test_403_rate_limit_is_retried() {
		$calls = 0;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$calls ) {
				unset( $args, $url );
				++$calls;

				return array(
					'response' => array( 'code' => 403 ),
					'body'     => wp_json_encode(
						array( 'error' => array( 'errors' => array( array( 'reason' => 'rateLimitExceeded' ) ) ) )
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$client = new WP_MCP_AI_Google_Calendar_Client( 'test-token' );
		$result = $client->list_events( 'primary' );

		$this->assertWPError( $result );
		$this->assertSame(
			WP_MCP_AI_Google_Calendar_Client::MAX_ATTEMPTS,
			$calls,
			'A rate-limit 403 must be retried up to the attempt cap.'
		);
	}

	/**
	 * A missing token must fail before any HTTP request is attempted.
	 */
	public function test_missing_token_fails_closed() {
		$client = new WP_MCP_AI_Google_Calendar_Client( '' );
		$result = $client->list_calendars();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_calendar_missing_token', $result->get_error_code() );
	}

	// Pagination.

	/**
	 * `nextSyncToken` appears only on the final page, so pagination must walk to
	 * the end before the token is trustworthy.
	 */
	public function test_pagination_collects_all_pages_and_final_sync_token() {
		$pages = array(
			array(
				'items'         => array( array( 'id' => 'a' ) ),
				'nextPageToken' => 'p2',
			),
			array(
				'items'         => array( array( 'id' => 'b' ) ),
				'nextSyncToken' => 'FINAL',
			),
		);

		$index = 0;

		$result = WP_MCP_AI_Google_Calendar_Client::paginate(
			function ( $params ) use ( &$index, $pages ) {
				unset( $params );
				$page = $pages[ $index ];
				++$index;

				return $page;
			},
			array()
		);

		$this->assertCount( 2, $result['items'] );
		$this->assertSame( 'FINAL', $result['next_sync_token'] );
	}

	// Cancelled-event routing.

	/**
	 * A cancelled exception of a live recurring event must be retained, while a
	 * plain cancelled event must be deleted locally. Conflating the two either
	 * resurrects deleted events or makes cancelled occurrences reappear.
	 */
	public function test_cancelled_events_are_routed_by_recurring_parent() {
		$classified = WP_MCP_AI_Google_Calendar_Sync::classify_events(
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

		$this->assertSame( array( 'truly_deleted' ), $classified['deleted'] );
	}

	/**
	 * Events without an ID cannot be keyed locally and must be skipped.
	 */
	public function test_events_without_id_are_skipped() {
		$classified = WP_MCP_AI_Google_Calendar_Sync::classify_events(
			array(
				array( 'status' => 'confirmed' ),
				'not-an-array',
			)
		);

		$this->assertSame( array(), $classified['upserted'] );
		$this->assertSame( array(), $classified['deleted'] );
	}

	// OAuth service.

	/**
	 * Google requires the authorize-time and exchange-time redirect URIs to match
	 * byte for byte, so both must come from the same builder.
	 */
	public function test_redirect_uri_is_stable_across_calls() {
		$first  = WP_MCP_AI_Google_OAuth_Service::build_redirect_uri( 'google_calendar_callback' );
		$second = WP_MCP_AI_Google_OAuth_Service::build_redirect_uri( 'google_calendar_callback' );

		$this->assertSame( $first, $second );
		$this->assertStringContainsString( 'wp_mcp_ai_oauth=google_calendar_callback', $first );
	}

	/**
	 * Offline access with forced consent is required to obtain a refresh token.
	 */
	public function test_authorize_url_requests_offline_access() {
		$url = WP_MCP_AI_Google_OAuth_Service::build_authorize_url(
			array(
				'client_id'    => 'cid',
				'redirect_uri' => 'https://example.com/cb',
				'scope'        => WP_MCP_AI_Google_Calendar_Scopes::SCOPE_EVENTS,
				'state'        => 'st',
			)
		);

		$this->assertIsString( $url );
		$this->assertStringContainsString( 'access_type=offline', $url );
		$this->assertStringContainsString( 'prompt=consent', $url );
		$this->assertStringContainsString( 'include_granted_scopes=true', $url );
		$this->assertStringContainsString( 'response_type=code', $url );
	}

	/**
	 * An incomplete authorize request must fail loudly rather than producing a
	 * URL Google will reject.
	 */
	public function test_authorize_url_requires_all_parameters() {
		$result = WP_MCP_AI_Google_OAuth_Service::build_authorize_url( array( 'client_id' => 'cid' ) );

		$this->assertWPError( $result );
	}

	/**
	 * State is single-use: a replayed callback must fail even inside the TTL.
	 */
	public function test_oauth_state_is_single_use() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$state = WP_MCP_AI_Google_OAuth_Service::store_state( 'google_calendar', array( 'connection_id' => 'conn_1' ) );

		$first = WP_MCP_AI_Google_OAuth_Service::consume_state( 'google_calendar', $state );
		$this->assertIsArray( $first );
		$this->assertSame( 'conn_1', $first['connection_id'] );

		$replay = WP_MCP_AI_Google_OAuth_Service::consume_state( 'google_calendar', $state );
		$this->assertWPError( $replay );
	}

	/**
	 * State is bound to the user who started the flow.
	 */
	public function test_oauth_state_is_user_bound() {
		$starter = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other   = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $starter );
		$state = WP_MCP_AI_Google_OAuth_Service::store_state( 'google_calendar' );

		wp_set_current_user( $other );
		$result = WP_MCP_AI_Google_OAuth_Service::consume_state( 'google_calendar', $state );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_oauth_state_user_mismatch', $result->get_error_code() );
	}

	/**
	 * A revoked refresh token surfaces as `invalid_grant`, which callers use to
	 * prompt for reconnection rather than retrying forever.
	 */
	public function test_invalid_grant_is_distinguished() {
		$this->mock_response( 400, array( 'error' => 'invalid_grant' ) );

		$result = WP_MCP_AI_Google_OAuth_Service::mint_access_token(
			array(
				'client_id'     => 'cid',
				'client_secret' => 'secret',
				'refresh_token' => 'revoked',
				'cache_key'     => 'test-' . wp_generate_uuid4(),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_oauth_invalid_grant', $result->get_error_code() );
	}

	// Sync scheduling.

	/**
	 * The interval must be jittered so a fleet of sites never syncs in lockstep,
	 * which Google names as a quota-exhausting anti-pattern.
	 */
	public function test_sync_interval_is_jittered_within_bounds() {
		$base   = 21600;
		$spread = (int) round( $base * 0.25 );
		$seen   = array();

		for ( $i = 0; $i < 40; $i++ ) {
			$value = WP_MCP_AI_Google_Calendar_Sync::jittered_interval( $base );

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
		$this->assertGreaterThanOrEqual( 300, WP_MCP_AI_Google_Calendar_Sync::jittered_interval( 1 ) );
	}

	/**
	 * State round-trips per connection and calendar without collision.
	 */
	public function test_sync_state_is_isolated_per_target() {
		WP_MCP_AI_Google_Calendar_Sync::save_state( 'conn_a', 'primary', array( 'sync_token' => 'TOKEN_A' ) );
		WP_MCP_AI_Google_Calendar_Sync::save_state( 'conn_b', 'primary', array( 'sync_token' => 'TOKEN_B' ) );
		WP_MCP_AI_Google_Calendar_Sync::save_state( 'conn_a', 'other@group.calendar.google.com', array( 'sync_token' => 'TOKEN_C' ) );

		$this->assertSame( 'TOKEN_A', WP_MCP_AI_Google_Calendar_Sync::get_state( 'conn_a', 'primary' )['sync_token'] );
		$this->assertSame( 'TOKEN_B', WP_MCP_AI_Google_Calendar_Sync::get_state( 'conn_b', 'primary' )['sync_token'] );
		$this->assertSame( 'TOKEN_C', WP_MCP_AI_Google_Calendar_Sync::get_state( 'conn_a', 'other@group.calendar.google.com' )['sync_token'] );

		WP_MCP_AI_Google_Calendar_Sync::delete_state( 'conn_a', 'primary' );
		$this->assertSame( '', WP_MCP_AI_Google_Calendar_Sync::get_state( 'conn_a', 'primary' )['sync_token'] );
		$this->assertSame( 'TOKEN_B', WP_MCP_AI_Google_Calendar_Sync::get_state( 'conn_b', 'primary' )['sync_token'] );
	}

	// Helpers.

	/**
	 * Short-circuit all HTTP requests with a fixed status and body.
	 *
	 * @param int                 $status HTTP status code.
	 * @param array<string,mixed> $body   Response body, JSON-encoded.
	 * @return void
	 */
	protected function mock_response( $status, array $body ) {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $status, $body ) {
				unset( $preempt, $args, $url );

				return array(
					'response' => array( 'code' => $status ),
					'body'     => wp_json_encode( $body ),
					'headers'  => array(),
				);
			},
			10,
			3
		);
	}
}
