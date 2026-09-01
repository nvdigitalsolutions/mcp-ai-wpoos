<?php
/**
 * RabbitMQ client + STDIO transport port tests (Wave D2d/D2e).
 *
 * Characterization suite for `NvoosContentGraphAi\Provider\RabbitMqClient`
 * and `NvoosContentGraphAi\Provider\StdioTransport`. Assertions mirror the
 * base plugin's tests: config loading (constants > settings > defaults),
 * queue-name prefixing, availability gating, publish/job-transient
 * behaviour without the `amqp` extension, health-check shapes, and the
 * JSON-RPC 2.0 / MCP message surface of the STDIO transport.
 *
 * Matrix note: the RabbitMQ client reads the base settings option in
 * monolith runs and the content-graph settings option standalone; tests
 * seed the store appropriate to the active matrix.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Provider\RabbitMqClient;
use NvoosContentGraphAi\Provider\StdioTransport;

/**
 * Test double exposing protected STDIO message handlers.
 */
class Testable_Stdio_Transport extends StdioTransport {

	public function expose_process_message( $line ) {
		return $this->process_message( $line );
	}

	public function expose_route_method( $method, $params ) {
		return $this->route_method( $method, $params );
	}

	public function expose_initialize( $params ) {
		return $this->handle_initialize( $params );
	}

	public function expose_tools_list( $params ) {
		return $this->handle_tools_list( $params );
	}

	public function expose_tools_call( $params ) {
		return $this->handle_tools_call( $params );
	}

	public function expose_convert_to_text( $value ) {
		return $this->convert_to_text( $value );
	}

	public function expose_error_response( $id, $code, $message ) {
		return $this->error_response( $id, $code, $message );
	}
}

/**
 * @group provider
 */
