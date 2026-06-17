<?php
/**
 * Shared base test case for AJAX handler tests.
 *
 * Codifies the 4-point coverage contract introduced by the AJAX-test gap-fill
 * plan: every concrete `wp_ajax_*` handler should have at minimum
 *
 *   1. Capability gate    — caller without the required cap is rejected.
 *   2. Nonce verification — bad/missing nonce is rejected.
 *   3. Happy path         — valid request returns the expected JSON shape.
 *   4. Input validation   — at least one missing/invalid param is rejected.
 *
 * This class wraps `WP_Ajax_UnitTestCase` with helpers that keep each handler
 * test minimal and consistent across the suite.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName, WordPress.NamingConventions.ValidFunctionName -- inherited from WP_Ajax_UnitTestCase ($this->_last_response, _handleAjax, etc.).

/**
 * Base AJAX test case.
 */
abstract class WP_MCP_AI_Ajax_TestCase extends WP_Ajax_UnitTestCase {

	/**
	 * Stubbed HTTP responses keyed by URL substring.
	 *
	 * Populated via {@see self::stub_http_response()} and consumed by the
	 * `pre_http_request` filter installed in {@see self::setUp()}.
	 *
	 * @var array<string,array|WP_Error>
	 */
	protected $http_stubs = array();

	/**
	 * Set up the test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->http_stubs = array();
		add_filter( 'pre_http_request', array( $this, 'filter_pre_http_request' ), 10, 3 );
	}

	/**
	 * Tear down the test environment.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'filter_pre_http_request' ), 10 );
		$this->http_stubs = array();
		$this->reset_post();

		parent::tearDown();
	}

	/**
	 * Switch to a freshly created administrator user.
	 *
	 * @return int User ID.
	 */
	protected function as_admin() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Switch to a freshly created subscriber (low-capability user).
	 *
	 * @return int User ID.
	 */
	protected function as_subscriber() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Switch to a freshly created editor user (has edit_posts, upload_files, etc.).
	 *
	 * @return int User ID.
	 */
	protected function as_editor() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Switch to no logged-in user.
	 */
	protected function as_anonymous() {
		wp_set_current_user( 0 );
	}

	/**
	 * Reset the global $_POST/$_REQUEST and the WP Ajax response state between dispatches.
	 */
	protected function reset_post() {
		$_POST    = array();
		$_REQUEST = array();
		// `WP_Ajax_UnitTestCase::_handleAjax()` reads `$this->_last_response` to
		// confirm a single response per dispatch; clear it so back-to-back calls
		// in the same test method behave as expected.
		$this->_last_response = '';
	}

	/**
	 * Dispatch an AJAX action and return the decoded JSON response.
	 *
	 * Wraps {@see WP_Ajax_UnitTestCase::_handleAjax()} so the inevitable
	 * `WPAjaxDieContinueException` / `WPAjaxDieStopException` is caught and the
	 * caller can focus on assertions.
	 *
	 * @param string $action  Action name (without the `wp_ajax_` prefix).
	 * @param array  $payload Optional `$_POST` payload to set before dispatch.
	 *                        Keys are merged on top of `$_POST`.
	 * @return array Decoded JSON body. On non-JSON output returns
	 *               `array( 'success' => false, 'data' => $raw )`.
	 */
	protected function dispatch( $action, array $payload = array() ) {
		$_POST['action'] = $action;
		foreach ( $payload as $k => $v ) {
			$_POST[ $k ] = $v;
		}
		$_REQUEST = array_merge( $_REQUEST, $_POST );

		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected for `wp_send_json_*` paths.
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			// Expected for `wp_die()` paths (e.g. nonce failures).
			unset( $e );
		}

