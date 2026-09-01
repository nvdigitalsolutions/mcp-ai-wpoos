<?php
/**
 * Provider circuit breaker port tests (Wave D4a).
 *
 * Characterization suite for `ProviderCircuitBreaker`. Assertions mirror
 * the base plugin's circuit breaker: constants, default allowance,
 * failure accumulation to OPEN, blocking while open, half-open transition
 * after the retry window, success/reset clearing state, and the
 * threshold/timeout filters.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Security\ProviderCircuitBreaker;

/**
 * @group security
 */
class Test_Provider_Circuit_Breaker extends \WP_UnitTestCase {

	public function tearDown(): void {
		\remove_all_filters( 'wp_mcp_ai_circuit_breaker_threshold' );
		\remove_all_filters( 'wp_mcp_ai_circuit_breaker_timeout' );

		ProviderCircuitBreaker::reset( 'openai' );
		ProviderCircuitBreaker::reset( 'gemini' );
		ProviderCircuitBreaker::reset( 'custom' );

		parent::tearDown();
	}

	public function test_constants_match_base(): void {
		$this->assertSame( 'wp_mcp_ai_cb_', ProviderCircuitBreaker::STATE_KEY_PREFIX );
		$this->assertSame( 5, ProviderCircuitBreaker::DEFAULT_THRESHOLD );
		$this->assertSame( 60, ProviderCircuitBreaker::DEFAULT_TIMEOUT );
		$this->assertSame( 'closed', ProviderCircuitBreaker::STATE_CLOSED );
		$this->assertSame( 'open', ProviderCircuitBreaker::STATE_OPEN );
		$this->assertSame( 'half_open', ProviderCircuitBreaker::STATE_HALF_OPEN );
	}

	public function test_allowed_by_default(): void {
		$this->assertTrue( ProviderCircuitBreaker::is_allowed( 'openai' ) );
		$this->assertSame( ProviderCircuitBreaker::STATE_CLOSED, ProviderCircuitBreaker::get_state( 'openai' ) );
	}

	public function test_failures_open_the_circuit(): void {
		for ( $i = 0; $i < ProviderCircuitBreaker::DEFAULT_THRESHOLD; $i++ ) {
			ProviderCircuitBreaker::record_failure( 'openai' );
		}

		$this->assertSame( ProviderCircuitBreaker::STATE_OPEN, ProviderCircuitBreaker::get_state( 'openai' ) );
		$this->assertFalse( ProviderCircuitBreaker::is_allowed( 'openai' ) );
	}

	public function test_open_circuit_blocks_until_retry_window(): void {
		\add_filter( 'wp_mcp_ai_circuit_breaker_threshold', static function () {
			return 3;
		} );

		ProviderCircuitBreaker::record_failure( 'gemini' );
		ProviderCircuitBreaker::record_failure( 'gemini' );
		ProviderCircuitBreaker::record_failure( 'gemini' );

		// Three failures reach the pinned threshold and open the circuit.
		$this->assertSame( ProviderCircuitBreaker::STATE_OPEN, ProviderCircuitBreaker::get_state( 'gemini' ) );
		$this->assertFalse( ProviderCircuitBreaker::is_allowed( 'gemini' ) );
	}

	public function test_open_circuit_transitions_to_half_open_after_timeout(): void {
		$key = ProviderCircuitBreaker::STATE_KEY_PREFIX . 'openai';

		// Simulate an open circuit whose retry window has elapsed.
		\set_transient(
			$key,
			\wp_json_encode(
				array(
					'state'       => ProviderCircuitBreaker::STATE_OPEN,
					'failures'    => 5,
					'retry_after' => time() - 10,
				)
			),
			120
		);

		$this->assertTrue( ProviderCircuitBreaker::is_allowed( 'openai' ) );
		$this->assertSame( ProviderCircuitBreaker::STATE_HALF_OPEN, ProviderCircuitBreaker::get_state( 'openai' ) );
	}

	public function test_open_circuit_still_blocks_before_timeout(): void {
		$key = ProviderCircuitBreaker::STATE_KEY_PREFIX . 'openai';

		\set_transient(
			$key,
			\wp_json_encode(
				array(
					'state'       => ProviderCircuitBreaker::STATE_OPEN,
					'failures'    => 5,
					'retry_after' => time() + 300,
				)
			),
			120
		);

		$this->assertFalse( ProviderCircuitBreaker::is_allowed( 'openai' ) );
		$this->assertSame( ProviderCircuitBreaker::STATE_OPEN, ProviderCircuitBreaker::get_state( 'openai' ) );
	}

	public function test_success_resets_the_circuit(): void {
		for ( $i = 0; $i < ProviderCircuitBreaker::DEFAULT_THRESHOLD; $i++ ) {
			ProviderCircuitBreaker::record_failure( 'openai' );
		}
		$this->assertSame( ProviderCircuitBreaker::STATE_OPEN, ProviderCircuitBreaker::get_state( 'openai' ) );

		ProviderCircuitBreaker::record_success( 'openai' );

		$this->assertSame( ProviderCircuitBreaker::STATE_CLOSED, ProviderCircuitBreaker::get_state( 'openai' ) );
		$this->assertTrue( ProviderCircuitBreaker::is_allowed( 'openai' ) );
	}

	public function test_reset_clears_state(): void {
		ProviderCircuitBreaker::record_failure( 'openai' );
		ProviderCircuitBreaker::reset( 'openai' );

		$this->assertSame( ProviderCircuitBreaker::STATE_CLOSED, ProviderCircuitBreaker::get_state( 'openai' ) );
	}

	public function test_custom_threshold_and_timeout_filters(): void {
		\add_filter( 'wp_mcp_ai_circuit_breaker_threshold', static function () {
			return 2;
		} );
		\add_filter( 'wp_mcp_ai_circuit_breaker_timeout', static function () {
			return 30;
		} );

		ProviderCircuitBreaker::record_failure( 'custom' );
		$this->assertSame( ProviderCircuitBreaker::STATE_CLOSED, ProviderCircuitBreaker::get_state( 'custom' ) );

		ProviderCircuitBreaker::record_failure( 'custom' );
		$this->assertSame( ProviderCircuitBreaker::STATE_OPEN, ProviderCircuitBreaker::get_state( 'custom' ) );

		$state = json_decode( (string) \get_transient( ProviderCircuitBreaker::STATE_KEY_PREFIX . 'custom' ), true );
		$this->assertSame( 2, $state['failures'] );
		$this->assertGreaterThan( time(), $state['retry_after'] );
		$this->assertLessThanOrEqual( time() + 31, $state['retry_after'] );
	}
}
