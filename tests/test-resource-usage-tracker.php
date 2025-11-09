<?php
/**
 * Tests for Resource Usage Tracker.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for resource usage tracking functionality.
 */
class WP_MCP_AI_Resource_Usage_Tracker_Test extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clean up any existing test data.
		delete_option( 'wp_mcp_ai_resource_usage_history' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_resource_usage_history' );

		parent::tearDown();
	}

	/**
	 * Test that usage tracking records assistant_id.
	 */
	public function test_records_assistant_id() {
		$assistant_id = 123;

		// Simulate tracking start.
		apply_filters( 'wp_mcp_ai_before_chat_request', null, $assistant_id );

		// Simulate tracking completion with successful response.
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'content' => 'Test response',
					),
				),
			),
			'usage'   => array(
				'total_tokens' => 150,
			),
		);

		// Mock request object.
		$request = new WP_REST_Request();

		do_action( 'wp_mcp_ai_after_chat_response', $assistant_id, $response, $request );

		// Verify data was recorded.
		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$history          = $resource_manager->get_usage_history( 1 );

		$this->assertNotEmpty( $history );

		$latest_entry = end( $history );
		$this->assertArrayHasKey( 'assistant_id', $latest_entry );
		$this->assertEquals( $assistant_id, $latest_entry['assistant_id'] );
	}

	/**
	 * Test that usage tracking records token usage.
	 */
	public function test_records_token_usage() {
		$assistant_id = 123;

		apply_filters( 'wp_mcp_ai_before_chat_request', null, $assistant_id );

		$response = array(
			'usage' => array(
				'total_tokens' => 250,
			),
		);

		$request = new WP_REST_Request();

		do_action( 'wp_mcp_ai_after_chat_response', $assistant_id, $response, $request );

		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$history          = $resource_manager->get_usage_history( 1 );

		$latest_entry = end( $history );
		$this->assertArrayHasKey( 'tokens_used', $latest_entry );
		$this->assertEquals( 250, $latest_entry['tokens_used'] );
	}

	/**
	 * Test that usage tracking records execution time.
	 */
	public function test_records_execution_time() {
		$assistant_id = 123;

		apply_filters( 'wp_mcp_ai_before_chat_request', null, $assistant_id );

		// Sleep briefly to simulate execution time.
		usleep( 100000 ); // 0.1 seconds.

		$response = array();
		$request  = new WP_REST_Request();

		do_action( 'wp_mcp_ai_after_chat_response', $assistant_id, $response, $request );

		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$history          = $resource_manager->get_usage_history( 1 );

		$latest_entry = end( $history );
		$this->assertArrayHasKey( 'execution_time', $latest_entry );
		$this->assertGreaterThan( 0, $latest_entry['execution_time'] );
	}

	/**
	 * Test that usage tracking detects errors.
	 */
	public function test_records_error_status() {
		$assistant_id = 123;

		apply_filters( 'wp_mcp_ai_before_chat_request', null, $assistant_id );

		// Simulate error response.
		$response = new WP_Error( 'test_error', 'Test error message' );
		$request  = new WP_REST_Request();

		do_action( 'wp_mcp_ai_after_chat_response', $assistant_id, $response, $request );

		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$history          = $resource_manager->get_usage_history( 1 );

		$latest_entry = end( $history );
		$this->assertArrayHasKey( 'status', $latest_entry );
		$this->assertEquals( 'error', $latest_entry['status'] );
	}

	/**
	 * Test that usage tracking records success status.
	 */
	public function test_records_success_status() {
		$assistant_id = 123;

		apply_filters( 'wp_mcp_ai_before_chat_request', null, $assistant_id );

		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'content' => 'Success',
					),
				),
			),
		);
		$request  = new WP_REST_Request();

		do_action( 'wp_mcp_ai_after_chat_response', $assistant_id, $response, $request );

		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$history          = $resource_manager->get_usage_history( 1 );

		$latest_entry = end( $history );
		$this->assertArrayHasKey( 'status', $latest_entry );
		$this->assertEquals( 'success', $latest_entry['status'] );
	}

	/**
	 * Test that usage tracking records operation type.
	 */
	public function test_records_operation_type() {
		$assistant_id = 123;

		apply_filters( 'wp_mcp_ai_before_chat_request', null, $assistant_id );

		$response = array();
		$request  = new WP_REST_Request();

		do_action( 'wp_mcp_ai_after_chat_response', $assistant_id, $response, $request );

		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$history          = $resource_manager->get_usage_history( 1 );

		$latest_entry = end( $history );
		$this->assertArrayHasKey( 'operation_type', $latest_entry );
		$this->assertEquals( 'chat', $latest_entry['operation_type'] );
	}

	/**
	 * Test that multiple operations are tracked separately.
	 */
	public function test_tracks_multiple_operations() {
		$request = new WP_REST_Request();

		// First operation.
		apply_filters( 'wp_mcp_ai_before_chat_request', null, 1 );
		do_action( 'wp_mcp_ai_after_chat_response', 1, array( 'usage' => array( 'total_tokens' => 100 ) ), $request );

		// Second operation.
		apply_filters( 'wp_mcp_ai_before_chat_request', null, 2 );
		do_action( 'wp_mcp_ai_after_chat_response', 2, array( 'usage' => array( 'total_tokens' => 200 ) ), $request );

		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$history          = $resource_manager->get_usage_history( 24 );

		$this->assertCount( 2, $history );

		$history_values = array_values( $history );
		$this->assertEquals( 1, $history_values[0]['assistant_id'] );
		$this->assertEquals( 100, $history_values[0]['tokens_used'] );
		$this->assertEquals( 2, $history_values[1]['assistant_id'] );
		$this->assertEquals( 200, $history_values[1]['tokens_used'] );
	}
}
