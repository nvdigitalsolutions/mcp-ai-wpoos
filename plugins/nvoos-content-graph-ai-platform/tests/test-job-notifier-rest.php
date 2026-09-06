<?php
/**
 * Job notifier REST + SSE stream port tests (Wave E2).
 *
 * Characterization suite for the ported `JobNotifierRestController` and
 * `SseStream`: byte-identical route surface (`mcp-ai/v1` jobs stream,
 * status, webhook registration), permission-callback envelopes (missing
 * credentials, invalid nonce, forbidden webhook registration, standalone
 * auth-unavailable degradation), the job-status handler (404/403/200),
 * the dot-preserving `sanitize_job_id()`, the buffered SSE framing
 * (connected/status/complete/close), parameter clamping, CORS
 * resolution, and the per-install-mode seams.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Queues\JobNotifier;
use NvoosContentGraphAiPlatform\Rest\JobNotifierRestController;
use NvoosContentGraphAiPlatform\Rest\SseStream;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixture shares this file with its test case.

/**
 * Test seam exposing the SSE stream's protected surface.
 */
class JobNotifierSseStreamSeam extends SseStream {

	/**
	 * Expose protected methods for contract testing.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Method arguments.
	 * @return mixed Method result.
	 */
	public static function seam( $method, array $args = array() ) {
		return self::$method( ...$args );
	}
}

/**
 * Test seam forcing the absent-authenticator degradation branch.
 */
class JobNotifierRestControllerNoAuthSeam extends JobNotifierRestController {

	/**
	 * Resolve no authenticator class.
	 *
	 * @return string|null
	 */
	protected static function authenticator_class(): ?string {
		return null;
	}
}

/**
 * @group queues
 */
