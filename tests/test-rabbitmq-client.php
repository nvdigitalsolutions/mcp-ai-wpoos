<?php
/**
 * Tests for RabbitMQ Client.
 *
 * @package WP_MCP_AI
 */

/**
 * Test RabbitMQ client functionality.
 *
 * @group rabbitmq
 */
class Test_RabbitMQ_Client extends WP_UnitTestCase {

	/**
	 * Test client returns singleton instance.
	 */
	public function test_get_instance_returns_singleton() {
		// Skip if class doesn't exist.
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		$instance1 = WP_MCP_AI_RabbitMQ_Client::get_instance();
		$instance2 = WP_MCP_AI_RabbitMQ_Client::get_instance();

		$this->assertSame( $instance1, $instance2, 'Should return the same singleton instance.' );
	}

	/**
	 * Test client reports unavailable when AMQP extension not loaded.
	 */
	public function test_is_available_without_amqp_extension() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		// If AMQP is not loaded, should return false.
		if ( ! extension_loaded( 'amqp' ) ) {
			$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
			$this->assertFalse( $client->is_available(), 'Should return false when AMQP extension not loaded.' );
		} else {
			$this->markTestSkipped( 'AMQP extension is loaded, cannot test unavailable state.' );
		}
	}

	/**
	 * Test exchanges constant has correct structure.
	 */
	public function test_exchanges_constant_structure() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		$exchanges = WP_MCP_AI_RabbitMQ_Client::EXCHANGES;

		$this->assertIsArray( $exchanges, 'EXCHANGES should be an array.' );
		$this->assertArrayHasKey( 'tools', $exchanges, 'Should have tools exchange.' );
		$this->assertArrayHasKey( 'chat', $exchanges, 'Should have chat exchange.' );
		$this->assertArrayHasKey( 'deadletter', $exchanges, 'Should have deadletter exchange.' );
		$this->assertArrayHasKey( 'analytics', $exchanges, 'Should have analytics exchange.' );

		// Verify structure of an exchange.
		$this->assertArrayHasKey( 'name', $exchanges['tools'], 'Exchange should have name.' );
		$this->assertArrayHasKey( 'type', $exchanges['tools'], 'Exchange should have type.' );
		$this->assertArrayHasKey( 'durable', $exchanges['tools'], 'Exchange should have durable flag.' );
	}

	/**
	 * Test queues constant has correct structure.
	 */
	public function test_queues_constant_structure() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		$queues = WP_MCP_AI_RabbitMQ_Client::QUEUES;

		$this->assertIsArray( $queues, 'QUEUES should be an array.' );
		$this->assertArrayHasKey( 'tool.execution', $queues, 'Should have tool.execution queue.' );
		$this->assertArrayHasKey( 'tool.execution.priority.high', $queues, 'Should have high priority queue.' );
		$this->assertArrayHasKey( 'tool.execution.async', $queues, 'Should have async queue.' );
		$this->assertArrayHasKey( 'deadletter.queue', $queues, 'Should have deadletter queue.' );

		// Verify structure of a queue.
		$this->assertArrayHasKey( 'exchange', $queues['tool.execution'], 'Queue should have exchange reference.' );
		$this->assertArrayHasKey( 'routing_key', $queues['tool.execution'], 'Queue should have routing_key.' );
		$this->assertArrayHasKey( 'durable', $queues['tool.execution'], 'Queue should have durable flag.' );
	}

	/**
	 * Test health check returns proper structure when disabled.
	 */
	public function test_health_check_structure() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
		$health = $client->health_check();

		$this->assertIsArray( $health, 'Health check should return array.' );
		$this->assertArrayHasKey( 'status', $health, 'Health should have status.' );
		$this->assertArrayHasKey( 'connection', $health, 'Health should have connection info.' );
		$this->assertArrayHasKey( 'extension', $health, 'Health should have extension flag.' );
		$this->assertArrayHasKey( 'enabled', $health, 'Health should have enabled flag.' );
	}
}
