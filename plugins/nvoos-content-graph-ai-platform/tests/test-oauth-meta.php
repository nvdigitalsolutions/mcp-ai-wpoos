<?php
/**
 * Meta OAuth handler port tests (Wave E4, sub-cluster 2).
 *
 * Characterization suite for the ported `MetaOAuthHandler`: the
 * constants, the `allowed_redirect_hosts` filter, the callback
 * error/state-mismatch/missing-credentials branches through the
 * `redirect_and_exit()` blocked-redirect contract (WPDieException),
 * the end-to-end happy path (state transient, GET token exchange,
 * `/me` profile fetch, settings persistence with the per-mode
 * sanitizer seam), the disconnect handler, and the capability gate.
 * Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Integrations\MetaOAuthHandler;

/**
 * Meta OAuth handler characterization.
 */
class Test_OAuth_Meta extends \WP_UnitTestCase {

	/**
	 * Handler instance.
	 *
	 * @var MetaOAuthHandler
	 */
	private $handler;

	/**
	 * Canned HTTP responses by URL.
	 *
	 * @var array<string, array|\WP_Error>
	 */
	private $responses = array();

	public function setUp(): void {
		parent::setUp();
		$this->handler = new MetaOAuthHandler();
		delete_option( 'wp_mcp_ai_settings' );
		delete_transient( 'settings_errors' );
		wp_set_current_user( 0 );
	}