class Test_Job_Notifier_Rest extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		delete_option( 'wp_mcp_ai_settings' );
		delete_option( JobNotifier::WEBHOOK_OPTION_KEY );

		remove_all_actions( 'wp_mcp_ai_sse_stream_started' );
		remove_all_actions( 'wp_mcp_ai_sse_stream_chunk_sent' );
		remove_all_actions( 'wp_mcp_ai_sse_stream_ended' );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );

		delete_option( 'wp_mcp_ai_settings' );
		delete_option( JobNotifier::WEBHOOK_OPTION_KEY );

		// Sweep job-status transients this suite created.
		global $wpdb;
		$like = $wpdb->esc_like( '_transient_' . JobNotifier::CACHE_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-harness sweep on plugin-owned transient keys.

		parent::tearDown();
	}

	/**
	 * Build a GET request for the jobs endpoints with a nonce header.
	 *
	 * @param string $route Route path.
	 * @param string $nonce Optional nonce value.
	 * @return \WP_REST_Request
	 */
	private function job_request( string $route, string $nonce = '' ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', $route );
		if ( '' !== $nonce ) {
			$request->set_header( 'X-WP-Nonce', $nonce );
		}
		return $request;
	}

	// ─── Route surface ──────────────────────────────────────────────

	public function test_init_registers_rest_routes_hook(): void {
		// Plugin::register() never runs during the test bootstrap (the
		// ecosystem plugins load after WordPress boots) — call init()
		// directly, matching the suite's established pattern.
		JobNotifierRestController::init();

		$this->assertSame( 10, has_action( 'rest_api_init', array( JobNotifierRestController::class, 'register_routes' ) ) );
	}

	public function test_register_routes_exposes_three_job_routes(): void {
		// Route registration must happen under rest_api_init (WP 6.9 flags
		// off-action registrations) — wire the controller and re-fire the
		// action, matching the blueprints/federation suite pattern.
		JobNotifierRestController::init();
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/mcp-ai/v1/jobs/(?P<job_id>[a-zA-Z0-9_.\-]+)/stream', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/jobs/(?P<job_id>[a-zA-Z0-9_.\-]+)', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/jobs/(?P<job_id>[a-zA-Z0-9_.*\-]+)/webhooks', $routes );
	}

	public function test_sanitize_job_id_preserves_dots_and_blocks_traversal(): void {
		$this->assertSame( 'veo_69203b5b2388f5.11575461', JobNotifierRestController::sanitize_job_id( 'veo_69203b5b2388f5.11575461' ) );
		$this->assertSame( 'abc', JobNotifierRestController::sanitize_job_id( 'a/b..\c' ) );
		$this->assertSame( 'job*', JobNotifierRestController::sanitize_job_id( 'job*' ) );
		// Angle brackets are stripped character-by-character, not as tags —
		// byte-identical to the base's regex behaviour.
		$this->assertSame( 'job-1script', JobNotifierRestController::sanitize_job_id( 'job-1<script>' ) );
	}

	// ─── Permission callbacks ───────────────────────────────────────

	public function test_stream_permission_requires_credentials(): void {
		$result = JobNotifierRestController::permissions_check_job_stream( $this->job_request( '/mcp-ai/v1/jobs/job-x/stream' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_credentials', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_stream_permission_rejects_invalid_nonce(): void {
		$result = JobNotifierRestController::permissions_check_job_stream( $this->job_request( '/mcp-ai/v1/jobs/job-x/stream', 'bogus-nonce' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'rest_invalid_nonce', $result->get_error_code() );
	}

	public function test_stream_permission_accepts_valid_nonce(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = JobNotifierRestController::permissions_check_job_stream(
			$this->job_request( '/mcp-ai/v1/jobs/job-x/stream', wp_create_nonce( 'wp_rest' ) )
		);

		$this->assertTrue( $result );
	}

	public function test_webhook_register_permission_requires_admin(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/jobs/job-x/webhooks' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$result = JobNotifierRestController::permissions_check_webhook_register( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_webhook_register_permission_allows_admin(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/jobs/job-x/webhooks' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$this->assertTrue( JobNotifierRestController::permissions_check_webhook_register( $request ) );
	}

	public function test_stream_permission_mesh_key_degrades_without_authenticator(): void {
		// The monorepo autoloader resolves the base authenticator in both
		// test matrices — reset the cached instance and force the
		// absent-authenticator branch through the seam so the documented
		// degradation is exercised deterministically.
		$ref = new \ReflectionProperty( JobNotifierRestController::class, 'authenticator' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		$request = $this->job_request( '/mcp-ai/v1/jobs/job-x/stream' );
		$request->set_header( 'X-WP-MCP-AI-Mesh-Key', 'some-mesh-key' );

		$result = JobNotifierRestControllerNoAuthSeam::permissions_check_job_stream( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_auth_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );

		// Re-arm the authenticator cache for later tests.
		$ref->setValue( null, null );
	}

	// ─── Job status handler ─────────────────────────────────────────

	public function test_handle_job_status_returns_404_for_unknown_job(): void {
		$result = JobNotifierRestController::handle_job_status(
			new \WP_REST_Request( 'GET', '/mcp-ai/v1/jobs/nope' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'job_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_handle_job_status_enforces_ownership(): void {
		JobNotifier::handle_job_completed( 'job-x', array( 'ok' => true ), array( 'user_id' => 999 ) );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new \WP_REST_Request( 'GET', '/mcp-ai/v1/jobs/job-x' );
		$request->set_param( 'job_id', 'job-x' );
		$result = JobNotifierRestController::handle_job_status( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'unauthorized', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_handle_job_status_returns_status_for_owner(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		JobNotifier::handle_job_completed( 'job-x', array( 'ok' => true ), array( 'user_id' => $user_id ) );

		$request = new \WP_REST_Request( 'GET', '/mcp-ai/v1/jobs/job-x' );
		$request->set_param( 'job_id', 'job-x' );
		$response = JobNotifierRestController::handle_job_status( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'job-x', $data['job_id'] );
		$this->assertSame( 'completed', $data['status'] );
	}

	public function test_handle_job_status_admin_bypasses_ownership(): void {
		JobNotifier::handle_job_completed( 'job-x', array( 'ok' => true ), array( 'user_id' => 999 ) );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new \WP_REST_Request( 'GET', '/mcp-ai/v1/jobs/job-x' );
		$request->set_param( 'job_id', 'job-x' );
		$response = JobNotifierRestController::handle_job_status( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
	}

	// ─── Webhook registration handler ───────────────────────────────

	public function test_handle_webhook_register_happy_path(): void {
		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/jobs/job-x/webhooks' );
		$request->set_param( 'job_id', 'job-x' );
		$request->set_param( 'webhook_url', 'https://example.com/hook' );
		$request->set_param( 'events', array( 'completed' ) );

		$response = JobNotifierRestController::handle_webhook_register( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'job-x', $data['job_id'] );

		$webhooks = get_option( JobNotifier::WEBHOOK_OPTION_KEY, array() );
		$this->assertSame( array( 'completed' ), $webhooks['job-x'][0]['events'] );
	}

	// ─── SSE stream ─────────────────────────────────────────────────

	public function test_stream_job_status_frames_completed_job(): void {
		$captured = array();
		add_action(
			'wp_mcp_ai_sse_stream_started',
			function ( $job_id, $params ) use ( &$captured ) {
				$captured['started'] = array( $job_id, $params );
			},
			10,
			2
		);
		add_action(
			'wp_mcp_ai_sse_stream_ended',
			function ( $job_id, $outcome, $summary ) use ( &$captured ) {
				$captured['ended'] = array( $job_id, $outcome, $summary );
			},
			10,
			3
		);

		JobNotifier::handle_job_completed( 'job-x', array( 'ok' => true ) );

		$response = SseStream::stream_job_status( 'job-x', 10, 1 );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'text/event-stream; charset=UTF-8', $response->get_headers()['Content-Type'] );
		$this->assertSame( 'no', $response->get_headers()['X-Accel-Buffering'] );

		$body = $response->get_data();
		$this->assertStringContainsString( "event: connected\n", $body );
		$this->assertStringContainsString( "event: status\n", $body );
		$this->assertStringContainsString( "event: complete\n", $body );
		$this->assertStringContainsString( "event: close\n", $body );
		$this->assertStringContainsString( '"job_id":"job-x"', $body );

		$this->assertSame( 'job-x', $captured['started'][0] );
		$this->assertSame( 10, $captured['started'][1]['max_duration'] );
		$this->assertSame( 'job-x', $captured['ended'][0] );
		$this->assertSame( 'complete', $captured['ended'][1] );
		$this->assertArrayHasKey( 'duration_ms', $captured['ended'][2] );
	}

	public function test_stream_job_status_clamps_parameters(): void {
		JobNotifier::handle_job_completed( 'job-x', array( 'ok' => true ) );

		add_filter(
			'wp_mcp_ai_sse_max_duration',
			function () {
				return 700;
			}
		);
		add_filter(
			'wp_mcp_ai_sse_poll_interval',
			function () {
				return 0;
			}
		);

		$response = SseStream::stream_job_status( 'job-x' );

		$body = $response->get_data();
		$this->assertStringContainsString( '"max_duration":600', $body );
		$this->assertStringContainsString( '"poll_interval":1', $body );

		remove_all_filters( 'wp_mcp_ai_sse_max_duration' );
		remove_all_filters( 'wp_mcp_ai_sse_poll_interval' );
	}

	public function test_format_sse_message_and_comment(): void {
		$message = JobNotifierSseStreamSeam::seam( 'format_sse_message', array( 'status', array( 'a' => 1 ), 'evt-1' ) );
		$this->assertSame( "id: evt-1\nevent: status\ndata: {\"a\":1}\n\n", $message );

		$comment = JobNotifierSseStreamSeam::seam( 'format_sse_comment', array( 'heartbeat' ) );
		$this->assertSame( ": heartbeat\n\n", $comment );
	}

	public function test_cors_origin_resolution(): void {
		$this->assertSame( 'site', JobNotifierSseStreamSeam::seam( 'cors_allow_origin_setting' ) );

		update_option( 'wp_mcp_ai_settings', array( 'cors_allow_origin' => 'star' ) );
		$this->assertSame( 'star', JobNotifierSseStreamSeam::seam( 'cors_allow_origin_setting' ) );
	}

	public function test_security_headers_resolve_per_install_mode(): void {
		$headers = JobNotifierSseStreamSeam::seam( 'security_headers' );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertNotEmpty( $headers );
		} else {
			$this->assertSame( array(), $headers );
		}
	}
}
