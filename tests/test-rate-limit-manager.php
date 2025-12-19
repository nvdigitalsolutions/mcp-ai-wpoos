<?php
/**
 * Tests for the Rate Limit Manager.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Rate_Limit_Manager_Test extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Clear all rate limit transients.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_mcp_ai_retry_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wp_mcp_ai_retry_%'" );
		parent::tearDown();
	}

	/**
	 * Test successful execution without retry.
	 */
	public function test_execute_with_retry_success() {
		$callable = function () {
			return array( 'status' => 'success' );
		};

		$result = WP_MCP_AI_Rate_Limit_Manager::execute_with_retry( $callable );

		$this->assertIsArray( $result );
		$this->assertSame( 'success', $result['status'] );
	}

	/**
	 * Test retry on rate limit error.
	 */
	public function test_execute_with_retry_on_rate_limit() {
		$attempts = 0;

		$callable = function () use ( &$attempts ) {
			++$attempts;

			// Fail on first attempt, succeed on second.
			if ( 1 === $attempts ) {
				return new WP_Error(
					'rate_limit_exceeded',
					'Rate limit exceeded',
					array( 'status' => 429 )
				);
			}

			return array( 'status' => 'success' );
		};

		$result = WP_MCP_AI_Rate_Limit_Manager::execute_with_retry(
			$callable,
			array(),
			array(
				'max_retries'   => 3,
				'initial_delay' => 0, // No delay in tests.
			)
		);

		$this->assertSame( 2, $attempts );
		$this->assertIsArray( $result );
		$this->assertSame( 'success', $result['status'] );
	}

	/**
	 * Test max retries exhausted.
	 */
	public function test_execute_with_retry_max_retries() {
		$attempts = 0;

		$callable = function () use ( &$attempts ) {
			++$attempts;
			return new WP_Error(
				'rate_limit_exceeded',
				'Rate limit exceeded',
				array( 'status' => 429 )
			);
		};

		$result = WP_MCP_AI_Rate_Limit_Manager::execute_with_retry(
			$callable,
			array(),
			array(
				'max_retries'   => 2,
				'initial_delay' => 0,
			)
		);

		// Should attempt 3 times (initial + 2 retries).
		$this->assertSame( 3, $attempts );
		$this->assertWPError( $result );
	}

	/**
	 * Test retry with Retry-After header.
	 */
	public function test_execute_with_retry_after_header() {
		$attempts = 0;

		$callable = function () use ( &$attempts ) {
			++$attempts;

			if ( 1 === $attempts ) {
				return new WP_Error(
					'rate_limit_exceeded',
					'Rate limit exceeded',
					array(
						'status'  => 429,
						'headers' => array( 'Retry-After' => '2' ),
					)
				);
			}

			return array( 'status' => 'success' );
		};

		$result = WP_MCP_AI_Rate_Limit_Manager::execute_with_retry(
			$callable,
			array(),
			array(
				'max_retries'   => 3,
				'initial_delay' => 0,
			)
		);

		$this->assertSame( 2, $attempts );
		$this->assertIsArray( $result );
	}

	/**
	 * Test non-retriable errors fail immediately.
	 */
	public function test_execute_with_retry_non_retriable() {
		$attempts = 0;

		$callable = function () use ( &$attempts ) {
			++$attempts;
			return new WP_Error(
				'invalid_request',
				'Invalid request',
				array( 'status' => 400 )
			);
		};

		$result = WP_MCP_AI_Rate_Limit_Manager::execute_with_retry(
			$callable,
			array(),
			array( 'max_retries' => 3 )
		);

		// Should only attempt once.
		$this->assertSame( 1, $attempts );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_request', $result->get_error_code() );
	}

	/**
	 * Test is_rate_limited method.
	 */
	public function test_is_rate_limited() {
		$service_key = 'test_service';

		// Should not be rate limited initially.
		$this->assertFalse( WP_MCP_AI_Rate_Limit_Manager::is_rate_limited( $service_key ) );

		// Set rate limit.
		$retry_after = time() + 10;
		WP_MCP_AI_Rate_Limit_Manager::set_rate_limit( $service_key, $retry_after );

		// Should now be rate limited.
		$this->assertTrue( WP_MCP_AI_Rate_Limit_Manager::is_rate_limited( $service_key ) );

		// Clear rate limit.
		WP_MCP_AI_Rate_Limit_Manager::clear_rate_limit( $service_key );

		// Should no longer be rate limited.
		$this->assertFalse( WP_MCP_AI_Rate_Limit_Manager::is_rate_limited( $service_key ) );
	}

	/**
	 * Test get_retry_after method.
	 */
	public function test_get_retry_after() {
		$service_key = 'test_service';
		$retry_after = time() + 30;

		// Should return null initially.
		$this->assertNull( WP_MCP_AI_Rate_Limit_Manager::get_retry_after( $service_key ) );

		// Set rate limit.
		WP_MCP_AI_Rate_Limit_Manager::set_rate_limit( $service_key, $retry_after );

		// Should return the retry-after timestamp.
		$this->assertSame( $retry_after, WP_MCP_AI_Rate_Limit_Manager::get_retry_after( $service_key ) );
	}

	/**
	 * Test invalid callable returns error.
	 */
	public function test_execute_with_retry_invalid_callable() {
		$result = WP_MCP_AI_Rate_Limit_Manager::execute_with_retry( 'not_a_callable' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_callable', $result->get_error_code() );
	}
}