	public function tearDown(): void {
		remove_filter( 'wp_redirect', '__return_false' );
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ) );
		delete_option( 'wp_mcp_ai_settings' );
		delete_transient( 'settings_errors' );
		unset( $_GET['state'], $_GET['code'], $_GET['error'], $_GET['error_description'], $_REQUEST['_wpnonce'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Intercept HTTP requests, routing by URL.
	 *
	 * @param bool   $preempt Preempt flag.
	 * @param array  $args    Request args.
	 * @param string $url     Request URL.
	 * @return array|\WP_Error|false
	 */
	public function intercept_http( $preempt, $args, $url ) {
		foreach ( $this->responses as $needle => $response ) {
			if ( false !== strpos( $url, $needle ) ) {
				return $response;
			}
		}
		return false;
	}

	/**
	 * Create an administrator and current user.
	 *
	 * @return int
	 */
	private function make_admin(): int {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		return $admin;
	}

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'https://www.facebook.com/v18.0/dialog/oauth', MetaOAuthHandler::META_OAUTH_AUTHORIZE_ENDPOINT );
		$this->assertSame( 'https://graph.facebook.com/v18.0/oauth/access_token', MetaOAuthHandler::META_OAUTH_TOKEN_ENDPOINT );
		$this->assertSame( 'https://graph.facebook.com/v18.0', MetaOAuthHandler::META_GRAPH_API_BASE );
		$this->assertSame( 'pages_manage_posts,instagram_basic,instagram_content_publish,whatsapp_business_management,whatsapp_business_messaging', MetaOAuthHandler::META_OAUTH_SCOPES );
		$this->assertSame( 'wp_mcp_ai_settings', MetaOAuthHandler::OPTION_NAME );
	}

	public function test_allow_meta_oauth_redirect_host(): void {
		$hosts = $this->handler->allow_meta_oauth_redirect_host( array( 'example.com' ), '' );

		$this->assertContains( 'www.facebook.com', $hosts );
		$this->assertContains( 'example.com', $hosts );
	}

	public function test_callback_requires_capability(): void {
		$this->expectException( 'WPDieException' );
		$this->handler->handle_meta_oauth_callback();
	}

	public function test_callback_error_branch(): void {
		$this->make_admin();
		add_filter( 'wp_redirect', '__return_false' );

		$_GET['error'] = 'access_denied';

		try {
			$this->handler->handle_meta_oauth_callback();
			$this->fail( 'Expected WPDieException from the blocked redirect.' );
		} catch ( \WPDieException $e ) {
			$this->assertInstanceOf( 'WPDieException', $e );
		}

		$notice = get_transient( 'settings_errors' );
		$this->assertIsArray( $notice );
		$this->assertSame( 'meta_oauth_error', $notice[0]['code'] );
	}

	public function test_callback_state_mismatch(): void {
		$this->make_admin();
		add_filter( 'wp_redirect', '__return_false' );

		$_GET['state'] = 'unknown-state';
		$_GET['code']  = 'code-1';

		try {
			$this->handler->handle_meta_oauth_callback();
			$this->fail( 'Expected WPDieException from the blocked redirect.' );
		} catch ( \WPDieException $e ) {
			$this->assertInstanceOf( 'WPDieException', $e );
		}

		$notice = get_transient( 'settings_errors' );
		$this->assertIsArray( $notice );
		$this->assertSame( 'meta_oauth_state_mismatch', $notice[0]['code'] );
	}

	public function test_callback_missing_credentials(): void {
		$admin = $this->make_admin();
		$state = wp_generate_uuid4();
		set_transient(
			'wp_mcp_ai_meta_state_' . md5( $state ),
			array(
				'user_id' => $admin,
				'time'    => time(),
			),
			600
		);

		add_filter( 'wp_redirect', '__return_false' );

		$_GET['state'] = $state;
		$_GET['code']  = 'code-1';

		try {
			$this->handler->handle_meta_oauth_callback();
			$this->fail( 'Expected WPDieException from the blocked redirect.' );
		} catch ( \WPDieException $e ) {
			$this->assertInstanceOf( 'WPDieException', $e );
		}

		$notice = get_transient( 'settings_errors' );
		$this->assertIsArray( $notice );
		$this->assertSame( 'meta_oauth_missing_client', $notice[0]['code'] );
	}

	public function test_callback_happy_path_persists_token_and_user(): void {
		$admin = $this->make_admin();
		update_option(
			'wp_mcp_ai_settings',
			array(
				'meta_app_id'     => 'app-id',
				'meta_app_secret' => 'app-secret',
			)
		);

		$state = wp_generate_uuid4();
		set_transient(
			'wp_mcp_ai_meta_state_' . md5( $state ),
			array(
				'user_id' => $admin,
				'time'    => time(),
			),
			600
		);

		$this->responses = array(
			'oauth/access_token' => array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'access_token' => 'EA_test_token_456' ) ),
			),
			'/me'                => array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'id'   => '123456789',
						'name' => 'Meta User',
					)
				),
			),
		);
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
		add_filter( 'wp_redirect', '__return_false' );

		$_GET['state'] = $state;
		$_GET['code']  = 'code-1';

		try {
			$this->handler->handle_meta_oauth_callback();
			$this->fail( 'Expected WPDieException from the blocked redirect.' );
		} catch ( \WPDieException $e ) {
			$this->assertInstanceOf( 'WPDieException', $e );
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertSame( 'EA_test_token_456', $settings['meta_access_token'] );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the legacy base sanitizer is defaults-driven and drops
			// the connected-user keys — byte-identical base behavior.
			$this->assertArrayNotHasKey( 'meta_connected_user_name', $settings );
			$this->assertArrayNotHasKey( 'meta_connected_user_id', $settings );
		} else {
			// Standalone: pass-through sanitizer seam (documented deviation)
			// preserves the already-sanitized values.
			$this->assertSame( 'Meta User', $settings['meta_connected_user_name'] );
			$this->assertSame( '123456789', $settings['meta_connected_user_id'] );
		}

		$notice = get_transient( 'settings_errors' );
		$this->assertIsArray( $notice );
		$this->assertSame( 'meta_oauth_success', $notice[0]['code'] );
	}

	public function test_disconnect_clears_tokens_only(): void {
		$this->make_admin();
		update_option(
			'wp_mcp_ai_settings',
			array(
				'meta_app_id'              => 'app-id',
				'meta_app_secret'          => 'app-secret',
				'meta_access_token'        => 'EA_test_token_456',
				'meta_connected_user_name' => 'Meta User',
				'meta_connected_user_id'   => '123456789',
			)
		);

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wp_mcp_ai_meta_disconnect' );
		add_filter( 'wp_redirect', '__return_false' );

		try {
			$this->handler->handle_meta_disconnect();
			$this->fail( 'Expected WPDieException from the blocked redirect.' );
		} catch ( \WPDieException $e ) {
			$this->assertInstanceOf( 'WPDieException', $e );
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertArrayNotHasKey( 'meta_access_token', $settings );
		$this->assertArrayNotHasKey( 'meta_connected_user_name', $settings );
		$this->assertArrayNotHasKey( 'meta_connected_user_id', $settings );
		$this->assertSame( 'app-id', $settings['meta_app_id'] );
	}

	public function test_start_missing_credentials(): void {
		$this->make_admin();
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wp_mcp_ai_meta_oauth_start' );
		add_filter( 'wp_redirect', '__return_false' );

		try {
			$this->handler->handle_meta_oauth_start();
			$this->fail( 'Expected WPDieException from the blocked redirect.' );
		} catch ( \WPDieException $e ) {
			$this->assertInstanceOf( 'WPDieException', $e );
		}

		$notice = get_transient( 'settings_errors' );
		$this->assertIsArray( $notice );
		$this->assertSame( 'meta_oauth_missing_client', $notice[0]['code'] );
	}
}
