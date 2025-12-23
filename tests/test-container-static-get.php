<?php
/**
 * Tests for WP_MCP_AI_Container instance access
 *
 * @package WP_MCP_AI
 */

/**
 * Test container instance get functionality.
 */
class Test_Container_Static_Get extends WP_UnitTestCase {

	/**
	 * Test that the instance get method works correctly.
	 */
	public function test_get_instance_retrieves_service() {
		// The container should be auto-initialized with default services.
		// Test retrieving a known service using the instance method.
		$client = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );

		$this->assertInstanceOf(
			'WP_MCP_AI_Huggingface_Datasets_Client',
			$client,
			'Instance get method should retrieve the HuggingFace Datasets client'
		);
	}

	/**
	 * Test that the instance get method returns the same instance (singleton behavior).
	 */
	public function test_get_instance_returns_singleton() {
		$client1 = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );
		$client2 = WP_MCP_AI_Container::get_instance()->get( 'client.huggingface_datasets' );

		$this->assertSame(
			$client1,
			$client2,
			'Instance get method should return the same singleton instance'
		);
	}

	/**
	 * Test that the instance get method throws exception for unknown service.
	 */
	public function test_get_instance_throws_exception_for_unknown_service() {
		$this->expectException( 'Exception' );
		$this->expectExceptionMessageMatches( '/Service ".*" not found in container/' );

		WP_MCP_AI_Container::get_instance()->get( 'nonexistent.service' );
	}

	/**
	 * Test that get_instance returns the same container.
	 */
	public function test_get_instance_returns_same_container() {
		$container1 = WP_MCP_AI_Container::get_instance();
		$container2 = WP_MCP_AI_Container::get_instance();

		$this->assertSame(
			$container1,
			$container2,
			'get_instance should return the same container instance'
		);
	}
}
