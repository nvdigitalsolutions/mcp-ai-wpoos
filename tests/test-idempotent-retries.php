<?php
/**
 * Tests for idempotent retry functionality.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test idempotency features in Rate Limit Manager.
 */
class Test_Idempotent_Retries extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Clear all idempotency transients.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_mcp_ai_idempotent_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wp_mcp_ai_idempotent_%'" );
		parent::tearDown();
	}

	/**
	 * Test generate_idempotency_key creates consistent keys.
	 */
	public function test_generate_idempotency_key_consistent() {
		$operation = 'test_operation';
		$params    = array( 'param1' => 'value1', 'param2' => 'value2' );

		$key1 = WP_MCP_AI_Rate_Limit_Manager::generate_idempotency_key( $operation, $params );
		$key2 = WP_MCP_AI_Rate_Limit_Manager::generate_idempotency_key( $operation, $params );

		$this->assertSame( $key1, $key2 );
	}

	/**
	 * Test idempotency key ignores parameter order.
	 */
	public function test_idempotency_key_ignores_order() {
		$operation = 'test_operation';
		$params1   = array( 'param1' => 'value1', 'param2' => 'value2' );
		$params2   = array( 'param2' => 'value2', 'param1' => 'value1' );

		$key1 = WP_MCP_AI_Rate_Limit_Manager::generate_idempotency_key( $operation, $params1 );
		$key2 = WP_MCP_AI_Rate_Limit_Manager::generate_idempotency_key( $operation, $params2 );

		$this->assertSame( $key1, $key2 );
	}

	/**
	 * Test idempotency key excludes non-deterministic parameters.
	 */
	public function test_idempotency_key_excludes_nondeterministic() {
		$operation = 'test_operation';
		$params1   = array( 'param1' => 'value1', 'timestamp' => time() );
		$params2   = array( 'param1' => 'value1', 'timestamp' => time() + 100 );

		$key1 = WP_MCP_AI_Rate_Limit_Manager::generate_idempotency_key( $operation, $params1 );
		$key2 = WP_MCP_AI_Rate_Limit_Manager::generate_idempotency_key( $operation, $params2 );

		// Keys should be the same despite different timestamps.
		$this->assertSame( $key1, $key2 );
	}

	/**
	 * Test store and retrieve idempotent result.
	 */
	public function test_store_and_get_idempotent_result() {
		$idempotency_key = 'idem_test_123';
		$result          = array( 'status' => 'success', 'data' => 'test data' );

		// Initially should return null.
		$cached = WP_MCP_AI_Rate_Limit_Manager::get_idempotent_result( $idempotency_key );
		$this->assertNull( $cached );

		// Store result.
		$stored = WP_MCP_AI_Rate_Limit_Manager::store_idempotent_result( $idempotency_key, $result );
		$this->assertTrue( $stored );

		// Retrieve result.
		$cached = WP_MCP_AI_Rate_Limit_Manager::get_idempotent_result( $idempotency_key );
		$this->assertSame( $result, $cached );
	}

	/**
	 * Test execute_idempotent_with_retry returns cached result.
	 */
	public function test_execute_idempotent_with_retry_uses_cache() {
		$call_count = 0;

		$callable = function () use ( &$call_count ) {
			++$call_count;
			return array( 'result' => 'success', 'count' => $call_count );
		};

		$idempotency_key = 'idem_test_retry';

		// First execution.
		$result1 = WP_MCP_AI_Rate_Limit_Manager::execute_idempotent_with_retry(
			$callable,
			array(),
			$idempotency_key,
			array( 'initial_delay' => 0 )
		);

		$this->assertSame( 1, $call_count );
		$this->assertSame( 1, $result1['count'] );

		// Second execution should use cached result.
		$result2 = WP_MCP_AI_Rate_Limit_Manager::execute_idempotent_with_retry(
			$callable,
			array(),
			$idempotency_key,
			array( 'initial_delay' => 0 )
		);

		// Callable should not be called again.
		$this->assertSame( 1, $call_count );
		// Result should be from cache.
		$this->assertSame( 1, $result2['count'] );
	}

	/**
	 * Test execute_idempotent_with_retry without key doesn't cache.
	 */
	public function test_execute_idempotent_without_key_no_cache() {
		$call_count = 0;

		$callable = function () use ( &$call_count ) {
			++$call_count;
			return array( 'result' => 'success' );
		};

		// First execution without idempotency key.
		WP_MCP_AI_Rate_Limit_Manager::execute_idempotent_with_retry(
			$callable,
			array(),
			'',
			array( 'initial_delay' => 0 )
		);

		$this->assertSame( 1, $call_count );

		// Second execution should call again.
		WP_MCP_AI_Rate_Limit_Manager::execute_idempotent_with_retry(
			$callable,
			array(),
			'',
			array( 'initial_delay' => 0 )
		);

		$this->assertSame( 2, $call_count );
	}

	/**
	 * Test execute_idempotent_with_retry doesn't cache errors.
	 */
	public function test_execute_idempotent_doesnt_cache_errors() {
		$call_count = 0;

		$callable = function () use ( &$call_count ) {
			++$call_count;

			if ( 1 === $call_count ) {
				return new WP_Error( 'test_error', 'Test error message' );
			}

			return array( 'result' => 'success' );
		};

		$idempotency_key = 'idem_test_error';

		// First execution returns error.
		$result1 = WP_MCP_AI_Rate_Limit_Manager::execute_idempotent_with_retry(
			$callable,
			array(),
			$idempotency_key,
			array( 'initial_delay' => 0, 'max_retries' => 0 )
		);

		$this->assertWPError( $result1 );
		$this->assertSame( 1, $call_count );

		// Second execution should try again (error wasn't cached).
		$result2 = WP_MCP_AI_Rate_Limit_Manager::execute_idempotent_with_retry(
			$callable,
			array(),
			$idempotency_key,
			array( 'initial_delay' => 0 )
		);

		// Callable was called again.
		$this->assertSame( 2, $call_count );
		// This time it succeeded.
		$this->assertIsArray( $result2 );
		$this->assertSame( 'success', $result2['result'] );
	}

	/**
	 * Test idempotent retry with rate limit error.
	 */
	public function test_idempotent_retry_with_rate_limit() {
		$call_count = 0;

		$callable = function () use ( &$call_count ) {
			++$call_count;

			if ( 1 === $call_count ) {
				return new WP_Error(
					'rate_limit_exceeded',
					'Rate limit exceeded',
					array( 'status' => 429 )
				);
			}

			return array( 'result' => 'success', 'attempt' => $call_count );
		};

		$idempotency_key = 'idem_test_rate_limit';

		$result = WP_MCP_AI_Rate_Limit_Manager::execute_idempotent_with_retry(
			$callable,
			array(),
			$idempotency_key,
			array( 'initial_delay' => 0, 'max_retries' => 2 )
		);

		// Should have retried and succeeded.
		$this->assertSame( 2, $call_count );
		$this->assertIsArray( $result );
		$this->assertSame( 'success', $result['result'] );

		// Now result should be cached.
		$cached = WP_MCP_AI_Rate_Limit_Manager::get_idempotent_result( $idempotency_key );
		$this->assertNotNull( $cached );
		$this->assertSame( 2, $cached['attempt'] );
	}
}
