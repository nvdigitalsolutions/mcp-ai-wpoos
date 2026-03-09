<?php
/**
 * Security Tests for NV oOS: SSE Rate Limiting
 *
 * Verifies that:
 * - SSE rate limiter correctly enforces per-user connection limits.
 * - SSE rate limiter correctly enforces global connection limits.
 * - Users with manage_options bypass rate limits.
 * - Connection registration and release work correctly.
 * - Rate limit errors return HTTP 429.
 *
 * @package WP_MCP_AI
 * @group security
 * @group rate-limit
 */

/**
 * SSE rate limiting test suite.
 */
class WP_MCP_AI_SSE_Rate_Limiting_Test extends WP_UnitTestCase {

	/**
	 * SSE rate limiter instance.
	 *
	 * @var WP_MCP_AI_SSE_Rate_Limiter
	 */
	private $limiter;

	/**
	 * Test user with subscriber role.
	 *
	 * @var int
	 */
	private $subscriber_user_id;

	/**
	 * Test user with admin role.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_SSE_Rate_Limiter' ) ) {
			$rate_limiter_file = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-sse-rate-limiter.php';
			if ( file_exists( $rate_limiter_file ) ) {
				require_once $rate_limiter_file;
			} else {
				$this->markTestSkipped( 'WP_MCP_AI_SSE_Rate_Limiter class not available.' );
				return;
			}
		}

		$this->limiter = new WP_MCP_AI_SSE_Rate_Limiter();

		$this->subscriber_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->admin_user_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Start as subscriber.
		wp_set_current_user( $this->subscriber_user_id );

		// Clear any existing counters.
		$this->limiter->reset_counters( $this->subscriber_user_id );
		$this->limiter->reset_counters( 0 );
	}

	/**
	 * Tear down: clear user and global counters.
	 */
	public function tearDown(): void {
		if ( $this->limiter ) {
			$this->limiter->reset_counters( $this->subscriber_user_id );
			$this->limiter->reset_counters( 0 );
		}

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * A fresh limiter should allow the first connection.
	 */
	public function test_first_connection_is_allowed() {
		$result = $this->limiter->check_connection_allowed();
		$this->assertTrue( $result, 'First connection should be allowed.' );
	}

	/**
	 * Connection registration increments the per-user counter.
	 */
	public function test_register_increments_user_counter() {
		$before = $this->limiter->get_user_connection_count( $this->subscriber_user_id );
		$this->limiter->register_connection( $this->subscriber_user_id );
		$after = $this->limiter->get_user_connection_count( $this->subscriber_user_id );

		$this->assertEquals( $before + 1, $after, 'User counter should increment after register.' );
	}

	/**
	 * Connection release decrements the per-user counter.
	 */
	public function test_release_decrements_user_counter() {
		$token  = $this->limiter->register_connection( $this->subscriber_user_id );
		$before = $this->limiter->get_user_connection_count( $this->subscriber_user_id );

		$this->limiter->release_connection( $token );
		$after = $this->limiter->get_user_connection_count( $this->subscriber_user_id );

		$this->assertEquals( $before - 1, $after, 'User counter should decrement after release.' );
	}

	/**
	 * Connection registration increments the global counter.
	 */
	public function test_register_increments_global_counter() {
		$before = $this->limiter->get_global_connection_count();
		$this->limiter->register_connection( $this->subscriber_user_id );
		$after = $this->limiter->get_global_connection_count();

		$this->assertEquals( $before + 1, $after, 'Global counter should increment after register.' );
	}

	/**
	 * Exceeding per-user limit returns a WP_Error with status 429.
	 */
	public function test_per_user_limit_returns_429_error() {
		// Set a low limit for testing.
		add_filter( 'wp_mcp_ai_sse_per_user_limit', fn() => 2 );

		// Register 2 connections (at the limit).
		$this->limiter->register_connection( $this->subscriber_user_id );
		$this->limiter->register_connection( $this->subscriber_user_id );

		// The next connection should be denied.
		$result = $this->limiter->check_connection_allowed();

		remove_all_filters( 'wp_mcp_ai_sse_per_user_limit' );

		$this->assertWPError( $result, 'At-limit connection should be denied with WP_Error.' );
		$this->assertEquals( 'wp_mcp_ai_sse_rate_limit', $result->get_error_code() );
		$error_data = $result->get_error_data();
		$this->assertEquals( 429, $error_data['status'] );
		$this->assertEquals( 'per_user', $error_data['type'] );
	}

	/**
	 * Exceeding global limit returns a WP_Error with status 429.
	 */
	public function test_global_limit_returns_429_error() {
		// Set a low global limit and a generous per-user limit.
		add_filter( 'wp_mcp_ai_sse_global_limit', fn() => 1 );
		add_filter( 'wp_mcp_ai_sse_per_user_limit', fn() => 100 );

		// Register 1 connection (at the global limit).
		$this->limiter->register_connection( $this->subscriber_user_id );

		$result = $this->limiter->check_connection_allowed();

		remove_all_filters( 'wp_mcp_ai_sse_global_limit' );
		remove_all_filters( 'wp_mcp_ai_sse_per_user_limit' );

		$this->assertWPError( $result, 'Over-global-limit connection should be denied with WP_Error.' );
		$this->assertEquals( 'wp_mcp_ai_sse_global_rate_limit', $result->get_error_code() );
		$error_data = $result->get_error_data();
		$this->assertEquals( 429, $error_data['status'] );
		$this->assertEquals( 'global', $error_data['type'] );
	}

	/**
	 * Admin users bypass per-user rate limits.
	 */
	public function test_admin_bypasses_per_user_limit() {
		wp_set_current_user( $this->admin_user_id );

		add_filter( 'wp_mcp_ai_sse_per_user_limit', fn() => 0 ); // Limit = 0.

		// Force the counter above 0 to simulate "over limit" state.
		$this->limiter->register_connection( $this->admin_user_id );
		$this->limiter->register_connection( $this->admin_user_id );

		$result = $this->limiter->check_connection_allowed();

		remove_all_filters( 'wp_mcp_ai_sse_per_user_limit' );
		wp_set_current_user( $this->subscriber_user_id );

		$this->assertTrue( $result, 'Admin should bypass per-user rate limits.' );
	}

	/**
	 * Admin users bypass global rate limits.
	 */
	public function test_admin_bypasses_global_limit() {
		wp_set_current_user( $this->admin_user_id );

		add_filter( 'wp_mcp_ai_sse_global_limit', fn() => 0 ); // Global limit = 0.

		$result = $this->limiter->check_connection_allowed();

		remove_all_filters( 'wp_mcp_ai_sse_global_limit' );
		wp_set_current_user( $this->subscriber_user_id );

		$this->assertTrue( $result, 'Admin should bypass global rate limits.' );
	}

	/**
	 * Releasing a connection allows a subsequent one.
	 */
	public function test_release_allows_next_connection() {
		add_filter( 'wp_mcp_ai_sse_per_user_limit', fn() => 1 );

		$token = $this->limiter->register_connection( $this->subscriber_user_id );

		// At limit — should be denied.
		$denied = $this->limiter->check_connection_allowed();
		$this->assertWPError( $denied );

		// Release one slot.
		$this->limiter->release_connection( $token );

		// Now should be allowed.
		$allowed = $this->limiter->check_connection_allowed();

		remove_all_filters( 'wp_mcp_ai_sse_per_user_limit' );

		$this->assertTrue( $allowed, 'After releasing a connection the next one should be permitted.' );
	}

	/**
	 * Releasing an invalid / expired token does not crash or throw.
	 */
	public function test_release_invalid_token_is_safe() {
		// Should simply return without errors.
		$this->limiter->release_connection( 'invalid-token-that-does-not-exist' );
		$this->assertTrue( true, 'Releasing invalid token should not crash.' );
	}

	/**
	 * Per-user limit filter is honoured.
	 */
	public function test_per_user_limit_filter_is_applied() {
		$custom_limit = 7;
		add_filter( 'wp_mcp_ai_sse_per_user_limit', fn() => $custom_limit );

		// Register (limit - 1) connections — should all be allowed.
		$tokens = array();
		for ( $i = 0; $i < $custom_limit - 1; $i++ ) {
			$tokens[] = $this->limiter->register_connection( $this->subscriber_user_id );
		}

		$result_before_limit = $this->limiter->check_connection_allowed();

		// Register the final one to hit the limit.
		$this->limiter->register_connection( $this->subscriber_user_id );

		$result_at_limit = $this->limiter->check_connection_allowed();

		remove_all_filters( 'wp_mcp_ai_sse_per_user_limit' );

		$this->assertTrue( $result_before_limit, 'Connection below limit should be allowed.' );
		$this->assertWPError( $result_at_limit, 'Connection at limit should be denied.' );
	}
}
