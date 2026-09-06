<?php
/**
 * Request guard port tests (Wave D4d).
 *
 * Characterization suite for `RequestGuard`. Assertions mirror the base
 * plugin's request guard: plugin-route scoping, body-size and JSON-depth
 * rejection, error-verbosity stripping (safe/normal/verbose), asset
 * version stripping, dispatch wrapping, and the SSE slot lifecycle via
 * the active rate limiter seam.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Security\RequestGuard;

/**
 * @group security
 */
class Test_Request_Guard extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\remove_all_actions( 'wp_mcp_ai_sse_stream_started' );
		\remove_all_actions( 'wp_mcp_ai_sse_stream_ended' );

		\delete_option( 'wp_mcp_ai_settings' );
		\delete_option( 'nvoos_content_graph_settings' );
	}

	public function tearDown(): void {
		\remove_all_filters( 'wp_mcp_ai_load_guard_max_concurrent' );
		\delete_option( 'wp_mcp_ai_settings' );
		\delete_option( 'nvoos_content_graph_settings' );

		// Monolith: the base settings repository stores per-key options.
		\delete_option( 'wp_mcp_ai_max_request_body_size_kb' );
		\delete_option( 'wp_mcp_ai_max_json_depth' );
		\delete_option( 'wp_mcp_ai_api_error_verbosity' );

		\wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Write a settings value into the active settings store.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Setting value.
	 * @return void
	 */
	private function set_setting( string $key, $value ): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base repository reads prefixed per-key options
			// and caches them per request — write through its update().
			\wp_mcp_ai_get_settings_repository()->update( $key, $value );
			return;
		}

		$current = \get_option( 'nvoos_content_graph_settings', array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$current[ $key ] = $value;
		\update_option( 'nvoos_content_graph_settings', $current );
	}

	public function test_non_plugin_routes_pass_through(): void {
		$request = new \WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_body( str_repeat( 'a', 5000 ) );

		$result = RequestGuard::validate_request( null, null, $request );

		$this->assertNull( $result );
	}

	public function test_body_size_limit_rejects_oversized_plugin_requests(): void {
		$this->set_setting( 'max_request_body_size_kb', 1 );

		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_body( str_repeat( 'a', 2000 ) );

		$result = RequestGuard::validate_request( null, null, $request );

		$this->assertWPError( $result );
		$this->assertSame( 'request_body_too_large', $result->get_error_code() );
		$this->assertSame( 413, $result->get_error_data()['status'] );
	}

	public function test_body_within_limit_passes(): void {
		$this->set_setting( 'max_request_body_size_kb', 1024 );

		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_body( '{"message":"hi"}' );

		$this->assertNull( RequestGuard::validate_request( null, null, $request ) );
	}

	public function test_json_depth_limit_rejects_deep_payloads(): void {
		$this->set_setting( 'max_json_depth', 2 );

		// Nested 4 levels deep.
		$body    = '{"a":{"b":{"c":{"d":1}}}}';
		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_body( $body );

		$result = RequestGuard::validate_request( null, null, $request );

		$this->assertWPError( $result );
		$this->assertSame( 'json_too_deep', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_shallow_json_passes(): void {
		$this->set_setting( 'max_json_depth', 32 );

		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_body( '{"a":{"b":1}}' );

		$this->assertNull( RequestGuard::validate_request( null, null, $request ) );
	}

	public function test_error_verbosity_safe_strips_internal_keys(): void {
		$this->set_setting( 'api_error_verbosity', 'safe' );

		$request  = new \WP_REST_Request( 'GET', '/mcp-ai/v1/security/events' );
		$error    = new \WP_Error(
			'boom',
			'Detailed internal message',
			array(
				'status'      => 500,
				'retry_after' => 30,
				'internal'    => 'secret-internal-detail',
				'stack'       => 'trace…',
			)
		);

		$filtered = RequestGuard::filter_error_verbosity( $error, null, $request );

		$this->assertWPError( $filtered );
		$this->assertSame( 'boom', $filtered->get_error_code() );
		$data = $filtered->get_error_data();
		$this->assertSame( 500, $data['status'] );
		$this->assertSame( 30, $data['retry_after'] );
		$this->assertArrayNotHasKey( 'internal', $data );
		$this->assertArrayNotHasKey( 'stack', $data );
	}

	public function test_error_verbosity_verbose_passes_through(): void {
		$this->set_setting( 'api_error_verbosity', 'verbose' );

		$request = new \WP_REST_Request( 'GET', '/mcp-ai/v1/security/events' );
		$error   = new \WP_Error( 'boom', 'msg', array( 'internal' => 'secret' ) );

		$filtered = RequestGuard::filter_error_verbosity( $error, null, $request );

		$this->assertSame( $error, $filtered );
	}

	public function test_error_verbosity_normal_spares_admins(): void {
		$this->set_setting( 'api_error_verbosity', 'normal' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin_id );

		$request = new \WP_REST_Request( 'GET', '/mcp-ai/v1/security/events' );
		$error   = new \WP_Error( 'boom', 'msg', array( 'internal' => 'secret' ) );

		$this->assertSame( $error, RequestGuard::filter_error_verbosity( $error, null, $request ) );

		// Non-admin gets stripped under 'normal' mode.
		\wp_set_current_user( 0 );
		$filtered = RequestGuard::filter_error_verbosity( $error, null, $request );
		$this->assertArrayNotHasKey( 'internal', $filtered->get_error_data() );
	}

	public function test_wrap_dispatch_passes_through(): void {
		$non_plugin = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
		$this->assertSame( 'result', RequestGuard::wrap_dispatch( 'result', $non_plugin, '/wp/v2/posts', null, null ) );

		$plugin   = new \WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$wp_error = new \WP_Error( 'code', 'msg' );
		$this->assertSame( $wp_error, RequestGuard::wrap_dispatch( $wp_error, $plugin, '/mcp-ai/v1/assistants', null, null ) );
		$this->assertSame( 'ok', RequestGuard::wrap_dispatch( 'ok', $plugin, '/mcp-ai/v1/assistants', null, null ) );
	}

	public function test_strip_asset_version_only_affects_plugin_assets(): void {
		$plugin = RequestGuard::strip_asset_version( 'https://site.test/wp-content/plugins/nvoos-content-graph-ai/assets/js/app.js?ver=1.2.3', 'app' );
		$this->assertStringNotContainsString( 'ver=1.2.3', $plugin );

		$external = RequestGuard::strip_asset_version( 'https://site.test/wp-includes/js/jquery.js?ver=4.0', 'jquery' );
		$this->assertStringContainsString( 'ver=4.0', $external );
	}

	public function test_sse_slot_lifecycle_via_unified_limiter(): void {
		$job_id = 'job-lifecycle-1';

		RequestGuard::acquire_sse_slot( $job_id, array() );

		$token = \get_transient( 'wp_mcp_ai_sse_slot_token_' . $job_id );
		$this->assertNotFalse( $token );

		RequestGuard::refresh_sse_slot( $job_id, 'message' );
		RequestGuard::release_sse_slot( $job_id, 'completed', array() );

		$this->assertFalse( \get_transient( 'wp_mcp_ai_sse_slot_token_' . $job_id ) );
	}
}
