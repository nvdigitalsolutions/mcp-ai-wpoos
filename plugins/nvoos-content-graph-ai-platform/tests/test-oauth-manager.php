<?php
/**
 * OAuth manager port tests (Wave E4, sub-cluster 2).
 *
 * Characterization suite for the ported `OAuthManager`: the constants,
 * the constructor `admin_init` callback wiring, the no-op callback
 * guard, the capability/nonce gates (WPDieException contract), the
 * manual Google token-exchange branches (success / rejected / invalid
 * JSON / transport error), the manual authorize-URL builder, the
 * `allowed_redirect_hosts` filter, the per-mode settings seam, and the
 * per-mode Google-services availability seam. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Integrations\OAuthManager;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixture shares this file with its test case.

/**
 * Seam exposing protected members for contract testing.
 */
class OAuthManagerSeam extends OAuthManager {

	/**
	 * Expose settings().
	 *
	 * @return array
	 */
	public static function seam_settings(): array {
		return self::settings();
	}

	/**
	 * Expose google_services_available().
	 *
	 * @return bool
	 */
	public function seam_google_services_available(): bool {
		return $this->google_services_available();
	}

	/**
	 * Expose exchange_google_auth_code().
	 *
	 * @param string $code          Authorization code.
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client secret.
	 * @param string $redirect_uri  Redirect URI.
	 * @return array|\WP_Error
	 */
	public function seam_exchange_google_auth_code( $code, $client_id, $client_secret, $redirect_uri ) {
		return $this->exchange_google_auth_code( $code, $client_id, $client_secret, $redirect_uri );
	}

	/**
	 * Expose build_google_oauth_url().
	 *
	 * @param string $client_id    Client ID.
	 * @param string $redirect_uri Redirect URI.
	 * @param string $state        State.
	 * @param string $scope        Scopes.
	 * @return string
	 */
	public function seam_build_google_oauth_url( $client_id, $redirect_uri, $state, $scope ) {
		return $this->build_google_oauth_url( $client_id, $redirect_uri, $state, $scope );
	}
}

/**
 * OAuth manager characterization.
 */
class Test_OAuth_Manager extends \WP_UnitTestCase {

	/**
	 * Manager instance.
	 *
	 * @var OAuthManagerSeam
	 */
	private $manager;

	public function setUp(): void {
		parent::setUp();
		$this->manager = new OAuthManagerSeam();
		delete_option( 'wp_mcp_ai_settings' );
	}

	public function tearDown(): void {
		remove_action( 'admin_init', array( $this->manager, 'handle_oauth_callback' ) );
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ) );
		delete_option( 'wp_mcp_ai_settings' );
		unset( $_GET['wp_mcp_ai_oauth'], $_GET['_wpnonce'], $_REQUEST['_wpnonce'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Canned HTTP response.
	 *
	 * @var array|\WP_Error
	 */
	private $http_response = array();

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
		$this->assertSame( 'google_calendar_callback', OAuthManager::GOOGLE_CALENDAR_OAUTH_CALLBACK_HANDLER );
		$this->assertSame( 'wp_mcp_ai_settings', OAuthManager::OPTION_NAME );
	}

	public function test_constructor_registers_admin_init_callback(): void {
		$this->assertSame( 10, has_action( 'admin_init', array( $this->manager, 'handle_oauth_callback' ) ) );
	}

	public function test_handle_oauth_callback_no_param_returns(): void {
		unset( $_GET['wp_mcp_ai_oauth'] );

		$this->manager->handle_oauth_callback();

		$this->assertTrue( true );
	}

	public function test_gmail_start_requires_capability(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );

		$this->expectException( 'WPDieException' );
		$this->manager->handle_gmail_oauth_start();
	}

	public function test_gmail_start_requires_nonce(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		unset( $_GET['_wpnonce'] );

		$this->expectException( 'WPDieException' );
		$this->manager->handle_gmail_oauth_start();
	}

	public function test_yahoo_start_requires_capability(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );

		$this->expectException( 'WPDieException' );
		$this->manager->handle_yahoo_oauth_start();
	}

	public function test_calendar_start_requires_nonce(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		unset( $_REQUEST['_wpnonce'] );

		$this->expectException( 'WPDieException' );
		$this->manager->handle_google_calendar_oauth_start();
	}

	public function test_exchange_google_auth_code_success(): void {
		$this->http_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'access_token'  => 'access-token-1',
					'refresh_token' => 'refresh-token-1',
				)
			),
		);
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 1 );

		$result = $this->manager->seam_exchange_google_auth_code( 'code-1', 'client-id', 'client-secret', 'https://example.test/cb' );

		$this->assertSame(
			array(
				'refresh_token' => 'refresh-token-1',
				'access_token'  => 'access-token-1',
			),
			$result
		);
	}

	public function test_exchange_google_auth_code_rejected(): void {
		$this->http_response = array(
			'response' => array( 'code' => 400 ),
			'body'     => '{"error":"invalid_grant"}',
		);
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 1 );

		$result = $this->manager->seam_exchange_google_auth_code( 'code-1', 'client-id', 'client-secret', 'https://example.test/cb' );

		$this->assertWPError( $result );
		$this->assertSame( 'token_exchange_rejected', $result->get_error_code() );
	}

	public function test_exchange_google_auth_code_invalid_json(): void {
		$this->http_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => 'not-json',
		);
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 1 );

		$result = $this->manager->seam_exchange_google_auth_code( 'code-1', 'client-id', 'client-secret', 'https://example.test/cb' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_token_response', $result->get_error_code() );
	}

	public function test_exchange_google_auth_code_transport_error(): void {
		$this->http_response = new \WP_Error( 'http_request_failed', 'boom' );
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 1 );

		$result = $this->manager->seam_exchange_google_auth_code( 'code-1', 'client-id', 'client-secret', 'https://example.test/cb' );

		$this->assertWPError( $result );
		$this->assertSame( 'token_exchange_failed', $result->get_error_code() );
	}

	public function test_build_google_oauth_url(): void {
		$url = $this->manager->seam_build_google_oauth_url(
			'client-123',
			'https://example.test/admin.php?wp_mcp_ai_oauth=gmail_callback',
			'state-abc',
			'https://www.googleapis.com/auth/gmail.readonly'
		);

		$this->assertStringStartsWith( 'https://accounts.google.com/o/oauth2/v2/auth', $url );
		$this->assertStringContainsString( 'client_id=client-123', $url );
		$this->assertStringContainsString( 'response_type=code', $url );
		$this->assertStringContainsString( 'access_type=offline', $url );
		$this->assertStringContainsString( 'state=state-abc', $url );
		$this->assertStringContainsString( 'prompt=consent', $url );
	}

	public function test_allow_gmail_oauth_redirect_host(): void {
		$hosts = $this->manager->allow_gmail_oauth_redirect_host( array( 'example.com' ) );

		$this->assertContains( 'accounts.google.com', $hosts );
		$this->assertContains( 'example.com', $hosts );
	}

	public function test_settings_seam_reads_option(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gmail_client_id' => 'seam-client-id',
			)
		);

		$settings = OAuthManagerSeam::seam_settings();

		$this->assertSame( 'seam-client-id', $settings['gmail_client_id'] );
	}

	public function test_google_services_availability_resolves_per_mode(): void {
		// Monolith: the base plugin's google classes exist. Standalone: absent.
		$this->assertSame( defined( 'WP_MCP_AI_PATH' ), $this->manager->seam_google_services_available() );
	}
}
