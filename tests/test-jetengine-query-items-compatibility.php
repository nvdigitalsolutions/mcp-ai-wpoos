<?php
/**
 * Tests for JetEngine query_items() compatibility fallback.
 *
 * @package WP_MCP_AI
 */

/**
 * Test JetEngine query_items compatibility.
 */
class WP_MCP_AI_JetEngine_Query_Items_Compatibility_Test extends WP_UnitTestCase {

	/**
	 * Test query_items_safe with handler that has query_items method.
	 */
	public function test_query_items_safe_with_query_items_method() {
		// Create a mock handler with query_items method.
		$handler = $this->create_mock_handler_with_query_items();
		
		// Use reflection to test protected method.
		$class = new ReflectionClass( 'WP_MCP_AI_Performance_Monitor_CCT' );
		$method = $class->getMethod( 'query_items_safe' );
		$method->setAccessible( true );
		
		$args = array( 'test' => 'value' );
		$result = $method->invokeArgs( null, array( $handler, $args ) );
		
		$this->assertIsArray( $result );
		$this->assertEquals( 'query_items', $result['method_used'] );
	}

	/**
	 * Test query_items_safe with handler that only has factory->db->query.
	 */
	public function test_query_items_safe_with_factory_query_method() {
		// Create a mock handler with factory->db->query method.
		$handler = $this->create_mock_handler_with_factory_query();
		
		// Use reflection to test protected method.
		$class = new ReflectionClass( 'WP_MCP_AI_Performance_Monitor_CCT' );
		$method = $class->getMethod( 'query_items_safe' );
		$method->setAccessible( true );
		
		$args = array( 'test' => 'value' );
		$result = $method->invokeArgs( null, array( $handler, $args ) );
		
		$this->assertIsArray( $result );
		$this->assertEquals( 'factory_query', $result['method_used'] );
	}

	/**
	 * Test query_items_safe with handler that has no query methods.
	 */
	public function test_query_items_safe_with_no_query_methods() {
		// Create a mock handler with no query methods.
		$handler = new stdClass();
		
		// Use reflection to test protected method.
		$class = new ReflectionClass( 'WP_MCP_AI_Performance_Monitor_CCT' );
		$method = $class->getMethod( 'query_items_safe' );
		$method->setAccessible( true );
		
		$args = array( 'test' => 'value' );
		$result = $method->invokeArgs( null, array( $handler, $args ) );
		
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test performance reporter query_items_safe method.
	 */
	public function test_performance_reporter_query_items_safe() {
		// Create a mock handler with query_items method.
		$handler = $this->create_mock_handler_with_query_items();
		
		// Use reflection to test protected method.
		$class = new ReflectionClass( 'WP_MCP_AI_Performance_Reporter' );
		$method = $class->getMethod( 'query_items_safe' );
		$method->setAccessible( true );
		
		$args = array( 'component' => 'rest_api' );
		$result = $method->invokeArgs( null, array( $handler, $args ) );
		
		$this->assertIsArray( $result );
		$this->assertEquals( 'query_items', $result['method_used'] );
	}

	/**
	 * Create mock handler with query_items method.
	 *
	 * @return object Mock handler.
	 */
	protected function create_mock_handler_with_query_items() {
		return new class() {
			public function query_items( $args ) {
				return array( 'method_used' => 'query_items', 'args' => $args );
			}
		};
	}

	/**
	 * Create mock handler with factory->db->query method.
	 *
	 * @return object Mock handler.
	 */
	protected function create_mock_handler_with_factory_query() {
		return new class() {
			public function get_factory() {
				return new class() {
					public $db;

					public function __construct() {
						$this->db = new class() {
							public function query( $args ) {
								return array( 'method_used' => 'factory_query', 'args' => $args );
							}
						};
					}
				};
			}
		};
	}

	/**
	 * Test that get_performance_trends uses fallback when handler is null.
	 */
	public function test_get_performance_trends_fallback_when_no_handler() {
		// Mock the get_item_handler to return null.
		add_filter( 'jet_engine/custom-content-types/factory', '__return_null' );
		
		$result = WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends( 'rest_api', '-7 days' );
		
		// Should return trend data structure.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'trend', $result );
		
		remove_filter( 'jet_engine/custom-content-types/factory', '__return_null' );
	}

	/**
	 * Test that chart data method handles missing handler gracefully.
	 */
	public function test_get_chart_data_with_no_handler() {
		// Mock the get_item_handler to return null.
		add_filter( 'jet_engine/custom-content-types/factory', '__return_null' );
		
		$result = WP_MCP_AI_Performance_Reporter::get_chart_data( 'rest_api', 'avg_response_time', '-30 days' );
		
		// Should return empty chart data.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'labels', $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertEmpty( $result['labels'] );
		$this->assertEmpty( $result['data'] );
		
		remove_filter( 'jet_engine/custom-content-types/factory', '__return_null' );
	}
}
