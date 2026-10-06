<?php
/**
 * Tests for WP_MCP_AI_Request_Guard — REST error verbosity control.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Request Guard test suite.
 *
 * @group security
 * @group request-guard
 */
class WP_MCP_AI_Request_Guard_Tests extends WP_UnitTestCase {

	/**
	 * Reset the verbosity setting and current user between tests.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 );
	}

	/**
	 * Clean up the verbosity setting and current user.
	 */
	public function tearDown(): void {
		wp_mcp_ai_get_settings_repository()->delete( 'api_error_verbosity' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Write the API error verbosity setting.
	 *
	 * @param string $value Verbosity mode (safe, normal, verbose).
	 */
	private function set_verbosity( $value ) {
		wp_mcp_ai_get_settings_repository()->update( 'api_error_verbosity', $value );
	}

	/**
	 * Safe mode strips internal keys but keeps safe keys and adds a ref.
	 */
	public function test_safe_strips_internal_keys() {
		$this->set_verbosity( 'safe' );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/security/events' );
		$error   = new WP_Error(
			'boom',
			'Detailed internal message',
			array(
				'status'      => 500,
				'retry_after' => 30,
				'internal'    => 'secret-internal-detail',
				'stack'       => 'trace…',
			)
		);

		$filtered = WP_MCP_AI_Request_Guard::filter_error_verbosity( $error, null, $request );

		$this->assertWPError( $filtered );
		$this->assertSame( 'boom', $filtered->get_error_code() );
		$data = $filtered->get_error_data();
		$this->assertSame( 500, $data['status'] );
		$this->assertSame( 30, $data['retry_after'] );
		$this->assertArrayNotHasKey( 'internal', $data );
		$this->assertArrayNotHasKey( 'stack', $data );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]{10}$/', $data['ref'] );
	}

	/**
	 * Safe mode carries a real HTTP status through instead of forcing 500.
	 */
	public function test_safe_preserves_original_status() {
		$this->set_verbosity( 'safe' );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/security/events' );
		$error    = new WP_Error(
			'validation_failed',
			'Validation failed',
			array(
				'status' => 400,
				'field'  => 'aspect_ratio',
			)
		);
		$filtered = WP_MCP_AI_Request_Guard::filter_error_verbosity( $error, null, $request );

		$data = $filtered->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertArrayNotHasKey( 'field', $data );
	}

	/**
	 * Safe mode defaults to 500 only when the original error declared none.
	 */
	public function test_safe_defaults_status_to_500_when_absent() {
		$this->set_verbosity( 'safe' );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/security/events' );
		$error    = new WP_Error( 'boom', 'msg', array( 'internal' => 'secret' ) );
		$filtered = WP_MCP_AI_Request_Guard::filter_error_verbosity( $error, null, $request );

		$this->assertSame( 500, $filtered->get_error_data()['status'] );
	}

	/**
	 * A bare numeric error datum is treated as the HTTP status.
	 */
	public function test_safe_treats_numeric_datum_as_status() {
		$this->set_verbosity( 'safe' );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/security/events' );
		$error    = new WP_Error( 'boom', 'msg', 400 );
		$filtered = WP_MCP_AI_Request_Guard::filter_error_verbosity( $error, null, $request );

		$this->assertSame( 400, $filtered->get_error_data()['status'] );
	}

	/**
	 * Safe mode masks admins too unless they explicitly opt in.
	 */
	public function test_safe_masks_admins_without_override() {
		$this->set_verbosity( 'safe' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/security/events' );
		$error    = new WP_Error( 'boom', 'msg', array( 'internal' => 'secret' ) );
		$filtered = WP_MCP_AI_Request_Guard::filter_error_verbosity( $error, null, $request );

		$this->assertNotSame( $error, $filtered );
		$this->assertArrayNotHasKey( 'internal', $filtered->get_error_data() );
	}

	/**
	 * Admins can opt into full detail per request while safe mode stays on.
	 */
	public function test_safe_spares_admins_with_verbose_override() {
		$this->set_verbosity( 'safe' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/security/events' );
		$request->set_query_params( array( 'verbose_errors' => '1' ) );
		$error = new WP_Error( 'boom', 'msg', array( 'internal' => 'secret' ) );

		$this->assertSame( $error, WP_MCP_AI_Request_Guard::filter_error_verbosity( $error, null, $request ) );
	}

	/**
	 * The verbose_errors override must not work for unauthenticated users.
	 */
	public function test_verbose_errors_override_is_ignored_for_guests() {
		$this->set_verbosity( 'safe' );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/security/events' );
		$request->set_query_params( array( 'verbose_errors' => '1' ) );
		$error    = new WP_Error( 'boom', 'msg', array( 'internal' => 'secret' ) );
		$filtered = WP_MCP_AI_Request_Guard::filter_error_verbosity( $error, null, $request );

		$this->assertNotSame( $error, $filtered );
		$this->assertArrayNotHasKey( 'internal', $filtered->get_error_data() );
	}

	/**
	 * Safe mode strips REST error response payloads and attaches a ref.
	 */
	public function test_safe_strips_rest_response_and_attaches_ref() {
		$this->set_verbosity( 'safe' );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/security/events' );
		$response = new WP_REST_Response(
			array(
				'code'    => 'validation_failed',
				'message' => 'Validation failed',
				'data'    => array(
					'status' => 400,
					'field'  => 'aspect_ratio',
					'params' => array( '1:1', '16:9' ),
				),
			),
			400
		);

		$filtered = WP_MCP_AI_Request_Guard::filter_error_verbosity( $response, null, $request );

		$this->assertSame( 400, $filtered->get_status() );
		$data = $filtered->get_data();
		$this->assertSame( 'validation_failed', $data['code'] );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]{10}$/', $data['ref'] );
		$this->assertSame( 400, $data['data']['status'] );
		$this->assertArrayNotHasKey( 'field', $data['data'] );
		$this->assertArrayNotHasKey( 'params', $data['data'] );
	}

	/**
	 * Verbose mode passes errors through untouched.
	 */
	public function test_verbose_passes_through() {
		$this->set_verbosity( 'verbose' );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/security/events' );
		$error   = new WP_Error( 'boom', 'msg', array( 'internal' => 'secret' ) );

		$this->assertSame( $error, WP_MCP_AI_Request_Guard::filter_error_verbosity( $error, null, $request ) );
	}

	/**
	 * Normal mode spares admins and strips non-admin users.
	 */
	public function test_normal_spares_admins() {
		$this->set_verbosity( 'normal' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/security/events' );
		$error   = new WP_Error( 'boom', 'msg', array( 'internal' => 'secret' ) );

		$this->assertSame( $error, WP_MCP_AI_Request_Guard::filter_error_verbosity( $error, null, $request ) );

		// Non-admin gets stripped under 'normal' mode.
		wp_set_current_user( 0 );
		$filtered = WP_MCP_AI_Request_Guard::filter_error_verbosity( $error, null, $request );
		$this->assertArrayNotHasKey( 'internal', $filtered->get_error_data() );
	}

	/**
	 * Non-plugin routes pass through untouched.
	 */
	public function test_non_plugin_routes_pass_through() {
		$this->set_verbosity( 'safe' );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$error   = new WP_Error( 'boom', 'msg', array( 'internal' => 'secret' ) );

		$this->assertSame( $error, WP_MCP_AI_Request_Guard::filter_error_verbosity( $error, null, $request ) );
	}
}
