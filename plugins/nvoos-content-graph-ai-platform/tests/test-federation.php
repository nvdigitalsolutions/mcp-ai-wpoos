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

use NvoosContentGraphAiPlatform\Federation\PeerVerifier;
use NvoosContentGraphAiPlatform\Federation\RateLimiter;
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
		$this->assertSame( '_wp_mcp_ai_peer_health_status', PeerVerifier::META_HEALTH_STATUS );
		$this->assertSame( '_wp_mcp_ai_peer_wellknown_url', PeerVerifier::META_WELLKNOWN_URL );
		$this->assertSame( 'ai_peer', PeerVerifier::POST_TYPE );
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
		$this->assertSame( 'down', get_post_meta( $peer_id, PeerVerifier::META_HEALTH_STATUS, true ) );

		remove_all_filters( 'pre_http_request' );
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
