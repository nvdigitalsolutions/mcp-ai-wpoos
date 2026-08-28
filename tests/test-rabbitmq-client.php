<?php
/**
 * Tests for RabbitMQ Client.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test RabbitMQ Client functionality.
 *
 * @group rabbitmq
 */
class Test_RabbitMQ_Client extends WP_UnitTestCase {

	/**
	 * Test get_instance returns singleton.
	 */
	public function test_get_instance_returns_singleton() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		$instance1 = WP_MCP_AI_RabbitMQ_Client::get_instance();
		$instance2 = WP_MCP_AI_RabbitMQ_Client::get_instance();

		$this->assertSame( $instance1, $instance2, 'Should return the same singleton instance.' );
	}

	/**
	 * Test is_available returns false when not enabled.
	 */
	public function test_is_available_returns_false_when_disabled() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		// Ensure rabbitmq is disabled.
		update_option(
			'wp_mcp_ai_settings',
			array_merge(
				get_option( 'wp_mcp_ai_settings', array() ),
				array( 'rabbitmq_enabled' => false )
			)
		);

		$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
		$client->refresh_config();
		$this->assertFalse( $client->is_available(), 'Should return false when RabbitMQ is disabled.' );
	}

	/**
	 * Test health_check returns disabled when not enabled.
	 */
	public function test_health_check_returns_disabled_when_not_enabled() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		update_option(
			'wp_mcp_ai_settings',
			array_merge(
				get_option( 'wp_mcp_ai_settings', array() ),
				array( 'rabbitmq_enabled' => false )
			)
		);

		$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
		$client->refresh_config();
		$status = $client->health_check();

		$this->assertIsArray( $status );
		$this->assertEquals( 'disabled', $status['status'] );
		$this->assertFalse( $status['enabled'] );
	}

	/**
	 * Test health_check returns extension_missing when AMQP not loaded.
	 */
	public function test_health_check_returns_extension_missing_without_amqp() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		if ( extension_loaded( 'amqp' ) ) {
			$this->markTestSkipped( 'AMQP extension is loaded — cannot test extension_missing path.' );
		}

		update_option(
			'wp_mcp_ai_settings',
			array_merge(
				get_option( 'wp_mcp_ai_settings', array() ),
				array( 'rabbitmq_enabled' => true )
			)
		);

		$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
		$client->refresh_config();
		$status = $client->health_check();

		$this->assertIsArray( $status );
		$this->assertEquals( 'extension_missing', $status['status'] );
		$this->assertTrue( $status['enabled'] );
		$this->assertFalse( $status['extension'] );
	}

	/**
	 * Test get_config returns configured values.
	 */
	public function test_get_config_returns_values() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		update_option(
			'wp_mcp_ai_settings',
			array_merge(
				get_option( 'wp_mcp_ai_settings', array() ),
				array(
					'rabbitmq_enabled'      => true,
					'rabbitmq_host'         => 'my-broker.local',
					'rabbitmq_port'         => 5673,
					'rabbitmq_vhost'        => '/test',
					'rabbitmq_queue_prefix' => 'nvoos_test',
				)
			)
		);

		$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
		$client->refresh_config();

		$this->assertEquals( true, $client->get_config( 'enabled' ) );
		$this->assertEquals( 'my-broker.local', $client->get_config( 'host' ) );
		$this->assertEquals( 5673, $client->get_config( 'port' ) );
		$this->assertEquals( '/test', $client->get_config( 'vhost' ) );
		$this->assertEquals( 'nvoos_test', $client->get_config( 'prefix' ) );
	}

	/**
	 * Test get_config returns default for unknown key.
	 */
	public function test_get_config_returns_default_for_unknown_key() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		$client = WP_MCP_AI_RabbitMQ_Client::get_instance();

		$this->assertNull( $client->get_config( 'nonexistent_key' ) );
		$this->assertEquals( 'fallback', $client->get_config( 'nonexistent_key', 'fallback' ) );
	}

	/**
	 * Test get_queue_name prepends prefix.
	 */
	public function test_get_queue_name_prepends_prefix() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		update_option(
			'wp_mcp_ai_settings',
			array_merge(
				get_option( 'wp_mcp_ai_settings', array() ),
				array( 'rabbitmq_queue_prefix' => 'my_prefix' )
			)
		);

		$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
		$client->refresh_config();

		$this->assertEquals( 'my_prefix.tool.execution', $client->get_queue_name( 'tool.execution' ) );
		$this->assertEquals( 'my_prefix.deadletter.queue', $client->get_queue_name( 'deadletter.queue' ) );
	}

	/**
	 * Test queue_tool_execution returns false when unavailable.
	 */
	public function test_queue_tool_execution_returns_false_when_unavailable() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		update_option(
			'wp_mcp_ai_settings',
			array_merge(
				get_option( 'wp_mcp_ai_settings', array() ),
				array( 'rabbitmq_enabled' => false )
			)
		);

		$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
		$client->refresh_config();
		$job_id = $client->queue_tool_execution(
			'test_tool',
			array( 'param' => 'value' ),
			array( 'user_id' => 1 ),
			'normal'
		);

		$this->assertFalse( $job_id, 'Should return false when RabbitMQ is unavailable.' );
	}

	/**
	 * Test get_job_result returns null for unknown job.
	 */
	public function test_get_job_result_returns_null_for_unknown_job() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
		$result = $client->get_job_result( 'nonexistent-job-id-12345' );

		$this->assertNull( $result, 'Should return null for unknown job IDs.' );
	}

	/**
	 * Test exchange definitions are well-formed.
	 */
	public function test_exchange_definitions_have_required_keys() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		$exchanges = WP_MCP_AI_RabbitMQ_Client::EXCHANGES;

		$this->assertIsArray( $exchanges );
		$this->assertNotEmpty( $exchanges );

		$valid_types = array( 'direct', 'topic', 'fanout', 'headers' );

		foreach ( $exchanges as $key => $config ) {
			$this->assertArrayHasKey( 'name', $config, "Exchange '$key' should have a name." );
			$this->assertArrayHasKey( 'type', $config, "Exchange '$key' should have a type." );
			$this->assertContains( $config['type'], $valid_types, "Exchange '$key' type '{$config['type']}' is invalid." );
		}
	}

	/**
	 * Test queue definitions are well-formed.
	 */
	public function test_queue_definitions_have_required_keys() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		$queues = WP_MCP_AI_RabbitMQ_Client::QUEUES;

		$this->assertIsArray( $queues );
		$this->assertNotEmpty( $queues );

		foreach ( $queues as $name => $config ) {
			$this->assertArrayHasKey( 'exchange', $config, "Queue '$name' should reference an exchange." );
			$this->assertArrayHasKey( 'routing_key', $config, "Queue '$name' should have a routing key." );

			// Verify the referenced exchange exists.
			$exchange_key = $config['exchange'];
			$this->assertArrayHasKey(
				$exchange_key,
				WP_MCP_AI_RabbitMQ_Client::EXCHANGES,
				"Queue '$name' references unknown exchange '$exchange_key'."
			);
		}
	}

	/**
	 * Test publish returns false when unavailable.
	 */
	public function test_publish_returns_false_when_unavailable() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		update_option(
			'wp_mcp_ai_settings',
			array_merge(
				get_option( 'wp_mcp_ai_settings', array() ),
				array( 'rabbitmq_enabled' => false )
			)
		);

		$client  = WP_MCP_AI_RabbitMQ_Client::get_instance();
		$client->refresh_config();
		$success = $client->publish( 'tools', 'test.routing', array( 'test' => 'data' ) );

		$this->assertFalse( $success, 'Should return false when RabbitMQ is unavailable.' );
	}

	/**
	 * Test store_job_result creates a retrievable transient.
	 */
	public function test_store_job_result_creates_transient() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		$job_id = 'test-job-' . wp_generate_uuid4();

		$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
		$client->store_job_result( $job_id, array( 'ok' => true ), 'success' );

		$saved = get_transient( 'wp_mcp_ai_job_result_' . $job_id );
		$this->assertNotFalse( $saved, 'Result should be stored in transient.' );
		$this->assertEquals( $job_id, $saved['job_id'] );
		$this->assertEquals( 'success', $saved['status'] );
		$this->assertEquals( array( 'ok' => true ), $saved['result'] );

		// Clean up.
		delete_transient( 'wp_mcp_ai_job_result_' . $job_id );
	}

	/**
	 * Test get_job_result retrieves and cleans up stored result.
	 */
	public function test_get_job_result_retrieves_and_cleans_up() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		$job_id = 'test-job-' . wp_generate_uuid4();

		// Set up both transients.
		set_transient(
			'wp_mcp_ai_job_result_' . $job_id,
			array(
				'job_id' => $job_id,
				'result' => array( 'data' => 'value' ),
				'status' => 'success',
			),
			3600
		);
		set_transient( 'wp_mcp_ai_job_' . $job_id, array( 'tool_name' => 'test' ), 3600 );

		$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
		$result = $client->get_job_result( $job_id );

		$this->assertIsArray( $result );
		$this->assertEquals( $job_id, $result['job_id'] );
		$this->assertEquals( array( 'data' => 'value' ), $result['result'] );

		// Both transients should be cleaned up.
		$this->assertFalse( get_transient( 'wp_mcp_ai_job_result_' . $job_id ) );
		$this->assertFalse( get_transient( 'wp_mcp_ai_job_' . $job_id ) );
	}

	/**
	 * Test get_queue_stats returns unavailable when not connected.
	 */
	public function test_get_queue_stats_returns_unavailable_when_disabled() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_RabbitMQ_Client class not loaded.' );
		}

		update_option(
			'wp_mcp_ai_settings',
			array_merge(
				get_option( 'wp_mcp_ai_settings', array() ),
				array( 'rabbitmq_enabled' => false )
			)
		);

		$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
		$client->refresh_config();
		$stats  = $client->get_queue_stats();

		$this->assertIsArray( $stats );
		$this->assertFalse( $stats['available'] );
		$this->assertArrayHasKey( 'error', $stats );
	}
}
