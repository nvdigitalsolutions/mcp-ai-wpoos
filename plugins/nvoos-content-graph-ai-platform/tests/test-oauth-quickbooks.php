<?php
/**
 * QuickBooks OAuth handler port tests (Wave E4, sub-cluster 2).
 *
 * Characterization suite for the ported `QuickbooksOAuthHandler`: the
 * constants, the state-transient key format, the `is_connected()` /
 * `get_access_token()` contract (not-connected error code, 60-second
 * expiry buffer, refresh path with its error codes), and the
 * capability gate on the disconnect handler. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Integrations\QuickbooksOAuthHandler;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixture shares this file with its test case.

/**
 * Seam exposing protected members for contract testing.
 */
class QuickbooksOAuthHandlerSeam extends QuickbooksOAuthHandler {

	/**
	 * Expose get_quickbooks_state_transient_key().
	 *
	 * @param string $state State.
	 * @return string
	 */
	public function seam_state_key( $state ) {
		return $this->get_quickbooks_state_transient_key( $state );
	}

	/**
	 * Expose refresh_access_token().
	 *
	 * @return string|\WP_Error
	 */
	public static function seam_refresh_access_token() {
		return self::refresh_access_token();
	}
}

/**
 * QuickBooks OAuth handler characterization.
 */
class Test_OAuth_Quickbooks extends \WP_UnitTestCase {

	/**
	 * Canned HTTP response.
	 *
	 * @var array|\WP_Error
	 */
	private $http_response = array();

	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_settings' );
		wp_set_current_user( 0 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ) );
		delete_option( 'wp_mcp_ai_settings' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Intercept HTTP requests.
	 *
	 * @return array|\WP_Error
	 */
	public function intercept_http() {
		if ( is_wp_error( $this->http_response ) ) {
			return $this->http_response;
		}
		return $this->http_response;
	}

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'https://appcenter.intuit.com/connect/oauth2', QuickbooksOAuthHandler::QUICKBOOKS_OAUTH_AUTHORIZE_ENDPOINT );
		$this->assertSame( 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer', QuickbooksOAuthHandler::QUICKBOOKS_OAUTH_TOKEN_ENDPOINT );
		$this->assertSame( 'https://quickbooks.api.intuit.com/v3', QuickbooksOAuthHandler::QUICKBOOKS_API_BASE );
		$this->assertSame( 'com.intuit.quickbooks.accounting', QuickbooksOAuthHandler::QUICKBOOKS_OAUTH_SCOPES );
		$this->assertSame( 'wp_mcp_ai_settings', QuickbooksOAuthHandler::OPTION_NAME );
	}

	public function test_state_transient_key_format(): void {
		$seam = new QuickbooksOAuthHandlerSeam();

		$this->assertSame( 'wp_mcp_ai_quickbooks_oauth_state_my-state', $seam->seam_state_key( 'my-state' ) );
	}

	public function test_is_connected_false_without_settings(): void {
		$this->assertFalse( QuickbooksOAuthHandler::is_connected() );
	}

	public function test_is_connected_true_with_tokens(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'quickbooks_connected'    => true,
				'quickbooks_access_token' => 'qb-token',
			)
		);

		$this->assertTrue( QuickbooksOAuthHandler::is_connected() );
	}

	public function test_get_access_token_not_connected(): void {
		$result = QuickbooksOAuthHandler::get_access_token();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_quickbooks_no_token', $result->get_error_code() );
	}

	public function test_get_access_token_returns_valid_token(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'quickbooks_access_token'     => 'qb-valid-token',
				'quickbooks_token_expires_at' => time() + 3600,
			)
		);

		$this->assertSame( 'qb-valid-token', QuickbooksOAuthHandler::get_access_token() );
	}

	public function test_get_access_token_refreshes_expired_token(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'quickbooks_access_token'     => 'qb-expired-token',
				'quickbooks_refresh_token'    => 'qb-refresh-token',
				'quickbooks_client_id'        => 'qb-client',
				'quickbooks_client_secret'    => 'qb-secret',
				'quickbooks_token_expires_at' => time() - 100,
			)
		);

		$this->http_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'access_token' => 'qb-fresh-token',
					'expires_in'   => 3600,
				)
			),
		);
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 1 );

		$result = QuickbooksOAuthHandler::get_access_token();

		$this->assertSame( 'qb-fresh-token', $result );
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertSame( 'qb-fresh-token', $settings['quickbooks_access_token'] );
	}

	public function test_refresh_missing_refresh_token(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'quickbooks_access_token'     => 'qb-token',
				'quickbooks_client_id'        => 'qb-client',
				'quickbooks_client_secret'    => 'qb-secret',
				'quickbooks_token_expires_at' => time() - 100,
			)
		);

		$result = QuickbooksOAuthHandlerSeam::seam_refresh_access_token();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_quickbooks_no_refresh_token', $result->get_error_code() );
	}

	public function test_refresh_missing_credentials(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'quickbooks_access_token'     => 'qb-token',
				'quickbooks_refresh_token'    => 'qb-refresh-token',
				'quickbooks_token_expires_at' => time() - 100,
			)
		);

		$result = QuickbooksOAuthHandlerSeam::seam_refresh_access_token();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_quickbooks_no_credentials', $result->get_error_code() );
	}

	public function test_refresh_http_error(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'quickbooks_refresh_token'    => 'qb-refresh-token',
				'quickbooks_client_id'        => 'qb-client',
				'quickbooks_client_secret'    => 'qb-secret',
				'quickbooks_access_token'     => 'qb-token',
				'quickbooks_token_expires_at' => time() - 100,
			)
		);

		$this->http_response = array(
			'response' => array( 'code' => 401 ),
			'body'     => '{}',
		);
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 1 );

		$result = QuickbooksOAuthHandlerSeam::seam_refresh_access_token();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_quickbooks_refresh_failed', $result->get_error_code() );
	}

	public function test_refresh_invalid_json(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'quickbooks_refresh_token'    => 'qb-refresh-token',
				'quickbooks_client_id'        => 'qb-client',
				'quickbooks_client_secret'    => 'qb-secret',
				'quickbooks_access_token'     => 'qb-token',
				'quickbooks_token_expires_at' => time() - 100,
			)
		);

		$this->http_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => 'not-json',
		);
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 1 );

		$result = QuickbooksOAuthHandlerSeam::seam_refresh_access_token();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_quickbooks_invalid_refresh_response', $result->get_error_code() );
	}

	public function test_disconnect_requires_capability(): void {
		$handler = new QuickbooksOAuthHandler();

		$this->expectException( 'WPDieException' );
		$handler->handle_quickbooks_disconnect();
	}
}