class Test_RabbitMq_Stdio extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\delete_option( 'nvoos_content_graph_settings' );
		\delete_option( 'wp_mcp_ai_settings' );
	}

	public function tearDown(): void {
		\delete_option( 'nvoos_content_graph_settings' );
		\delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	/**
	 * Seed the active settings option (matrix-aware).
	 *
	 * @param array $settings Settings values.
	 */
	private function seed_settings( array $settings ): void {
		$option = defined( 'WP_MCP_AI_PATH' ) ? 'wp_mcp_ai_settings' : 'nvoos_content_graph_settings';
		\update_option( $option, $settings );
	}

	// ─── RabbitMQ client ────────────────────────────────────────────

	public function test_rabbitmq_default_config_and_queue_names(): void {
		$client = RabbitMqClient::get_instance();
		$client->refresh_config();

		$this->assertFalse( $client->get_config( 'enabled' ) );
		$this->assertSame( 'localhost', $client->get_config( 'host' ) );
		$this->assertSame( 5672, $client->get_config( 'port' ) );
		$this->assertSame( 'guest', $client->get_config( 'username' ) );
		$this->assertSame( 'guest', $client->get_config( 'password' ) );
		$this->assertSame( '/', $client->get_config( 'vhost' ) );
		$this->assertSame( 'wp_mcp_ai', $client->get_config( 'prefix' ) );
		$this->assertSame( 'wp_mcp_ai.tool.execution', $client->get_queue_name( 'tool.execution' ) );
		$this->assertSame( 'custom-fallback', $client->get_config( 'nonexistent', 'custom-fallback' ) );
	}

	public function test_rabbitmq_settings_override_config(): void {
		$this->seed_settings(
			array(
				'rabbitmq_enabled'       => true,
				'rabbitmq_host'          => 'mq.example.com',
				'rabbitmq_port'          => '5673',
				'rabbitmq_username'      => 'svc',
				'rabbitmq_password'      => 'secret',
				'rabbitmq_vhost'         => '/prod',
				'rabbitmq_queue_prefix'  => 'custom',
			)
		);

		$client = RabbitMqClient::get_instance();
		$client->refresh_config();

		$this->assertTrue( $client->get_config( 'enabled' ) );
		$this->assertSame( 'mq.example.com', $client->get_config( 'host' ) );
		$this->assertSame( 5673, $client->get_config( 'port' ) );
		$this->assertSame( 'svc', $client->get_config( 'username' ) );
		$this->assertSame( '/prod', $client->get_config( 'vhost' ) );
		$this->assertSame( 'custom.tool.execution', $client->get_queue_name( 'tool.execution' ) );
	}

	public function test_rabbitmq_availability_gates_on_extension_and_config(): void {
		$client = RabbitMqClient::get_instance();
		$client->refresh_config();

		// Disabled by default.
		$this->assertFalse( $client->is_available() );

		// Enabled but no amqp extension in this environment.
		$this->seed_settings( array( 'rabbitmq_enabled' => true ) );
		$client->refresh_config();
		$this->assertFalse( $client->is_available() );

		// Result is cached until the next refresh.
		$this->assertFalse( $client->is_available() );
	}

	public function test_rabbitmq_publish_and_queue_execution_unavailable(): void {
		$client = RabbitMqClient::get_instance();
		$client->refresh_config();

		$this->assertFalse( $client->publish( 'tools', 'execute.normal', array( 'job' => 1 ) ) );
		$this->assertFalse(
			$client->queue_tool_execution( 'test_tool', array( 'a' => 1 ), array( 'user_id' => 0 ), 'high' )
		);
	}

	public function test_rabbitmq_job_result_transient_roundtrip(): void {
		$client = RabbitMqClient::get_instance();
		$client->refresh_config();

		$client->store_job_result( 'job-abc', array( 'ok' => true ), 'success' );

		$result = $client->get_job_result( 'job-abc' );
		$this->assertIsArray( $result );
		$this->assertSame( 'job-abc', $result['job_id'] );
		$this->assertSame( array( 'ok' => true ), $result['result'] );
		$this->assertSame( 'success', $result['status'] );

		// Consumed — second read is null.
		$this->assertNull( $client->get_job_result( 'job-abc' ) );
	}

	public function test_rabbitmq_queue_stats_and_health_unavailable_shapes(): void {
		$client = RabbitMqClient::get_instance();
		$client->refresh_config();

		$stats = $client->get_queue_stats();
		$this->assertSame(
			array(
				'available' => false,
				'error'     => 'RabbitMQ not available',
			),
			$stats
		);

		// Disabled.
		$health = $client->health_check();
		$this->assertSame( 'disabled', $health['status'] );
		$this->assertFalse( $health['extension'] );
		$this->assertFalse( $health['enabled'] );

		// Enabled but extension missing.
		$this->seed_settings( array( 'rabbitmq_enabled' => true ) );
		$client->refresh_config();
		$health = $client->health_check();
		$this->assertSame( 'extension_missing', $health['status'] );
		$this->assertSame( 'localhost', $health['connection']['host'] );
		$this->assertSame( 5672, $health['connection']['port'] );
	}

	public function test_rabbitmq_topology_constants(): void {
		$this->assertSame( 'wp_mcp_ai.tools', RabbitMqClient::EXCHANGES['tools']['name'] );
		$this->assertSame( 'topic', RabbitMqClient::EXCHANGES['chat']['type'] );
		$this->assertSame( 'fanout', RabbitMqClient::EXCHANGES['deadletter']['type'] );
		$this->assertSame( 'execute.high', RabbitMqClient::QUEUES['tool.execution.priority.high']['routing_key'] );
		$this->assertSame( 30000, RabbitMqClient::QUEUES['tool.execution.priority.high']['arguments']['x-message-ttl'] );
		$this->assertSame( 10, RabbitMqClient::QUEUES['tool.execution.priority.high']['arguments']['x-max-priority'] );
		$this->assertSame( 'workflow.#', RabbitMqClient::QUEUES['agentic.workflow']['routing_key'] );
		$this->assertSame( 86400000, RabbitMqClient::QUEUES['deadletter.queue']['arguments']['x-message-ttl'] );
	}

	// ─── STDIO transport ────────────────────────────────────────────

	public function test_stdio_constants(): void {
		$this->assertSame( 1048576, StdioTransport::MAX_LINE_LENGTH );
		$this->assertSame( '2026-07-28', StdioTransport::PROTOCOL_VERSION );
	}

	public function test_stdio_parse_error(): void {
		$transport = new Testable_Stdio_Transport();

		$response = $transport->expose_process_message( 'not json{' );

		$this->assertSame( -32700, $response['error']['code'] );
		$this->assertSame( 'Parse error: Invalid JSON', $response['error']['message'] );
	}

	public function test_stdio_invalid_request_shapes(): void {
		$transport = new Testable_Stdio_Transport();

		// Missing jsonrpc field.
		$response = $transport->expose_process_message( \wp_json_encode( array( 'id' => 1, 'method' => 'x' ) ) );
		$this->assertSame( -32600, $response['error']['code'] );
		$this->assertSame( 1, $response['id'] );

		// Missing method field.
		$response = $transport->expose_process_message( \wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 2 ) ) );
		$this->assertSame( -32600, $response['error']['code'] );

		// Unknown method.
		$response = $transport->expose_process_message( \wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'bogus/method' ) ) );
		$this->assertSame( -32601, $response['error']['code'] );
		$this->assertSame( 3, $response['id'] );
	}

	public function test_stdio_notification_returns_null(): void {
		$transport = new Testable_Stdio_Transport();

		$response = $transport->expose_process_message( \wp_json_encode( array( 'jsonrpc' => '2.0', 'method' => 'shutdown' ) ) );

		$this->assertNull( $response );
	}

	public function test_stdio_initialize_shape(): void {
		$transport = new Testable_Stdio_Transport();

		$result = $transport->expose_initialize( array() );

		$this->assertSame( '2026-07-28', $result['protocolVersion'] );
		$this->assertTrue( $result['capabilities']['tools']['listChanged'] );
		$this->assertFalse( $result['capabilities']['resources']['subscribe'] );
		$this->assertSame( 'NV oOS', $result['serverInfo']['name'] );
		$this->assertNotEmpty( $result['serverInfo']['version'] );
		$this->assertNotEmpty( $result['instructions'] );
		$this->assertArrayHasKey( 'tools', $result );
	}

	public function test_stdio_tools_list_populated(): void {
		$transport = new Testable_Stdio_Transport();

		$result = $transport->expose_tools_list( array() );

		$this->assertArrayHasKey( 'tools', $result );
		$this->assertNotEmpty( $result['tools'] );

		$first = $result['tools'][0];
		$this->assertArrayHasKey( 'name', $first );
		$this->assertArrayHasKey( 'description', $first );
		$this->assertArrayHasKey( 'inputSchema', $first );
	}

	public function test_stdio_tools_call_errors(): void {
		$transport = new Testable_Stdio_Transport();

		$result = $transport->expose_tools_call( array( 'arguments' => array() ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_invalid_params', $result->get_error_code() );

		$result = $transport->expose_tools_call( array( 'name' => 'nonexistent_tool_xyz', 'arguments' => array() ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_tool_not_found', $result->get_error_code() );
	}

	public function test_stdio_shutdown_route(): void {
		$transport = new Testable_Stdio_Transport();

		$result = $transport->expose_route_method( 'shutdown', array() );
		$this->assertSame( array( 'shutdown' => true ), $result );

		$transport->stop();
	}

	public function test_stdio_convert_to_text(): void {
		$transport = new Testable_Stdio_Transport();

		$this->assertSame( 'plain text', $transport->expose_convert_to_text( 'plain text' ) );
		$this->assertSame( '42', $transport->expose_convert_to_text( 42 ) );
		$this->assertSame( 'true', $transport->expose_convert_to_text( true ) );
		$this->assertSame( 'null', $transport->expose_convert_to_text( null ) );

		$json = $transport->expose_convert_to_text( array( 'a' => 1, 'b' => array( 'x' => 'y' ) ) );
		$this->assertStringContainsString( '"a": 1', $json );
		$this->assertStringContainsString( "\n", $json ); // Pretty-printed.

		// Non-finite floats fail JSON encoding.
		$this->assertSame(
			'[Unable to serialize scalar value]',
			$transport->expose_convert_to_text( NAN )
		);
	}

	public function test_stdio_error_response_shape(): void {
		$transport = new Testable_Stdio_Transport();

		$response = $transport->expose_error_response( 7, -32000, 'Boom' );

		$this->assertSame(
			array(
				'jsonrpc' => '2.0',
				'id'      => 7,
				'error'   => array(
					'code'    => -32000,
					'message' => 'Boom',
				),
			),
			$response
		);
	}
}
