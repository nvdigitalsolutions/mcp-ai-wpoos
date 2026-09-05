<?php
/**
 * Rate limit manager port tests (Wave E2).
 *
 * Characterization suite for the ported `RateLimitManager`:
 * byte-identical constants, retry-loop contract (immediate success,
 * non-retriable bail, retriable retry-then-success, max-retries
 * exhaustion, retry-option overrides, default filters), Retry-After
 * resolution, retriable status/timeout tables, and the transient-backed
 * rate-limit state API. Retry tests run with zero delays so the
 * byte-identical sleep() costs nothing.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Queues\RateLimitManager;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixture shares this file with its test case.

/**
 * Seam subclass exposing protected members for contract testing.
 */
class RateLimitManagerSeam extends RateLimitManager {

	/**
	 * Expose calculate_retry_delay().
	 *
	 * @param \WP_Error $error      Error response.
	 * @param int       $base_delay Base delay.
	 * @param int       $max_delay  Max delay.
	 * @return int Delay in seconds.
	 */
	public static function seam_calculate_retry_delay( $error, $base_delay, $max_delay ) {
		return self::calculate_retry_delay( $error, $base_delay, $max_delay );
	}

	/**
	 * Expose is_retriable_error().
	 *
	 * @param \WP_Error $error Error to check.
	 * @return bool True if retriable.
	 */
	public static function seam_is_retriable_error( $error ) {
		return self::is_retriable_error( $error );
	}
}

/**
 * @group queues
 */
class Test_Rate_Limit_Manager extends \WP_UnitTestCase {

	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_rate_limit_max_retries' );
		remove_all_filters( 'wp_mcp_ai_rate_limit_initial_delay' );
		remove_all_filters( 'wp_mcp_ai_rate_limit_max_delay' );
		remove_all_filters( 'wp_mcp_ai_rate_limit_backoff_multiplier' );

