<?php
/**
 * Federation ported-class tests.
 *
 * Verifies the extraction port of the federation server surface
 * (src/Federation/) preserves the public behaviour of the base plugin's
 * federation classes (mcp-ai-wpoos/includes/).
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Federation\DirectoryRest;
use NvoosContentGraphAiPlatform\Federation\Federation;
use NvoosContentGraphAiPlatform\Federation\PeerCpt;
use NvoosContentGraphAiPlatform\Federation\PeerVerifier;
use NvoosContentGraphAiPlatform\Federation\RateLimiter;
use NvoosContentGraphAiPlatform\Federation\Settings;
use NvoosContentGraphAiPlatform\Federation\WellKnown;

/**
 * @group federation
 */
class Test_Platform_Federation extends \WP_UnitTestCase {

	public function test_wellknown_query_vars_added(): void {
		$wellknown = new WellKnown( null );

		$vars = $wellknown->add_query_vars( array() );
		$this->assertContains( 'wp_mcp_ai_wellknown', $vars );
	}

	public function test_wellknown_canonical_redirect_prevented(): void {
		$wellknown = new WellKnown( null );

		$this->assertFalse(
			$wellknown->prevent_canonical_redirect(
				'https://example.com/foo',
				'https://example.com/.well-known/ai-peer'
			)
		);
		$this->assertSame(
			'https://example.com/foo',
			$wellknown->prevent_canonical_redirect(
				'https://example.com/foo',
				'https://example.com/some-page'
			)
		);
	}

	public function test_wellknown_handles_null_registry_gracefully(): void {
		$wellknown = new WellKnown( null );

		$document = $this->invoke_protected( $wellknown, 'get_ai_peer_document' );
		$this->assertSame( '1.0', $document['version'] );
		$this->assertSame( array(), $document['capabilities'] );

		$jwks = $this->invoke_protected( $wellknown, 'get_jwks_document' );
		$this->assertArrayHasKey( 'keys', $jwks );
	}

	public function test_rate_limiter_allows_within_window(): void {
		$limiter = new RateLimiter();

		$result = $limiter->check_rate_limit( 'test_endpoint_' . uniqid(), 5, 60 );
		$this->assertTrue( $result );
	}

	public function test_rate_limiter_blocks_after_limit(): void {
		$limiter  = new RateLimiter();
		$endpoint = 'test_endpoint_' . uniqid();

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( $limiter->check_rate_limit( $endpoint, 5, 60 ) );
		}

		$result = $limiter->check_rate_limit( $endpoint, 5, 60 );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_rate_limit_exceeded', $result->get_error_code() );
	}

	public function test_rate_limit_headers_added(): void {
		$limiter  = new RateLimiter();
		$response = new \WP_REST_Response( array() );

		$modified = $limiter->add_rate_limit_headers( $response, 'headers_endpoint', 60, 60 );
		$this->assertSame( '60', $modified->get_headers()['X-RateLimit-Limit'] );
	}

	public function test_peer_meta_constants_match_base_cpt_values(): void {
		// Data stability: meta keys must stay byte-identical to the base CPT.
		$this->assertSame( '_wp_mcp_ai_peer_health_status', PeerCpt::META_HEALTH_STATUS );
		$this->assertSame( '_wp_mcp_ai_peer_wellknown_url', PeerCpt::META_WELLKNOWN_URL );
		$this->assertSame( 'ai_peer', PeerCpt::POST_TYPE );
	}

	public function test_peer_verifier_handles_wp_error_response(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'http_request_failed', 'Connection refused' );
			}
		);

		$peer_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$result = PeerVerifier::verify_peer( $peer_id, 'https://example.com/.well-known/ai-peer' );
		$this->assertWPError( $result );
		$this->assertSame( 'down', get_post_meta( $peer_id, PeerCpt::META_HEALTH_STATUS, true ) );

		remove_all_filters( 'pre_http_request' );
	}

	public function test_peer_cpt_registers_post_type(): void {
		new PeerCpt();
		PeerCpt::register_post_type();

		$this->assertTrue( post_type_exists( PeerCpt::POST_TYPE ) );
	}

	public function test_directory_rest_registers_routes(): void {
		// DirectoryRest hooks rest_api_init in its constructor; routes must be
		// registered on that action (WP 5.1+ requirement).
		new DirectoryRest();
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes( 'ai-dir/v1' );
		$this->assertArrayHasKey( '/ai-dir/v1/peers', $routes );
		$this->assertArrayHasKey( '/ai-dir/v1/peers/register', $routes );
		$this->assertArrayHasKey( '/ai-dir/v1/search', $routes );
		$this->assertArrayHasKey( '/ai-dir/v1/reverify/(?P<id>\d+)', $routes );
	}

	public function test_directory_rest_register_peer_creates_peer_post(): void {
		PeerCpt::register_post_type();

		// Simulate a healthy remote peer's well-known document + JWKS.
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, '.well-known/ai-peer' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'site_name'    => 'Remote Peer',
								'site_url'     => 'https://peer.example.com',
								'mcp'          => array( 'url' => 'https://peer.example.com/wp-json/mcp-ai/v1' ),
								'jwks_uri'     => 'https://peer.example.com/.well-known/jwks.json',
								'capabilities' => array( 'post_create' ),
							)
						),
					);
				}
				if ( false !== strpos( $url, '.well-known/jwks.json' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'keys' => array() ) ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$directory = new DirectoryRest();
		$request   = new \WP_REST_Request( 'POST', '/ai-dir/v1/peers/register' );
		$request->set_param( 'wellknown_url', 'https://peer.example.com/.well-known/ai-peer' );

		$response = $directory->register_peer( $request );
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$this->assertSame( 201, $response->get_status() );

		$data    = $response->get_data();
		$peer_id = $data['peer_id'];
		$this->assertSame( PeerCpt::POST_TYPE, get_post_type( $peer_id ) );
		$this->assertSame( 'healthy', get_post_meta( $peer_id, PeerCpt::META_HEALTH_STATUS, true ) );
		$this->assertSame( 'Remote Peer', get_post_meta( $peer_id, PeerCpt::META_SITE_NAME, true ) );

		remove_all_filters( 'pre_http_request' );
	}

	public function test_federation_bootstrap_gates_on_settings(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_federation'           => true,
				'enable_federation_directory' => false,
				'enable_mesh'                 => false,
			)
		);

		$federation = new Federation( null );
		$federation->maybe_load_federation_features();

		$this->assertTrue( Federation::is_enabled() );
		$this->assertFalse( Federation::is_directory_enabled() );
		$this->assertTrue( Settings::is_federation_enabled() );
		$this->assertFalse( Settings::is_directory_enabled() );
		$this->assertFalse( Settings::is_mesh_enabled() );
	}

	/**
	 * Invoke a protected method on an object for contract testing.
	 *
	 * @param object $instance Object instance.
	 * @param string $method   Method name.
	 * @return mixed Method result.
	 */
	private function invoke_protected( $instance, $method ) {
		$reflection = new \ReflectionMethod( $instance, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( $instance );
	}
}
