<?php
/**
 * SSE Rate Limiter port tests (Wave D1b).
 *
 * Characterization suite for the ported
 * `NvoosContentGraphAi\Chat\SseRateLimiter`. Assertions mirror the base
 * plugin's `tests/security/test-sse-rate-limiting.php` so the two
 * implementations stay behaviourally locked (ecosystem port plan,
 * principle: behaviour-preserving).
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Chat\SseRateLimiter;

/**
 * @group chat
 */
class Test_Sse_Rate_Limiter extends \WP_UnitTestCase {

	private $limiter;

	public function setUp(): void {
		parent::setUp();
		$this->limiter = new SseRateLimiter();
		$this->limiter->reset_counters();
		\wp_set_current_user( 0 );
	}

	public function tearDown(): void {
		$this->limiter->reset_counters();
		\wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_admins_bypass_all_limits(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );

		$this->assertTrue( $this->limiter->check_connection_allowed() );

		// Even with a saturated per-user counter, admins pass.
		\set_transient( SseRateLimiter::USER_TRANSIENT_PREFIX . $admin, 500, 3600 );
		$this->assertTrue( $this->limiter->check_connection_allowed() );
	}

	public function test_user_limit_is_enforced(): void {
		// A real user is required: wp_set_current_user() silently falls back
		// to user 0 for non-existent IDs, which would read the wrong counter.
		$user_id = self::factory()->user->create();

		for ( $i = 0; $i < SseRateLimiter::DEFAULT_PER_USER_LIMIT; $i++ ) {
			$this->limiter->register_connection( $user_id );
		}

		\wp_set_current_user( $user_id );
		$result = $this->limiter->check_connection_allowed();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_sse_rate_limit', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 429, $data['status'] );
		$this->assertSame( 'per_user', $data['type'] );
		$this->assertSame( SseRateLimiter::DEFAULT_PER_USER_LIMIT, $data['limit'] );
	}

	public function test_global_limit_is_enforced(): void {
		add_filter( 'wp_mcp_ai_sse_global_limit', static function () {
			return 2;
		} );

		// Saturate the global counter across two distinct users.
		$this->limiter->register_connection( 11 );
		$this->limiter->register_connection( 22 );

		\wp_set_current_user( 33 );
		$result = $this->limiter->check_connection_allowed();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_sse_global_rate_limit', $result->get_error_code() );
		$this->assertSame( 'global', $result->get_error_data()['type'] );

		remove_all_filters( 'wp_mcp_ai_sse_global_limit' );
	}

	public function test_register_and_release_tracks_counts(): void {
		$user_id = 55;

		$this->assertSame( 0, $this->limiter->get_user_connection_count( $user_id ) );
		$this->assertSame( 0, $this->limiter->get_global_connection_count() );

		$token_a = $this->limiter->register_connection( $user_id );
		$token_b = $this->limiter->register_connection( $user_id );

		$this->assertSame( 2, $this->limiter->get_user_connection_count( $user_id ) );
		$this->assertSame( 2, $this->limiter->get_global_connection_count() );
		$this->assertNotSame( $token_a, $token_b );

		$this->limiter->release_connection( $token_a );
		$this->assertSame( 1, $this->limiter->get_user_connection_count( $user_id ) );
		$this->assertSame( 1, $this->limiter->get_global_connection_count() );

		$this->limiter->release_connection( $token_b );
		$this->assertSame( 0, $this->limiter->get_user_connection_count( $user_id ) );
		$this->assertSame( 0, $this->limiter->get_global_connection_count() );
	}

	public function test_release_with_invalid_token_is_noop(): void {
		$this->limiter->register_connection( 77 );
		$this->assertSame( 1, $this->limiter->get_global_connection_count() );

		$this->limiter->release_connection( 'not-a-real-token' );

		$this->assertSame( 1, $this->limiter->get_global_connection_count() );
	}

	public function test_release_floors_at_zero(): void {
		$token = $this->limiter->register_connection( 88 );
		$this->limiter->release_connection( $token );
		$this->limiter->release_connection( $token );

		$this->assertSame( 0, $this->limiter->get_user_connection_count( 88 ) );
		$this->assertSame( 0, $this->limiter->get_global_connection_count() );
	}

	public function test_reset_counters_scoped_and_global(): void {
		$this->limiter->register_connection( 100 );
		$this->limiter->register_connection( 200 );

		$this->limiter->reset_counters( 100 );
		$this->assertSame( 0, $this->limiter->get_user_connection_count( 100 ) );
		$this->assertSame( 1, $this->limiter->get_user_connection_count( 200 ) );

		$this->limiter->reset_counters();
		$this->assertSame( 0, $this->limiter->get_global_connection_count() );
	}
}
