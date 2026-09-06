<?php
/**
 * Google Calendar client port tests (Wave E4, sub-cluster 3).
 *
 * Characterization suite for the ported `GoogleCalendarClient`: the
 * sync-mode parameter split (full vs incremental), the
 * `SYNC_FORBIDDEN_PARAMS` stripping, the forced `showDeleted`, the
 * `maxResults` clamping, the three-way 410 discrimination, the
 * 403/429/5xx retry classification with the attempt cap, the
 * fail-closed missing-token path, token-driven pagination, the boolean
 * stringification, and the full-sync-required / auth-failure probes.
 * Retry tests zero the backoff filter so the suite never sleeps. Runs
 * in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Google\GoogleCalendarClient;

/**
 * Google Calendar API client characterization.
 */
class Test_Google_Client extends \WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		// Never actually sleep during retry tests.
		add_filter( 'wp_mcp_ai_google_calendar_retry_backoff', '__return_zero', 10, 2 );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_google_calendar_retry_backoff', '__return_zero', 10 );

		parent::tearDown();
	}

	/**
	 * A full sync may narrow by date.
	 */
	public function test_full_sync_permits_time_min() {
		$params = GoogleCalendarClient::build_sync_params(
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
		$params = GoogleCalendarClient::build_sync_params(
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

		foreach ( GoogleCalendarClient::SYNC_FORBIDDEN_PARAMS as $param ) {
			$cases[ $param ] = array( $param );
		}

		return $cases;
	}

	/**
	 * Deletions must be returned during incremental sync so clients can purge,
	 * so `showDeleted=false` is not permitted.
	 */
	public function test_incremental_sync_forces_show_deleted() {
		$params = GoogleCalendarClient::build_sync_params(
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
		$this->assertSame( 2500, GoogleCalendarClient::clamp_max_results( 99999 ) );
		$this->assertSame( 250, GoogleCalendarClient::clamp_max_results( 0 ) );
		$this->assertSame( 250, GoogleCalendarClient::clamp_max_results( -5 ) );
		$this->assertSame( 10, GoogleCalendarClient::clamp_max_results( 10 ) );
	}

	// Error classification.

	/**
	 * `410 fullSyncRequired` must be distinguishable so callers wipe local state.
	 */
	public function test_410_full_sync_required_is_classified() {
		$this->mock_response( 410, array( 'error' => array( 'errors' => array( array( 'reason' => 'fullSyncRequired' ) ) ) ) );

		$client = new GoogleCalendarClient( 'test-token' );
		$result = $client->list_events( 'primary', array( 'syncToken' => 'stale' ) );

		$this->assertWPError( $result );
		$this->assertTrue( GoogleCalendarClient::is_full_sync_required( $result ) );
		$this->assertSame( 'wp_mcp_ai_calendar_full_sync_required', $result->get_error_code() );
	}

	/**
	 * `410 deleted` on a DELETE is a success, not a failure. Treating it as an
	 * error makes idempotent deletes look broken.
	 */
	public function test_410_deleted_on_delete_is_success() {
		$this->mock_response( 410, array( 'error' => array( 'errors' => array( array( 'reason' => 'deleted' ) ) ) ) );

		$client = new GoogleCalendarClient( 'test-token' );
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

		$client = new GoogleCalendarClient( 'test-token' );
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

		$client = new GoogleCalendarClient( 'test-token' );
		$result = $client->list_events( 'primary' );

		$this->assertWPError( $result );
		$this->assertSame(
			GoogleCalendarClient::MAX_ATTEMPTS,
			$calls,
			'A rate-limit 403 must be retried up to the attempt cap.'
		);
		$this->assertSame( 'wp_mcp_ai_calendar_rate_limited', $result->get_error_code() );
	}

	/**
	 * A transport failure is transient and must be retried to the attempt cap.
	 */
	public function test_transport_error_is_retried() {
		$calls = 0;

		add_filter(
			'pre_http_request',
			function () use ( &$calls ) {
				++$calls;

				return new \WP_Error( 'http_request_failed', 'Connection refused.' );
			},
			10,
			3
		);

		$client = new GoogleCalendarClient( 'test-token' );
		$result = $client->list_calendars();

		$this->assertWPError( $result );
		$this->assertSame( GoogleCalendarClient::MAX_ATTEMPTS, $calls );
		$this->assertSame( 'wp_mcp_ai_calendar_transport_error', $result->get_error_code() );
	}

	/**
	 * A missing token must fail before any HTTP request is attempted.
	 */
	public function test_missing_token_fails_closed() {
		$client = new GoogleCalendarClient( '' );
		$result = $client->list_calendars();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_calendar_missing_token', $result->get_error_code() );
	}

	/**
	 * A callable token provider returning WP_Error propagates it untouched.
	 */
	public function test_erroring_token_provider_propagates() {
		$client = new GoogleCalendarClient(
			static function () {
				return new \WP_Error( 'wp_mcp_ai_oauth_invalid_grant', 'Revoked.' );
			}
		);

		$result = $client->list_calendars();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_oauth_invalid_grant', $result->get_error_code() );
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

		$result = GoogleCalendarClient::paginate(
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

	/**
	 * Pagination must not run past the safety cap even when Google keeps
	 * returning tokens.
	 */
	public function test_pagination_respects_the_page_cap() {
		$calls = 0;

		$result = GoogleCalendarClient::paginate(
			function () use ( &$calls ) {
				++$calls;

				return array(
					'items'         => array( array( 'id' => 'x' ) ),
					'nextPageToken' => 'page-' . $calls,
				);
			},
			array(),
			3
		);

		$this->assertSame( 3, $calls );
		$this->assertCount( 3, $result['items'] );
	}

	/**
	 * A WP_Error from the fetch callback short-circuits pagination.
	 */
	public function test_pagination_propagates_fetch_errors() {
		$result = GoogleCalendarClient::paginate(
			static function () {
				return new \WP_Error( 'boom', 'Broken.' );
			}
		);

		$this->assertWPError( $result );
		$this->assertSame( 'boom', $result->get_error_code() );
	}

	// Query stringification.

	/**
	 * Booleans must travel as the literal `true`/`false` strings, never `1`/`0`.
	 */
	public function test_boolean_params_are_stringified_literally() {
		$this->mock_response( 200, array() );

		$seen_url = '';

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$seen_url ) {
				unset( $args );
				$seen_url = $url;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
					'headers'  => array(),
				);
			},
			11,
			3
		);

		$client = new GoogleCalendarClient( 'test-token' );
		$client->list_events(
			'primary',
			array(
				'singleEvents' => true,
				'showDeleted'  => false,
			)
		);

		$this->assertStringContainsString( 'singleEvents=true', $seen_url );
		$this->assertStringContainsString( 'showDeleted=false', $seen_url );
	}

	// Probes.

	/**
	 * The full-sync and auth-failure probes classify their error codes.
	 */
	public function test_error_probes_classify_codes() {
		$this->assertTrue(
			GoogleCalendarClient::is_full_sync_required(
				new \WP_Error( 'wp_mcp_ai_calendar_full_sync_required' )
			)
		);
		$this->assertFalse( GoogleCalendarClient::is_full_sync_required( 'nope' ) );

		$this->assertTrue(
			GoogleCalendarClient::is_auth_failure(
				new \WP_Error( 'wp_mcp_ai_calendar_unauthorized' )
			)
		);
		$this->assertTrue(
			GoogleCalendarClient::is_auth_failure(
				new \WP_Error( 'wp_mcp_ai_oauth_invalid_grant' )
			)
		);
		$this->assertTrue(
			GoogleCalendarClient::is_auth_failure(
				new \WP_Error( 'wp_mcp_ai_calendar_missing_token' )
			)
		);
		$this->assertFalse(
			GoogleCalendarClient::is_auth_failure(
				new \WP_Error( 'wp_mcp_ai_calendar_not_found' )
			)
		);
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