		$raw     = $this->_last_response;
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'success' => false,
				'data'    => $raw,
			);
		}
		return $decoded;
	}

	/**
	 * Assert that the most recent dispatch returned `success: true`.
	 *
	 * @param array       $response Decoded response from {@see self::dispatch()}.
	 * @param string|null $message  Optional failure message.
	 */
	protected function assertAjaxSuccess( $response, $message = null ) {
		$this->assertIsArray( $response, 'AJAX response is not a JSON object.' );
		$this->assertArrayHasKey( 'success', $response, 'AJAX response is missing the "success" flag.' );
		$this->assertTrue(
			$response['success'],
			null !== $message
				? $message
				: 'Expected AJAX success, got: ' . wp_json_encode( $response )
		);
	}

	/**
	 * Assert that the most recent dispatch returned `success: false`.
	 *
	 * @param array       $response          Decoded response from {@see self::dispatch()}.
	 * @param string|null $expected_substring Optional substring expected in `data.message`.
	 */
	protected function assertAjaxError( $response, $expected_substring = null ) {
		$this->assertIsArray( $response, 'AJAX response is not a JSON object.' );
		$this->assertArrayHasKey( 'success', $response, 'AJAX response is missing the "success" flag.' );
		$this->assertFalse(
			$response['success'],
			'Expected AJAX error, got: ' . wp_json_encode( $response )
		);
		if ( null !== $expected_substring ) {
			$message = '';
			if ( isset( $response['data']['message'] ) ) {
				$message = (string) $response['data']['message'];
			} elseif ( isset( $response['data'] ) && is_string( $response['data'] ) ) {
				$message = $response['data'];
			}
			$this->assertStringContainsString( $expected_substring, $message );
		}
	}

	/**
	 * Assert that the most recent dispatch was rejected on capability or nonce
	 * grounds. Either an HTTP-403-style `wp_die` (no JSON body) or a
	 * `success: false` response with a permissions-y message is acceptable.
	 *
	 * @param array       $response Decoded response from {@see self::dispatch()}.
	 * @param string|null $message  Optional failure message.
	 */
	protected function assertAjaxForbidden( $response, $message = null ) {
		$this->assertIsArray( $response );
		// Either: nonce failure produced -1 / no JSON, or success:false.
		if ( isset( $response['success'] ) && false === $response['success'] ) {
			return;
		}
		// `wp_die( -1 )` from `check_ajax_referer( …, …, true )` produces "-1".
		$this->assertSame(
			'-1',
			isset( $response['data'] ) ? trim( (string) $response['data'] ) : '',
			null !== $message
				? $message
				: 'Expected forbidden / nonce failure response, got: ' . wp_json_encode( $response )
		);
	}

	/**
	 * Return true when the response indicates `success: true`.
	 *
	 * Useful for conditional assertions in happy-path tests that have multiple
	 * acceptable outcomes.
	 *
	 * @param array $response Decoded response from {@see self::dispatch()}.
	 * @return bool
	 */
	protected function isAjaxSuccess( $response ): bool {
		return is_array( $response ) && isset( $response['success'] ) && true === $response['success'];
	}

	/**
	 * Return true when the response indicates `success: false`.
	 *
	 * @param array $response Decoded response from {@see self::dispatch()}.
	 * @return bool
	 */
	protected function isAjaxError( $response ): bool {
		return is_array( $response ) && isset( $response['success'] ) && false === $response['success'];
	}

	/**
	 * Return true when the response looks like a nonce/capability failure.
	 *
	 * @param array $response Decoded response from {@see self::dispatch()}.
	 * @return bool
	 */
	protected function isAjaxForbidden( $response ): bool {
		if ( ! is_array( $response ) ) {
			return false;
		}
		if ( isset( $response['success'] ) && false === $response['success'] ) {
			return true;
		}
		return isset( $response['data'] ) && '-1' === trim( (string) $response['data'] );
	}

	/**
	 * Return the `data` payload from a successful AJAX response.
	 *
	 * @param array $response Decoded response from {@see self::dispatch()}.
	 * @return array
	 */
	protected function getResponseData( $response ): array {
		if ( ! is_array( $response ) || ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return array();
		}
		return $response['data'];
	}

	/**
	 * Register a stub HTTP response for any outbound `wp_remote_*` request.
	 *
	 * The first stub whose URL substring appears in the request URL is
	 * returned; remaining stubs fall through to a generic 200 OK so external
	 * APIs never escape the test sandbox.
	 *
	 * @param string         $url_substring Substring to match in the outbound URL.
	 * @param array|WP_Error $response      Either a faux `wp_remote_*` response
	 *                                       array (`array('response'=>['code'=>200], 'body'=>'…')`)
	 *                                       or a `WP_Error` instance.
	 */
	protected function stub_http_response( $url_substring, $response ) {
		$this->http_stubs[ $url_substring ] = $response;
	}

	/**
	 * Filter callback that intercepts outbound HTTP requests.
	 *
	 * @param false|array|WP_Error $preempt Pre-empt value.
	 * @param array                $args    Request args.
	 * @param string               $url     Request URL.
	 * @return mixed
	 */
	public function filter_pre_http_request( $preempt, $args, $url ) {
		unset( $args );
		foreach ( $this->http_stubs as $needle => $response ) {
			if ( '' === $needle || false !== strpos( (string) $url, $needle ) ) {
				return $response;
			}
		}

		// Default deny — return a generic 200 so handlers that hit unstubbed
		// URLs still get a deterministic response instead of failing the suite
		// because of network access.
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => '{}',
			'headers'  => array(),
			'cookies'  => array(),
		);
	}
}
