<?php
/**
 * Tests for restriction instrumentation (rate limiter adapter hooks + lifts).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test the Nvoos rate limiter adapter's exceed-hook, key index, and
 * the registry's counter reset on lift.
 */
class Test_Restriction_Instrumentation extends WP_UnitTestCase {

	/**
	 * Rate limiter adapter under test.
	 *
	 * @var Nvoos\WordPress\Adapter\RateLimiter|null
	 */
	protected $limiter;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( class_exists( 'Nvoos\WordPress\Adapter\RateLimiter' ) ) {
			$this->limiter = new Nvoos\WordPress\Adapter\RateLimiter();
		}

		delete_option( 'wp_mcp_ai_rl_index' );
		delete_option( WP_MCP_AI_Restriction_Registry::INDEX_OPTION );
	}

	/**
	 * Clean up after test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_rl_index' );
		delete_option( WP_MCP_AI_Restriction_Registry::INDEX_OPTION );

		parent::tearDown();
	}

	/**
	 * Test the adapter fires the exceed action when a window is exhausted.
	 */
	public function test_adapter_fires_rate_limit_exceeded_action() {
		if ( null === $this->limiter ) {
			$this->markTestSkipped( 'Nvoos rate limiter adapter unavailable.' );
		}

		$fired = 0;
		add_action(
			'wp_mcp_ai_rate_limit_exceeded',
			function () use ( &$fired ) {
				++$fired;
			}
		);

		$key = 'chat:99:1';

		// Consume the whole window.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->limiter->record( $key, 60 );
		}

		$this->assertFalse( $this->limiter->isAllowed( $key, 3, 60 ) );
		$this->assertSame( 1, $fired );
	}

	/**
	 * Test the adapter keeps an enumerable index of active keys.
	 */
	public function test_adapter_enumerates_active_keys() {
		if ( null === $this->limiter ) {
			$this->markTestSkipped( 'Nvoos rate limiter adapter unavailable.' );
		}

		$this->limiter->record( 'chat:99:1', 60 );
		$this->limiter->record( 'chat:99:2', 60 );

		$keys = $this->limiter->enumerateKeys();

		$this->assertContains( 'chat:99:1', $keys );
		$this->assertContains( 'chat:99:2', $keys );
	}

	/**
	 * Test resetForPrefix clears only matching keys.
	 */
	public function test_adapter_reset_for_prefix() {
		if ( null === $this->limiter ) {
			$this->markTestSkipped( 'Nvoos rate limiter adapter unavailable.' );
		}

		$this->limiter->record( 'chat:99:1', 60 );
		$this->limiter->record( 'chat:99:2', 60 );
		$this->limiter->record( 'chat:88:1', 60 );

		$this->limiter->resetForPrefix( 'chat:99:' );

		$keys = $this->limiter->enumerateKeys();

		$this->assertNotContains( 'chat:99:1', $keys );
		$this->assertNotContains( 'chat:99:2', $keys );
		$this->assertContains( 'chat:88:1', $keys );
		$this->assertSame( 2, $this->limiter->remaining( 'chat:99:1', 2, 60 ) );
	}

	/**
	 * Test the registry subscribe path flags the user when the hook fires.
	 */
	public function test_registry_flags_user_from_adapter_hook() {
		if ( null === $this->limiter ) {
			$this->markTestSkipped( 'Nvoos rate limiter adapter unavailable.' );
		}

		WP_MCP_AI_Restriction_Registry::register();

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$key     = 'chat:' . $user_id . ':7';

		for ( $i = 0; $i < 2; $i++ ) {
			$this->limiter->record( $key, 60 );
		}
		$this->limiter->isAllowed( $key, 2, 60 );

		$this->assertTrue( WP_MCP_AI_Restriction_Registry::is_restricted( $user_id, WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT ) );

		delete_user_meta( $user_id, WP_MCP_AI_Restriction_Registry::USER_META_KEY );
	}

	/**
	 * Test lifting a rate-limit restriction resets the adapter windows.
	 */
	public function test_lift_resets_rate_limit_windows() {
		if ( null === $this->limiter ) {
			$this->markTestSkipped( 'Nvoos rate limiter adapter unavailable.' );
		}

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		WP_MCP_AI_Restriction_Registry::flag(
			$user_id,
			WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT,
			array(
				'scope'       => 'chat',
				'limit'       => 60,
				'window'      => 60,
				'released_at' => time() + 60,
			)
		);

		// A live window exists for this user (indexed via the adapter).
		$this->limiter->record( 'chat:' . $user_id . ':1', 60 );

		$result = WP_MCP_AI_Restriction_Registry::lift( $user_id, WP_MCP_AI_Restriction_Registry::TYPE_RATE_LIMIT, 1 );

		$this->assertTrue( $result );
		$this->assertFalse( WP_MCP_AI_Restriction_Registry::is_restricted( $user_id ) );

		// When the OOS bridge exposes an enumerable limiter, the lift must
		// clear the user's live windows through it.
		if ( function_exists( 'wp_mcp_ai_oos_rate_limiter' ) ) {
			$bridge_limiter = wp_mcp_ai_oos_rate_limiter();
			if ( is_object( $bridge_limiter ) && method_exists( $bridge_limiter, 'enumerateKeys' ) ) {
				$this->assertNotContains( 'chat:' . $user_id . ':1', $bridge_limiter->enumerateKeys() );
			}
		}

		delete_user_meta( $user_id, WP_MCP_AI_Restriction_Registry::USER_META_KEY );
	}
}
