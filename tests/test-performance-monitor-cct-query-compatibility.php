<?php
/**
 * Tests for JetEngine Performance Monitor CCT query compatibility.
 *
 * Tests the backward-compatible query_items() implementation that handles
 * both old and new JetEngine API changes.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Performance Monitor CCT query compatibility.
 */
class WP_MCP_AI_Performance_Monitor_CCT_Query_Compatibility_Test extends WP_UnitTestCase {

	/**
	 * Test that query_items method exists and is public.
	 */
	public function test_query_items_method_exists() {
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Performance_Monitor_CCT', 'query_items' ),
			'query_items method should exist'
		);

		$reflection = new ReflectionMethod( 'WP_MCP_AI_Performance_Monitor_CCT', 'query_items' );
		$this->assertTrue(
			$reflection->isPublic(),
			'query_items method should be public'
		);
		$this->assertTrue(
			$reflection->isStatic(),
			'query_items method should be static'
		);
	}

	/**
	 * Test that get_content_type method exists.
	 */
	public function test_get_content_type_method_exists() {
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Performance_Monitor_CCT', 'get_content_type' ),
			'get_content_type method should exist'
		);
	}

	/**
	 * Test query_items returns empty array when JetEngine is not available.
	 */
	public function test_query_items_returns_empty_array_without_jetengine() {
		// Since JetEngine is not available in test environment,
		// query_items should return an empty array.
		$result = WP_MCP_AI_Performance_Monitor_CCT::query_items( array() );

		$this->assertIsArray( $result, 'query_items should return an array' );
		$this->assertEmpty( $result, 'query_items should return empty array when JetEngine is not available' );
	}

	/**
	 * Test prepare_jetengine_query_args converts simple args correctly.
	 */
	public function test_prepare_jetengine_query_args_simple_equality() {
		$reflection = new ReflectionMethod( 'WP_MCP_AI_Performance_Monitor_CCT', 'prepare_jetengine_query_args' );
		$reflection->setAccessible( true );

		$args = array(
			'component'   => 'rest_api',
			'test_type'   => 'stress',
			'test_status' => 'passed',
		);

		$result = $reflection->invoke( null, $args, (object) array() );

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result );

		// Check first argument.
		$this->assertArrayHasKey( 'field', $result[0] );
		$this->assertArrayHasKey( 'operator', $result[0] );
		$this->assertArrayHasKey( 'value', $result[0] );
		$this->assertEquals( 'component', $result[0]['field'] );
		$this->assertEquals( '=', $result[0]['operator'] );
		$this->assertEquals( 'rest_api', $result[0]['value'] );
	}

	/**
	 * Test prepare_jetengine_query_args handles date ranges.
	 */
	public function test_prepare_jetengine_query_args_date_range() {
		$reflection = new ReflectionMethod( 'WP_MCP_AI_Performance_Monitor_CCT', 'prepare_jetengine_query_args' );
		$reflection->setAccessible( true );

		$args = array(
			'component' => 'rest_api',
			'tested_at' => array(
				'type'  => 'DATE',
				'value' => array( '2024-01-01 00:00:00', '2024-12-31 23:59:59' ),
			),
		);

		$result = $reflection->invoke( null, $args, (object) array() );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );

		// Find the date range query arg.
		$date_arg = null;
		foreach ( $result as $arg ) {
			if ( isset( $arg['field'] ) && 'tested_at' === $arg['field'] ) {
				$date_arg = $arg;
				break;
			}
		}

		$this->assertNotNull( $date_arg, 'Date range argument should be present' );
		$this->assertEquals( 'BETWEEN', $date_arg['operator'] );
		$this->assertEquals( 'DATE', $date_arg['type'] );
		$this->assertIsArray( $date_arg['value'] );
		$this->assertCount( 2, $date_arg['value'] );
		$this->assertEquals( '2024-01-01 00:00:00', $date_arg['value'][0] );
		$this->assertEquals( '2024-12-31 23:59:59', $date_arg['value'][1] );
	}

	/**
	 * Test get_performance_trends uses query_items internally.
	 */
	public function test_get_performance_trends_uses_query_items() {
		// This should not error even without JetEngine.
		$trends = WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends( 'rest_api', '-7 days' );

		$this->assertIsArray( $trends );
		$this->assertArrayHasKey( 'trend', $trends );
	}

	/**
	 * Test query_items accepts and handles limit parameter.
	 */
	public function test_query_items_accepts_limit_parameter() {
		// Should not throw error even with custom limit.
		$result = WP_MCP_AI_Performance_Monitor_CCT::query_items( array( 'component' => 'rest_api' ), 50 );

		$this->assertIsArray( $result );
	}

	/**
	 * Test query_items accepts and handles offset parameter.
	 */
	public function test_query_items_accepts_offset_parameter() {
		// Should not throw error even with custom offset.
		$result = WP_MCP_AI_Performance_Monitor_CCT::query_items( array( 'component' => 'rest_api' ), 100, 10 );

		$this->assertIsArray( $result );
	}

	/**
	 * Test that get_content_type returns null when JetEngine is not available.
	 */
	public function test_get_content_type_returns_null_without_jetengine() {
		$reflection = new ReflectionMethod( 'WP_MCP_AI_Performance_Monitor_CCT', 'get_content_type' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( null );

		$this->assertNull( $result, 'get_content_type should return null when JetEngine is not available' );
	}

	/**
	 * Test backward compatibility: method signature matches expected usage.
	 */
	public function test_query_items_method_signature() {
		$reflection = new ReflectionMethod( 'WP_MCP_AI_Performance_Monitor_CCT', 'query_items' );
		$parameters = $reflection->getParameters();

		$this->assertCount( 3, $parameters, 'query_items should accept 3 parameters' );

		$this->assertEquals( 'args', $parameters[0]->getName() );
		$this->assertEquals( 'limit', $parameters[1]->getName() );
		$this->assertEquals( 'offset', $parameters[2]->getName() );

		// Check default values.
		$this->assertTrue( $parameters[1]->isDefaultValueAvailable() );
		$this->assertEquals( 100, $parameters[1]->getDefaultValue() );

		$this->assertTrue( $parameters[2]->isDefaultValueAvailable() );
		$this->assertEquals( 0, $parameters[2]->getDefaultValue() );
	}

	/**
	 * Test prepare_jetengine_query_args handles empty args.
	 */
	public function test_prepare_jetengine_query_args_empty_args() {
		$reflection = new ReflectionMethod( 'WP_MCP_AI_Performance_Monitor_CCT', 'prepare_jetengine_query_args' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( null, array(), (object) array() );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result, 'Empty args should produce empty query args' );
	}

	/**
	 * Test prepare_jetengine_query_args ignores malformed date ranges.
	 */
	public function test_prepare_jetengine_query_args_malformed_date() {
		$reflection = new ReflectionMethod( 'WP_MCP_AI_Performance_Monitor_CCT', 'prepare_jetengine_query_args' );
		$reflection->setAccessible( true );

		// Date range with wrong number of values.
		$args = array(
			'tested_at' => array(
				'type'  => 'DATE',
				'value' => array( '2024-01-01 00:00:00' ), // Only one value.
			),
		);

		$result = $reflection->invoke( null, $args, (object) array() );

		$this->assertIsArray( $result );
		// Malformed date range should be skipped, resulting in empty array.
		$this->assertEmpty( $result );
	}
}