		parent::tearDown();
	}

	/**
	 * Zero-delay retry options — sleep(0) keeps the byte-identical loop instant.
	 *
	 * @return array Retry options.
	 */
	private function fast_retries(): array {
		return array(
			'initial_delay' => 0,
			'max_delay'     => 0,
		);
	}

	/**
	 * Build a WP_Error with an HTTP status in its data.
	 *
	 * @param string $code   Error code.
	 * @param int    $status HTTP status.
	 * @return \WP_Error
	 */
	private function status_error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, 'HTTP failure.', array( 'status' => $status ) );
	}

	// ─── Constants ──────────────────────────────────────────────────

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'wp_mcp_ai_retry_', RateLimitManager::RETRY_STATE_PREFIX );
		$this->assertSame( 2, RateLimitManager::DEFAULT_INITIAL_DELAY );
		$this->assertSame( 30, RateLimitManager::DEFAULT_MAX_DELAY );
		$this->assertSame( 3, RateLimitManager::DEFAULT_MAX_RETRIES );
		$this->assertSame( 2, RateLimitManager::BACKOFF_MULTIPLIER );
	}

	// ─── Retry loop ─────────────────────────────────────────────────

	public function test_invalid_callable_returns_error(): void {
		$result = RateLimitManager::execute_with_retry( 'no_such_function_xyz' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_callable', $result->get_error_code() );
	}

	public function test_success_on_first_attempt(): void {
		$attempts = 0;
		$callable = static function () use ( &$attempts ) {
			++$attempts;
			return 'ok';
		};

		$result = RateLimitManager::execute_with_retry( $callable );

		$this->assertSame( 'ok', $result );
		$this->assertSame( 1, $attempts );
	}

	public function test_non_retriable_error_bails_immediately(): void {
		$attempts = 0;
		$callable = function () use ( &$attempts ) {
			++$attempts;
			return $this->status_error( 'bad_request', 400 );
		};

		$result = RateLimitManager::execute_with_retry( $callable, array(), $this->fast_retries() );

		$this->assertWPError( $result );
		$this->assertSame( 'bad_request', $result->get_error_code() );
		$this->assertSame( 1, $attempts );
	}

	public function test_rate_limit_429_retries_then_succeeds(): void {
		$attempts = 0;
		$callable = function () use ( &$attempts ) {
			++$attempts;
			if ( $attempts < 2 ) {
				return $this->status_error( 'too_many_requests', 429 );
			}
			return 'recovered';
		};

		$result = RateLimitManager::execute_with_retry( $callable, array(), $this->fast_retries() );

		$this->assertSame( 'recovered', $result );
		$this->assertSame( 2, $attempts );
	}

	public function test_server_errors_retry_then_succeed(): void {
		foreach ( array( 500, 502, 503, 504 ) as $status ) {
			$attempts = 0;
			$callable = function () use ( &$attempts, $status ) {
				++$attempts;
				if ( $attempts < 2 ) {
					return $this->status_error( 'server_error', $status );
				}
				return 'recovered';
			};

			$result = RateLimitManager::execute_with_retry( $callable, array(), $this->fast_retries() );

			$this->assertSame( 'recovered', $result, "Status {$status} should be retriable." );
			$this->assertSame( 2, $attempts );
		}
	}

	public function test_timeout_codes_retry_then_succeed(): void {
		foreach ( array( 'http_request_timeout', 'wp_mcp_ai_wordpress_timeout' ) as $code ) {
			$attempts = 0;
			$callable = function () use ( &$attempts, $code ) {
				++$attempts;
				if ( $attempts < 2 ) {
					return new \WP_Error( $code, 'Timed out.' );
				}
				return 'recovered';
			};

			$result = RateLimitManager::execute_with_retry( $callable, array(), $this->fast_retries() );

			$this->assertSame( 'recovered', $result, "Code {$code} should be retriable." );
			$this->assertSame( 2, $attempts );
		}
	}

	public function test_max_retries_exhausted_returns_last_error(): void {
		$attempts  = 0;
		$last_code = 'rate_limited';
		$callable  = function () use ( &$attempts, &$last_code ) {
			++$attempts;
			return $this->status_error( $last_code, 429 );
		};

		$options = array_merge( $this->fast_retries(), array( 'max_retries' => 2 ) );
		$result  = RateLimitManager::execute_with_retry( $callable, array(), $options );

		$this->assertWPError( $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame( 3, $attempts ); // Initial + 2 retries.
	}

	public function test_retry_options_override_defaults(): void {
		$attempts = 0;
		$callable = function () use ( &$attempts ) {
			++$attempts;
			return $this->status_error( 'rate_limited', 429 );
		};

		$options = array_merge( $this->fast_retries(), array( 'max_retries' => 0 ) );
		$result  = RateLimitManager::execute_with_retry( $callable, array(), $options );

		$this->assertWPError( $result );
		$this->assertSame( 1, $attempts );
	}

	public function test_max_retries_filter_changes_default(): void {
		add_filter(
			'wp_mcp_ai_rate_limit_max_retries',
			static function () {
				return 1;
			}
		);

		$attempts = 0;
		$callable = function () use ( &$attempts ) {
			++$attempts;
			return $this->status_error( 'rate_limited', 429 );
		};

		$result = RateLimitManager::execute_with_retry( $callable, array(), $this->fast_retries() );

		$this->assertWPError( $result );
		$this->assertSame( 2, $attempts ); // Initial + 1 filtered retry.
	}

	// ─── Delay calculation (seam) ───────────────────────────────────

	public function test_retry_delay_prefers_reset_seconds(): void {
		$error = new \WP_Error(
			'rate_limited',
			'Limited.',
			array( 'rate_limit_reset_seconds' => 12 )
		);

		$this->assertSame( 12, RateLimitManagerSeam::seam_calculate_retry_delay( $error, 5, 30 ) );
	}

	public function test_retry_delay_reset_seconds_capped_by_max(): void {
		$error = new \WP_Error(
			'rate_limited',
			'Limited.',
			array( 'rate_limit_reset_seconds' => 90 )
		);

		$this->assertSame( 30, RateLimitManagerSeam::seam_calculate_retry_delay( $error, 5, 30 ) );
	}

	public function test_retry_delay_honours_retry_after_header_case_insensitively(): void {
		$error = new \WP_Error(
			'rate_limited',
			'Limited.',
			array(
				'headers' => array( 'Retry-After' => 8 ),
			)
		);

		$this->assertSame( 8, RateLimitManagerSeam::seam_calculate_retry_delay( $error, 5, 30 ) );
	}

	public function test_retry_delay_header_capped_by_max(): void {
		$error = new \WP_Error(
			'rate_limited',
			'Limited.',
			array(
				'headers' => array( 'retry-after' => 45 ),
			)
		);

		$this->assertSame( 30, RateLimitManagerSeam::seam_calculate_retry_delay( $error, 5, 30 ) );
	}

	public function test_retry_delay_falls_back_to_base_delay(): void {
		$error = new \WP_Error( 'rate_limited', 'Limited.', array( 'status' => 429 ) );

		$this->assertSame( 5, RateLimitManagerSeam::seam_calculate_retry_delay( $error, 5, 30 ) );
		$this->assertSame( 25, RateLimitManagerSeam::seam_calculate_retry_delay( $error, 25, 30 ) );
		$this->assertSame( 30, RateLimitManagerSeam::seam_calculate_retry_delay( $error, 90, 30 ) );
	}

	public function test_retry_delay_zero_reset_seconds_falls_back(): void {
		$error = new \WP_Error(
			'rate_limited',
			'Limited.',
			array( 'rate_limit_reset_seconds' => 0 )
		);

		$this->assertSame( 5, RateLimitManagerSeam::seam_calculate_retry_delay( $error, 5, 30 ) );
	}

	// ─── Retriable classification (seam) ────────────────────────────

	public function test_retriable_error_classification(): void {
		$this->assertFalse( RateLimitManagerSeam::seam_is_retriable_error( 'not-an-error' ) );

		foreach ( array( 429, 500, 502, 503, 504 ) as $status ) {
			$this->assertTrue( RateLimitManagerSeam::seam_is_retriable_error( $this->status_error( 'http', $status ) ), "Status {$status} should be retriable." );
		}

		foreach ( array( 400, 401, 403, 404, 418 ) as $status ) {
			$this->assertFalse( RateLimitManagerSeam::seam_is_retriable_error( $this->status_error( 'http', $status ) ), "Status {$status} should not be retriable." );
		}

		$this->assertTrue( RateLimitManagerSeam::seam_is_retriable_error( new \WP_Error( 'http_request_timeout' ) ) );
		$this->assertTrue( RateLimitManagerSeam::seam_is_retriable_error( new \WP_Error( 'wp_mcp_ai_wordpress_timeout' ) ) );
		$this->assertFalse( RateLimitManagerSeam::seam_is_retriable_error( new \WP_Error( 'something_else' ) ) );
	}

	// ─── Transient rate-limit state ─────────────────────────────────

	public function test_rate_limit_state_roundtrip(): void {
		$retry_after = time() + 300;

		$this->assertFalse( RateLimitManager::is_rate_limited( 'svc-1' ) );
		$this->assertNull( RateLimitManager::get_retry_after( 'svc-1' ) );

		$this->assertTrue( RateLimitManager::set_rate_limit( 'svc-1', $retry_after ) );

		$this->assertTrue( RateLimitManager::is_rate_limited( 'svc-1' ) );
		$this->assertSame( $retry_after, RateLimitManager::get_retry_after( 'svc-1' ) );

		$this->assertTrue( RateLimitManager::clear_rate_limit( 'svc-1' ) );

		$this->assertFalse( RateLimitManager::is_rate_limited( 'svc-1' ) );
		$this->assertNull( RateLimitManager::get_retry_after( 'svc-1' ) );
	}

	public function test_past_retry_after_is_not_rate_limited(): void {
		RateLimitManager::set_rate_limit( 'svc-past', time() - 60 );

		$this->assertFalse( RateLimitManager::is_rate_limited( 'svc-past' ) );
		$this->assertSame( time() - 60, RateLimitManager::get_retry_after( 'svc-past' ) );

		RateLimitManager::clear_rate_limit( 'svc-past' );
	}

	public function test_malformed_retry_state_is_not_rate_limited(): void {
		$key = RateLimitManager::RETRY_STATE_PREFIX . md5( 'svc-malformed' );

		// String state (not an array).
		set_transient( $key, 'garbage', HOUR_IN_SECONDS );
		$this->assertFalse( RateLimitManager::is_rate_limited( 'svc-malformed' ) );
		$this->assertNull( RateLimitManager::get_retry_after( 'svc-malformed' ) );
		delete_transient( $key );

		// Array without retry_after.
		set_transient( $key, array( 'timestamp' => time() ), HOUR_IN_SECONDS );
		$this->assertFalse( RateLimitManager::is_rate_limited( 'svc-malformed' ) );
		$this->assertNull( RateLimitManager::get_retry_after( 'svc-malformed' ) );
		delete_transient( $key );
	}

	public function test_service_keys_are_independent(): void {
		RateLimitManager::set_rate_limit( 'svc-a', time() + 300 );

		$this->assertTrue( RateLimitManager::is_rate_limited( 'svc-a' ) );
		$this->assertFalse( RateLimitManager::is_rate_limited( 'svc-b' ) );

		RateLimitManager::clear_rate_limit( 'svc-a' );
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
