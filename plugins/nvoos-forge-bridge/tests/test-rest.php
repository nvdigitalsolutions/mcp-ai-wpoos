<?php
/**
 * REST + preview-token tests — routes, HMAC fail-closed, capability gates,
 * and single-use consumption.
 *
 * @package NvoosForgeBridge
 */

use NvoosForgeBridge\Preview_Token;
use NvoosForgeBridge\Rebuild_Webhook;
use NvoosForgeBridge\Settings;

/**
 * REST suite.
 */
class Test_Forge_Bridge_Rest extends WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Reset state + boot a fresh REST server.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		foreach ( Settings::option_keys() as $option ) {
			delete_option( $option );
		}
		delete_transient( 'nvoos_forge_bridge_preview_tokens' );
		delete_transient( 'nvoos_forge_bridge_webhook_sent' );
		Rebuild_Webhook::reset_pending();

		global $wp_rest_server;
		$wp_rest_server = null;
		$this->server   = rest_get_server();
	}

	/**
	 * Teardown.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tearDown();
	}

	/**
	 * The three routes register under nvoos-forge/v1.
	 */
	public function test_routes_registered(): void {
		$routes = $this->server->get_routes( 'nvoos-forge/v1' );
		$paths  = array_keys( $routes );
		$this->assertContains( '/nvoos-forge/v1/rebuild', $paths );
		$this->assertContains( '/nvoos-forge/v1/health', $paths );
		$this->assertContains( '/nvoos-forge/v1/preview-tokens', $paths );
		$this->assertContains( '/nvoos-forge/v1/preview-tokens/verify', $paths );
	}

	/**
	 * Health is public and redacted — booleans + hosts, never secrets.
	 */
	public function test_health_is_redacted(): void {
		update_option( Settings::OPTION_REBUILD_URL, 'https://api.github.com/repos/o/r/dispatches' );
		update_option( Settings::OPTION_REBUILD_TOKEN, 'supersecret' );
		update_option( Settings::OPTION_REBUILD_SECRET, 'supersecret' );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/nvoos-forge/v1/health' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'api.github.com', $data['rebuildUrlHost'] );
		$this->assertFalse( isset( $data['token'] ) );
		$this->assertFalse( isset( $data['secret'] ) );
		$this->assertStringNotContainsString( 'supersecret', wp_json_encode( $data ) );
	}

	/**
	 * An inbound rebuild without a valid signature is rejected (fail closed).
	 */
	public function test_rebuild_requires_valid_signature(): void {
		update_option( Settings::OPTION_REBUILD_ENABLED, '1' );
		update_option( Settings::OPTION_REBUILD_URL, 'https://api.github.com/repos/o/r/dispatches' );
		update_option( Settings::OPTION_REBUILD_TOKEN, 'gh-token' );
		update_option( Settings::OPTION_REBUILD_SECRET, 'shared-secret' );

		$request = new WP_REST_Request( 'POST', '/nvoos-forge/v1/rebuild' );
		$request->set_body( '{}' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'nvoos_forge_bridge_bad_signature', $response->get_data()['code'] );
	}

	/**
	 * A correctly signed inbound rebuild is accepted (202).
	 */
	public function test_rebuild_accepts_valid_signature(): void {
		update_option( Settings::OPTION_REBUILD_SECRET, 'shared-secret' );

		$body      = '{"event":"manual"}';
		$request   = new WP_REST_Request( 'POST', '/nvoos-forge/v1/rebuild' );
		$request->set_body( $body );
		$request->set_header( 'X-Forge-Signature', Rebuild_Webhook::sign( $body ) );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 202, $response->get_status() );
		$this->assertTrue( $response->get_data()['accepted'] );
	}

	/**
	 * Minting requires edit_posts.
	 */
	public function test_mint_requires_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/nvoos-forge/v1/preview-tokens' ) );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Minted tokens verify once, then die.
	 */
	public function test_preview_token_single_use(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/nvoos-forge/v1/preview-tokens' ) );
		$this->assertSame( 201, $response->get_status() );
		$token = $response->get_data()['token'];
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $token );

		$verify = new WP_REST_Request( 'GET', '/nvoos-forge/v1/preview-tokens/verify' );
		$verify->set_param( 'token', $token );
		$first = $this->server->dispatch( $verify );
		$this->assertSame( 200, $first->get_status() );
		$this->assertTrue( $first->get_data()['valid'] );

		$second = $this->server->dispatch( $verify );
		$this->assertSame( 403, $second->get_status() );
		$this->assertFalse( $second->get_data()['valid'] );
	}

	/**
	 * Garbage tokens fail closed.
	 */
	public function test_preview_token_rejects_junk(): void {
		$verify = new WP_REST_Request( 'GET', '/nvoos-forge/v1/preview-tokens/verify' );
		$verify->set_param( 'token', 'not-a-token' );
		$response = $this->server->dispatch( $verify );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Expired tokens fail closed.
	 */
	public function test_preview_token_expiry(): void {
		$token = Preview_Token::mint();

		// Age the store past its TTL by rewriting the transient directly.
		$tokens = get_transient( 'nvoos_forge_bridge_preview_tokens' );
		foreach ( array_keys( $tokens ) as $hash ) {
			$tokens[ $hash ] = time() - 1;
		}
		set_transient( 'nvoos_forge_bridge_preview_tokens', $tokens, Preview_Token::TTL_SECONDS );

		$this->assertFalse( Preview_Token::verify_and_consume( $token ) );
	}
}
