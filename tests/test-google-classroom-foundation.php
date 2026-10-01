<?php
/**
 * Tests for the shared Google Classroom foundation.
 *
 * Covers the invariants that are cheap to break and expensive to debug:
 * profile scope separation under granular consent, the restricted-scope
 * posture, repeated-parameter building, quota-retry classification, the
 * `@MissingGrant` terminal path, Pub/Sub payload decoding, and webhook
 * token verification.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-scopes.php';
require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-oauth-service.php';
require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-client.php';
require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-credentials.php';
require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-push.php';

/**
 * Google Classroom foundation test case.
 */
class Test_Google_Classroom_Foundation extends WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		// Never actually sleep during retry tests.
		add_filter( 'wp_mcp_ai_google_classroom_retry_backoff', '__return_zero' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_google_classroom_retry_backoff', '__return_zero' );

		parent::tearDown();
	}

	// Scopes.

	/**
	 * Every Classroom profile uses restricted scopes and therefore needs OAuth
	 * app verification for public distribution.
	 */
	public function test_all_profiles_require_verification() {
		foreach ( array( 'readonly', 'write', 'push' ) as $profile ) {
			$this->assertTrue(
				WP_MCP_AI_Google_Classroom_Scopes::profile_requires_verification( $profile ),
				$profile . ' profile must flag verification requirement'
			);
		}
	}

	/**
	 * The read-only profile must not leak any write scope.
	 */
	public function test_readonly_profile_has_no_write_scopes() {
		$scopes = WP_MCP_AI_Google_Classroom_Scopes::get_profile_scopes(
			WP_MCP_AI_Google_Classroom_Scopes::PROFILE_READONLY
		);

		$this->assertContains( WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSES_READONLY, $scopes );
		$this->assertContains( WP_MCP_AI_Google_Classroom_Scopes::SCOPE_ROSTERS_READONLY, $scopes );
		$this->assertContains( WP_MCP_AI_Google_Classroom_Scopes::SCOPE_PROFILE_EMAILS, $scopes );

		$this->assertNotContains( WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSES, $scopes );
		$this->assertNotContains( WP_MCP_AI_Google_Classroom_Scopes::SCOPE_ANNOUNCEMENTS, $scopes );
		$this->assertNotContains( WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSEWORK_STUDENTS, $scopes );
		$this->assertNotContains( WP_MCP_AI_Google_Classroom_Scopes::SCOPE_PUSH_NOTIFICATIONS, $scopes );
	}

	/**
	 * The push profile must include the push scope alongside read access.
	 */
	public function test_push_profile_includes_push_scope() {
		$scopes = WP_MCP_AI_Google_Classroom_Scopes::get_profile_scopes(
			WP_MCP_AI_Google_Classroom_Scopes::PROFILE_PUSH
		);

		$this->assertContains( WP_MCP_AI_Google_Classroom_Scopes::SCOPE_PUSH_NOTIFICATIONS, $scopes );
		$this->assertContains( WP_MCP_AI_Google_Classroom_Scopes::SCOPE_ROSTERS_READONLY, $scopes );
	}

	/**
	 * The write profile must carry the announcement and coursework scopes.
	 */
	public function test_write_profile_includes_write_scopes() {
		$scopes = WP_MCP_AI_Google_Classroom_Scopes::get_profile_scopes(
			WP_MCP_AI_Google_Classroom_Scopes::PROFILE_WRITE
		);

		$this->assertContains( WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSES, $scopes );
		$this->assertContains( WP_MCP_AI_Google_Classroom_Scopes::SCOPE_ANNOUNCEMENTS, $scopes );
		$this->assertContains( WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSEWORK_STUDENTS, $scopes );
		$this->assertContains( WP_MCP_AI_Google_Classroom_Scopes::SCOPE_SUBMISSIONS_STUDENTS_READONLY, $scopes );
		$this->assertContains( WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSEWORK_MATERIALS, $scopes );
		$this->assertContains( WP_MCP_AI_Google_Classroom_Scopes::SCOPE_TOPICS, $scopes );
	}

	/**
	 * An unknown profile slug must fall back to the default.
	 */
	public function test_unknown_profile_normalises_to_default() {
		$this->assertSame(
			WP_MCP_AI_Google_Classroom_Scopes::DEFAULT_PROFILE,
			WP_MCP_AI_Google_Classroom_Scopes::normalise_profile( 'not-a-real-profile' )
		);
		$this->assertSame(
			WP_MCP_AI_Google_Classroom_Scopes::DEFAULT_PROFILE,
			WP_MCP_AI_Google_Classroom_Scopes::normalise_profile( '' )
		);
	}

	/**
	 * Broader scopes must satisfy narrower requirements (granular consent).
	 */
	public function test_broader_scopes_imply_narrower_ones() {
		$courses_write = WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSES;

		$this->assertTrue(
			WP_MCP_AI_Google_Classroom_Scopes::has_scope( $courses_write, WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSES_READONLY )
		);

		$coursework = WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSEWORK_STUDENTS;

		$this->assertTrue(
			WP_MCP_AI_Google_Classroom_Scopes::has_scope( $coursework, WP_MCP_AI_Google_Classroom_Scopes::SCOPE_SUBMISSIONS_STUDENTS_READONLY )
		);
	}

	/**
	 * A narrower grant must not satisfy a broader requirement.
	 */
	public function test_narrower_scope_does_not_imply_broader_one() {
		$readonly = WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSES_READONLY;

		$this->assertFalse(
			WP_MCP_AI_Google_Classroom_Scopes::has_scope( $readonly, WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSES )
		);
		$this->assertTrue( WP_MCP_AI_Google_Classroom_Scopes::has_scope( $readonly, $readonly ) );
	}

	/**
	 * An empty recorded grant must pass (legacy connections predate scope
	 * tracking).
	 */
	public function test_empty_grant_passes_legacy_connections() {
		$this->assertTrue(
			WP_MCP_AI_Google_Classroom_Scopes::has_scope( '', WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSES )
		);
	}

	/**
	 * URL-encoded separators in a legacy grant string must normalise before
	 * splitting.
	 */
	public function test_parse_granted_normalises_url_encoded_spaces() {
		$granted = 'https://www.googleapis.com/auth/classroom.courses.readonly%20https://www.googleapis.com/auth/classroom.rosters.readonly';
		$parsed  = WP_MCP_AI_Google_Classroom_Scopes::parse_granted( $granted );

		$this->assertCount( 2, $parsed );
		$this->assertSame( WP_MCP_AI_Google_Classroom_Scopes::SCOPE_ROSTERS_READONLY, $parsed[1] );
	}

	/**
	 * A missing scope must produce an actionable, 403-coded error.
	 */
	public function test_missing_scope_error_is_actionable() {
		$error = WP_MCP_AI_Google_Classroom_Scopes::missing_scope_error(
			WP_MCP_AI_Google_Classroom_Scopes::SCOPE_ANNOUNCEMENTS
		);

		$this->assertInstanceOf( 'WP_Error', $error );
		$this->assertSame( 403, $error->get_error_data()['status'] );
		$this->assertSame(
			WP_MCP_AI_Google_Classroom_Scopes::SCOPE_ANNOUNCEMENTS,
			$error->get_error_data()['required_scope']
		);
	}

	// Client — pagination.

	/**
	 * Pagination must accumulate items across pages and stop when the token is
	 * exhausted.
	 */
	public function test_paginate_accumulates_pages() {
		$pages = array(
			array(
				'items'         => array( array( 'id' => '1' ), array( 'id' => '2' ) ),
				'nextPageToken' => 'tok-2',
			),
			array( 'items' => array( array( 'id' => '3' ) ) ),
		);
		$queue = $pages;

		$result = WP_MCP_AI_Google_Classroom_Client::paginate(
			static function () use ( &$queue ) {
				return array_shift( $queue );
			},
			array()
		);

		$this->assertCount( 3, $result['items'] );
		$this->assertSame( '3', $result['items'][2]['id'] );
	}

	/**
	 * Pagination must propagate a WP_Error from the fetch callable.
	 */
	public function test_paginate_propagates_errors() {
		$result = WP_MCP_AI_Google_Classroom_Client::paginate(
			static function () {
				return new WP_Error( 'wp_mcp_ai_classroom_not_found', 'nope' );
			},
			array()
		);

		$this->assertInstanceOf( 'WP_Error', $result );
	}

	// Client — transport classification.

	/**
	 * Build a client whose token provider returns a fixed token.
	 *
	 * @return WP_MCP_AI_Google_Classroom_Client
	 */
	private function make_client() {
		return new WP_MCP_AI_Google_Classroom_Client( 'test-token' );
	}

	/**
	 * Repeated parameters must be built as repeated `key=value` pairs, never
	 * comma-joined.
	 */
	public function test_repeated_params_are_built_individually() {
		$requested_url = '';

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$requested_url ) {
				$requested_url = $url;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'courses' => array() ) ),
				);
			},
			10,
			3
		);

		$this->make_client()->list_courses(
			array(
				'courseStates' => array( 'ACTIVE', 'ARCHIVED' ),
				'pageSize'     => 50,
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertStringContainsString( 'courseStates=ACTIVE', $requested_url );
		$this->assertStringContainsString( 'courseStates=ARCHIVED', $requested_url );
		$this->assertStringNotContainsString( 'courseStates=ACTIVE%2CARCHIVED', $requested_url );
	}

	/**
	 * A 403 `RESOURCE_EXHAUSTED` must be retried, then succeed.
	 */
	public function test_resource_exhausted_is_retried() {
		$calls = 0;

		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				++$calls;

				if ( 1 === $calls ) {
					return array(
						'response' => array( 'code' => 403 ),
						'body'     => wp_json_encode(
							array(
								'error' => array(
									'code'    => 429,
									'message' => 'quota exceeded',
									'errors'  => array( array( 'reason' => 'RESOURCE_EXHAUSTED' ) ),
								),
							)
						),
					);
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'courses' => array() ) ),
				);
			},
			10,
			3
		);

		$result = $this->make_client()->list_courses( array() );

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 2, $calls );
		$this->assertArrayHasKey( 'courses', $result );
	}

	/**
	 * A `@MissingGrant` failure is terminal and actionable — never retried.
	 */
	public function test_missing_grant_is_terminal_and_actionable() {
		$calls = 0;

		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				++$calls;

				return array(
					'response' => array( 'code' => 403 ),
					'body'     => wp_json_encode(
						array(
							'error' => array(
								'code'    => 403,
								'message' => 'Request had insufficient authentication scopes.',
								'errors'  => array( array( 'reason' => '@MissingGrant' ) ),
							),
						)
					),
				);
			},
			10,
			3
		);

		$result = $this->make_client()->list_courses( array() );

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 1, $calls );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_classroom_missing_grant', $result->get_error_code() );
		$this->assertTrue( WP_MCP_AI_Google_Classroom_Client::is_auth_failure( $result ) );
	}

	/**
	 * A 404 must map to the stable not-found code.
	 */
	public function test_404_maps_to_not_found() {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 404 ),
					'body'     => wp_json_encode(
						array(
							'error' => array(
								'code'    => 404,
								'message' => 'Course not found',
								'errors'  => array( array( 'reason' => 'notFound' ) ),
							),
						)
					),
				);
			},
			10,
			3
		);

		$result = $this->make_client()->get_course( '12345' );

		remove_all_filters( 'pre_http_request' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_classroom_not_found', $result->get_error_code() );
	}

	/**
	 * A bare 403 without a quota reason is an authorisation failure and must
	 * not be retried.
	 */
	public function test_bare_403_is_not_retried() {
		$calls = 0;

		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				++$calls;

				return array(
					'response' => array( 'code' => 403 ),
					'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Forbidden' ) ) ),
				);
			},
			10,
			3
		);

		$result = $this->make_client()->list_courses( array() );

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 1, $calls );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_classroom_forbidden', $result->get_error_code() );
	}

	/**
	 * Course updates must carry an explicit updateMask.
	 */
	public function test_update_course_requires_update_mask() {
		$requested_url  = '';
		$request_method = '';

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$requested_url, &$request_method ) {
				$requested_url  = $url;
				$request_method = isset( $args['method'] ) ? $args['method'] : '';

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'id' => '12345' ) ),
				);
			},
			10,
			3
		);

		$this->make_client()->update_course( '12345', array( 'name' => 'New' ), array( 'name' ) );

		remove_all_filters( 'pre_http_request' );

		$this->assertStringContainsString( 'updateMask=name', $requested_url );
		$this->assertSame( 'PATCH', $request_method );
	}

	// Credentials.

	/**
	 * Resolving without a connection ID is an actionable error.
	 */
	public function test_resolve_requires_connection_id() {
		$result = WP_MCP_AI_Google_Classroom_Credentials::resolve( '' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_classroom_connection_required', $result->get_error_code() );
	}

	/**
	 * Scope gating returns the recorded grant's implications.
	 */
	public function test_require_scope_respects_grant() {
		$credentials = array(
			'granted_scopes' => WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSES_READONLY,
		);

		$this->assertTrue(
			WP_MCP_AI_Google_Classroom_Credentials::require_scope(
				$credentials,
				WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSES_READONLY
			)
		);

		$error = WP_MCP_AI_Google_Classroom_Credentials::require_scope(
			$credentials,
			WP_MCP_AI_Google_Classroom_Scopes::SCOPE_ANNOUNCEMENTS
		);

		$this->assertInstanceOf( 'WP_Error', $error );
	}

	/**
	 * Default course resolution prefers the caller-supplied value, then the
	 * connection default, then errors.
	 */
	public function test_resolve_default_course_id() {
		$credentials = array( 'default_course_id' => 'conn-course' );

		$this->assertSame( 'explicit', WP_MCP_AI_Google_Classroom_Credentials::resolve_default_course_id( $credentials, 'explicit' ) );
		$this->assertSame( 'conn-course', WP_MCP_AI_Google_Classroom_Credentials::resolve_default_course_id( $credentials, '' ) );

		$error = WP_MCP_AI_Google_Classroom_Credentials::resolve_default_course_id( array(), '' );

		$this->assertInstanceOf( 'WP_Error', $error );
		$this->assertSame( 'wp_mcp_ai_classroom_course_required', $error->get_error_code() );
	}

	// Push.

	/**
	 * A well-formed base64 Pub/Sub envelope must decode into the Classroom
	 * notification payload.
	 */
	public function test_decode_pubsub_message() {
		$payload = wp_json_encode(
			array(
				'collection' => 'courses.students',
				'eventType'  => 'CREATED',
				'resourceId' => array(
					'courseId' => '12345',
					'userId'   => '67890',
				),
			)
		);

		$parsed = WP_MCP_AI_Google_Classroom_Push::decode_pubsub_message(
			array(
				'message' => array(
					'data'       => base64_encode( $payload ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Fixture encoding mirrors the Pub/Sub contract.
					'messageId'  => 'msg-1',
					'attributes' => array( 'registrationId' => 'reg-9' ),
				),
			)
		);

		$this->assertSame( 'msg-1', $parsed['message_id'] );
		$this->assertSame( 'reg-9', $parsed['registration_id'] );
		$this->assertSame( 'courses.students', $parsed['payload']['collection'] );
		$this->assertSame( 'CREATED', $parsed['payload']['eventType'] );
	}

	/**
	 * A malformed push payload must produce a WP_Error, not a fatal.
	 */
	public function test_decode_pubsub_message_rejects_garbage() {
		$parsed = WP_MCP_AI_Google_Classroom_Push::decode_pubsub_message(
			array(
				'message' => array(
					'data'      => 'not-json',
					'messageId' => 'msg-2',
				),
			)
		);

		$this->assertInstanceOf( 'WP_Error', $parsed );
	}

	/**
	 * The push secret must be long-lived, stable, and strong.
	 */
	public function test_push_secret_is_stable_and_strong() {
		$first  = WP_MCP_AI_Google_Classroom_Push::get_push_secret();
		$second = WP_MCP_AI_Google_Classroom_Push::get_push_secret();

		$this->assertSame( $first, $second );
		$this->assertGreaterThanOrEqual( 32, strlen( $first ) );

		delete_option( WP_MCP_AI_Google_Classroom_Push::SECRET_OPTION );
	}

	/**
	 * Webhook verification must accept the correct token and reject a wrong one.
	 */
	public function test_verify_notification_token() {
		$secret = WP_MCP_AI_Google_Classroom_Push::get_push_secret();

		$push = new WP_MCP_AI_Google_Classroom_Push();

		$good = new WP_REST_Request( 'POST', '/mcp-ai/v1/google-classroom/webhook' );
		$good->set_param( 'token', $secret );

		$this->assertTrue( $push->verify_notification( $good ) );

		$bad = new WP_REST_Request( 'POST', '/mcp-ai/v1/google-classroom/webhook' );
		$bad->set_param( 'token', 'wrong-token' );

		$result = $push->verify_notification( $bad );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );

		$missing = new WP_REST_Request( 'POST', '/mcp-ai/v1/google-classroom/webhook' );

		$this->assertInstanceOf( 'WP_Error', $push->verify_notification( $missing ) );

		delete_option( WP_MCP_AI_Google_Classroom_Push::SECRET_OPTION );
	}

	/**
	 * Registration expiry within the renewal threshold must trigger renewal
	 * logic via the renewal-window arithmetic.
	 */
	public function test_renewal_window_math() {
		$now    = time();
		$record = array( 'expiry_time' => $now + 3600 ); // 1h left — inside threshold.

		$inside = $record['expiry_time'] - $now <= WP_MCP_AI_Google_Classroom_Push::RENEW_THRESHOLD;
		$this->assertTrue( $inside );

		$record['expiry_time'] = $now + 3 * DAY_IN_SECONDS;
		$outside               = $record['expiry_time'] - $now <= WP_MCP_AI_Google_Classroom_Push::RENEW_THRESHOLD;
		$this->assertFalse( $outside );
	}

	/**
	 * Collection names like `courses.students` must survive the webhook
	 * handler intact — sanitize_key() strips dots, which silently mangles
	 * every collection into one blob and breaks delta routing.
	 */
	public function test_webhook_preserves_collection_dots() {
		// The dedupe transient persists in the shared test DB between runs —
		// clear it so this message is processed fresh.
		delete_transient( 'wp_mcp_ai_gcr_msgid_' . md5( 'msg-dots' ) );

		$push = new WP_MCP_AI_Google_Classroom_Push();

		$payload = wp_json_encode(
			array(
				'collection' => 'courses.students',
				'eventType'  => 'CREATED',
				'resourceId' => array(
					'courseId' => '12345',
					'userId'   => '67890',
				),
			)
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/google-classroom/webhook' );
		$request->set_body(
			wp_json_encode(
				array(
					'message' => array(
						'data'       => base64_encode( $payload ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Fixture mirrors the Pub/Sub contract.
						'messageId'  => 'msg-dots',
						'attributes' => array(),
					),
				)
			)
		);
		$request->set_header( 'Content-Type', 'application/json' );

		$push->handle_notification( $request );

		$found = false;

		foreach ( _get_cron_array() as $timestamp => $cron_events ) {
			foreach ( $cron_events as $hook => $keyed_events ) {
				if ( WP_MCP_AI_Google_Classroom_Push::NOTIFICATION_HOOK !== $hook ) {
					continue;
				}

				foreach ( $keyed_events as $event_key => $event ) {
					if ( ! empty( $event['args'][0]['collection'] ) && 'courses.students' === $event['args'][0]['collection'] ) {
						$found = true;
					}
				}
			}
		}

		$this->assertTrue( $found, 'The scheduled notification must carry the dotted collection name' );

		delete_option( WP_MCP_AI_Google_Classroom_Push::SECRET_OPTION );
	}
}
