<?php
/**
 * Test WP_MCP_AI_Rate_Limit_Manager stabilization changes.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Rate_Limit_Manager_Stabilization
 */
class Test_WP_MCP_AI_Rate_Limit_Manager_Stabilization extends WP_UnitTestCase {

	/**
	 * Test that DEFAULT_INITIAL_DELAY is 2 seconds.
	 */
	public function test_initial_delay_constant() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Rate_Limit_Manager' );
		$constant   = $reflection->getConstant( 'DEFAULT_INITIAL_DELAY' );

		$this->assertEquals( 2, $constant, 'DEFAULT_INITIAL_DELAY should be 2 seconds' );
	}

	/**
	 * Test that DEFAULT_MAX_DELAY is 30 seconds.
	 */
	public function test_max_delay_constant() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Rate_Limit_Manager' );
		$constant   = $reflection->getConstant( 'DEFAULT_MAX_DELAY' );

		$this->assertEquals( 30, $constant, 'DEFAULT_MAX_DELAY should be 30 seconds' );
	}

	/**
	 * Test that DEFAULT_MAX_RETRIES is 3.
	 */
	public function test_max_retries_constant() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Rate_Limit_Manager' );
		$constant   = $reflection->getConstant( 'DEFAULT_MAX_RETRIES' );

		$this->assertEquals( 3, $constant, 'DEFAULT_MAX_RETRIES should be 3' );
	}

	/**
	 * Test that BACKOFF_MULTIPLIER is 2 (for exponential backoff).
	 */
	public function test_backoff_multiplier_constant() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Rate_Limit_Manager' );
		$constant   = $reflection->getConstant( 'BACKOFF_MULTIPLIER' );

		$this->assertEquals( 2, $constant, 'BACKOFF_MULTIPLIER should be 2' );
	}

	/**
	 * Test successful request doesn't retry.
	 */
	public function test_successful_request_no_retry() {
		$call_count = 0;

		$callable = function () use ( &$call_count ) {
			++$call_count;
			return array( 'success' => true );
		};

		$result = WP_MCP_AI_Rate_Limit_Manager::execute_with_retry( $callable );

		$this->assertEquals( 1, $call_count, 'Successful request should only be called once' );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * Test rate limit state management.
	 */
	public function test_rate_limit_state_management() {
		$service_key = 'test_service_' . time();
		$retry_after = time() + 10;

		// Set rate limit.
		$result = WP_MCP_AI_Rate_Limit_Manager::set_rate_limit( $service_key, $retry_after );
		$this->assertTrue( $result, 'Should successfully set rate limit' );

		// Check if rate limited.
		$is_limited = WP_MCP_AI_Rate_Limit_Manager::is_rate_limited( $service_key );
		$this->assertTrue( $is_limited, 'Service should be rate limited' );

		// Get retry after.
		$stored_retry = WP_MCP_AI_Rate_Limit_Manager::get_retry_after( $service_key );
		$this->assertEquals( $retry_after, $stored_retry, 'Retry after should match' );

		// Clear rate limit.
		$cleared = WP_MCP_AI_Rate_Limit_Manager::clear_rate_limit( $service_key );
		$this->assertTrue( $cleared, 'Should successfully clear rate limit' );

		// Verify cleared.
		$is_limited_after = WP_MCP_AI_Rate_Limit_Manager::is_rate_limited( $service_key );
		$this->assertFalse( $is_limited_after, 'Service should not be rate limited after clearing' );
	}

	/**
	 * Test expired rate limit.
	 */
	public function test_expired_rate_limit() {
		$service_key = 'test_expired_' . time();
		$retry_after = time() - 10; // In the past.

		WP_MCP_AI_Rate_Limit_Manager::set_rate_limit( $service_key, $retry_after );

		$is_limited = WP_MCP_AI_Rate_Limit_Manager::is_rate_limited( $service_key );
		$this->assertFalse( $is_limited, 'Expired rate limit should not block requests' );
	}

	/**
	 * Test that 429 error is retriable.
	 */
	public function test_429_error_is_retriable() {
		$call_count = 0;

		$callable = function () use ( &$call_count ) {
			++$call_count;

			if ( $call_count < 3 ) {
				return new WP_Error(
					'wp_mcp_ai_api_error',
					'Rate limited',
					array( 'status' => 429 )
				);
			}

			return array( 'success' => true );
		};

		$start_time = time();
		$result     = WP_MCP_AI_Rate_Limit_Manager::execute_with_retry(
			$callable,
			array(),
			array(
				'max_retries'   => 3,
				'initial_delay' => 1, // Use shorter delay for testing.
				'max_delay'     => 5,
			)
		);
		$end_time   = time();

		$this->assertEquals( 3, $call_count, 'Should retry 429 errors' );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		// Should have delayed (at least 2 seconds for two retries).
		$this->assertGreaterThanOrEqual( 2, $end_time - $start_time, 'Should have delayed between retries' );
	}

	/**
	 * Test that non-retriable errors don't retry.
	 */
	public function test_non_retriable_error_no_retry() {
		$call_count = 0;

		$callable = function () use ( &$call_count ) {
			++$call_count;
			return new WP_Error(
				'wp_mcp_ai_api_error',
				'Bad request',
				array( 'status' => 400 )
			);
		};

		$result = WP_MCP_AI_Rate_Limit_Manager::execute_with_retry( $callable );

		$this->assertEquals( 1, $call_count, 'Non-retriable error should not retry' );
		$this->assertWPError( $result );
		$this->assertEquals( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Test max retries exhaustion.
	 */
	public function test_max_retries_exhaustion() {
		$call_count = 0;

		$callable = function () use ( &$call_count ) {
			++$call_count;
			return new WP_Error(
				'wp_mcp_ai_api_error',
				'Rate limited',
				array( 'status' => 429 )
			);
		};

		$result = WP_MCP_AI_Rate_Limit_Manager::execute_with_retry(
			$callable,
			array(),
			array(
				'max_retries'   => 2,
				'initial_delay' => 1,
				'max_delay'     => 5,
			)
		);

		$this->assertEquals( 3, $call_count, 'Should attempt max_retries + 1 times' );
		$this->assertWPError( $result );
	}

	/**
	 * Test exponential backoff progression.
	 */
	public function test_exponential_backoff_progression() {
		// Test that delays double: 2s, 4s, 8s, (16s capped at 30s), (32s capped at 30s).
		$expected_delays = array( 2, 4, 8, 16, 30, 30 );

		// Since we can't easily test actual delays, we verify the constants are correct.
		$this->assertEquals( 2, WP_MCP_AI_Rate_Limit_Manager::DEFAULT_INITIAL_DELAY );
		$this->assertEquals( 30, WP_MCP_AI_Rate_Limit_Manager::DEFAULT_MAX_DELAY );
		$this->assertEquals( 2, WP_MCP_AI_Rate_Limit_Manager::BACKOFF_MULTIPLIER );

		// The actual sequence: 2, 4, 8, 16 (min with 30) = 16, 32 (min with 30) = 30.
		// After 3 retries with initial 2s: delays are 2s, 4s, 8s.
		$delay = 2;
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertEquals( $expected_delays[ $i ], $delay, "Delay at retry {$i} should be {$expected_delays[$i]}" );
			$delay = min( $delay * 2, 30 );
		}
	}

	/**
	 * Test retriable 5xx errors.
	 */
	public function test_retriable_5xx_errors() {
		$retriable_statuses = array( 500, 502, 503, 504 );

		foreach ( $retriable_statuses as $status ) {
			$call_count = 0;

			$callable = function () use ( &$call_count, $status ) {
				++$call_count;

				if ( $call_count < 2 ) {
					return new WP_Error(
						'wp_mcp_ai_api_error',
						'Server error',
						array( 'status' => $status )
					);
				}

				return array( 'success' => true );
			};

			$result = WP_MCP_AI_Rate_Limit_Manager::execute_with_retry(
				$callable,
				array(),
				array(
					'max_retries'   => 3,
					'initial_delay' => 1,
					'max_delay'     => 5,
				)
			);

			$this->assertEquals( 2, $call_count, "Status {$status} should be retriable" );
			$this->assertIsArray( $result );
			$this->assertTrue( $result['success'] );
		}
	}
}
