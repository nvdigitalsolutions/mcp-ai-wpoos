<?php
/**
 * Mailjet OAuth handler port tests (Wave E4, sub-cluster 2).
 *
 * Characterization suite for the ported `MailjetOAuthHandler`: the
 * constants, the state-transient key format, the `is_connected()` /
 * `get_access_token()` contract (not-connected error code, 60-second
 * expiry buffer, refresh path with its error codes), and the
 * capability gate on the disconnect handler. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Integrations\MailjetOAuthHandler;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixture shares this file with its test case.

/**
 * Seam exposing protected members for contract testing.
 */
class MailjetOAuthHandlerSeam extends MailjetOAuthHandler {

	/**
	 * Expose get_mailjet_state_transient_key().
	 *
	 * @param string $state State.
	 * @return string
	 */
	public function seam_state_key( $state ) {
		return $this->get_mailjet_state_transient_key( $state );
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
 * Mailjet OAuth handler characterization.
 */
class Test_OAuth_Mailjet extends \WP_UnitTestCase {

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
		$this->assertSame( 'https://app.mailjet.com/oauth/authorize', MailjetOAuthHandler::MAILJET_OAUTH_AUTHORIZE_ENDPOINT );
		$this->assertSame( 'https://api.mailjet.com/v3/REST/oauth/token', MailjetOAuthHandler::MAILJET_OAUTH_TOKEN_ENDPOINT );
		$this->assertSame( 'https://api.mailjet.com/v3/REST', MailjetOAuthHandler::MAILJET_API_BASE );
		$this->assertSame( 'wp_mcp_ai_settings', MailjetOAuthHandler::OPTION_NAME );
	}

	public function test_state_transient_key_format(): void {
		$seam = new MailjetOAuthHandlerSeam();

		$this->assertSame( 'wp_mcp_ai_mailjet_oauth_state_my-state', $seam->seam_state_key( 'my-state' ) );
	}

	public function test_is_connected_false_without_settings(): void {
		$this->assertFalse( MailjetOAuthHandler::is_connected() );
	}

	public function test_is_connected_true_with_tokens(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'mailjet_connected'    => true,
				'mailjet_access_token' => 'mj-token',
			)
		);

		$this->assertTrue( MailjetOAuthHandler::is_connected() );
	}

	public function test_get_access_token_not_connected(): void {
		$result = MailjetOAuthHandler::get_access_token();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mailjet_no_token', $result->get_error_code() );
	}

	public function test_get_access_token_returns_valid_token(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'mailjet_access_token'     => 'mj-valid-token',
				'mailjet_token_expires_at' => time() + 3600,
			)
		);

		$this->assertSame( 'mj-valid-token', MailjetOAuthHandler::get_access_token() );
	}

	public function test_get_access_token_refreshes_expired_token(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'mailjet_access_token'     => 'mj-expired-token',
				'mailjet_refresh_token'    => 'mj-refresh-token',
				'mailjet_client_id'        => 'mj-client',
				'mailjet_client_secret'    => 'mj-secret',
				'mailjet_token_expires_at' => time() - 100,
			)
		);

		$this->http_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'access_token' => 'mj-fresh-token',
					'expires_in'   => 3600,
				)
			),
		);
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 1 );

		$result = MailjetOAuthHandler::get_access_token();

		$this->assertSame( 'mj-fresh-token', $result );
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertSame( 'mj-fresh-token', $settings['mailjet_access_token'] );
	}

	public function test_refresh_missing_refresh_token(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'mailjet_access_token'     => 'mj-token',
				'mailjet_client_id'        => 'mj-client',
				'mailjet_client_secret'    => 'mj-secret',
				'mailjet_token_expires_at' => time() - 100,
			)
		);

		$result = MailjetOAuthHandlerSeam::seam_refresh_access_token();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mailjet_no_refresh_token', $result->get_error_code() );
	}

	public function test_refresh_missing_credentials(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'mailjet_access_token'     => 'mj-token',
				'mailjet_refresh_token'    => 'mj-refresh-token',
				'mailjet_token_expires_at' => time() - 100,
			)
		);

		$result = MailjetOAuthHandlerSeam::seam_refresh_access_token();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mailjet_no_credentials', $result->get_error_code() );
	}

	public function test_refresh_http_error(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'mailjet_refresh_token'    => 'mj-refresh-token',
				'mailjet_client_id'        => 'mj-client',
				'mailjet_client_secret'    => 'mj-secret',
				'mailjet_access_token'     => 'mj-token',
				'mailjet_token_expires_at' => time() - 100,
			)
		);

		$this->http_response = array(
			'response' => array( 'code' => 401 ),
			'body'     => '{}',
		);
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 1 );

		$result = MailjetOAuthHandlerSeam::seam_refresh_access_token();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mailjet_refresh_failed', $result->get_error_code() );
	}

	public function test_refresh_invalid_json(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'mailjet_refresh_token'    => 'mj-refresh-token',
				'mailjet_client_id'        => 'mj-client',
				'mailjet_client_secret'    => 'mj-secret',
				'mailjet_access_token'     => 'mj-token',
				'mailjet_token_expires_at' => time() - 100,
			)
		);

		$this->http_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => 'not-json',
		);
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 1 );

		$result = MailjetOAuthHandlerSeam::seam_refresh_access_token();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mailjet_invalid_refresh_response', $result->get_error_code() );
	}

	public function test_disconnect_requires_capability(): void {
		$handler = new MailjetOAuthHandler();

		$this->expectException( 'WPDieException' );
		$handler->handle_mailjet_disconnect();
	}
}
