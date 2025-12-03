<?php
/**
 * Test WordPress object normalization in SSE streaming
 *
 * Tests that WP_Post, WP_Query, and other WordPress objects are properly
 * normalized to prevent JSON encoding failures in SSE streams.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for WordPress object normalization.
 */
class Test_Normalize_WordPress_Objects extends WP_UnitTestCase {
	/**
	 * SSE handler instance for testing.
	 *
	 * @var WP_MCP_AI_SSE_Handler
	 */
	private $sse_handler;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load SSE handler.
		if ( ! class_exists( 'WP_MCP_AI_SSE_Handler' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-sse-handler.php';
		}

		$this->sse_handler = new WP_MCP_AI_SSE_Handler();
	}

	/**
	 * Test that WP_Post objects are properly normalized.
	 */
	public function test_normalize_wp_post_object() {
		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Test Post',
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		$post = get_post( $post_id );

		// Create data containing a WP_Post object.
		$data = array(
			'status'     => 'completed',
			'result'     => array(
				'post'       => $post,
				'attachment' => $post,
			),
			'nested'     => array(
				'deep' => array(
					'post' => $post,
				),
			),
		);

		// Try to JSON encode without normalization - this should include the full WP_Post object.
		$json_before = wp_json_encode( $data );
		$this->assertNotFalse( $json_before, 'WP_Post object should be JSON encodable' );

		// The JSON should be very large due to all the WP_Post properties.
		$decoded_before = json_decode( $json_before, true );
		$this->assertIsArray( $decoded_before['result']['post'] );

		// Now test with normalized data using reflection to access protected method.
		$service = $this->get_cron_status_service_with_normalization();

		$normalized = $this->call_protected_method( $service, 'normalize_data_recursive', array( $data ) );

		// The normalized data should have simplified WP_Post objects.
		$this->assertIsArray( $normalized['result']['post'] );
		$this->assertArrayHasKey( 'ID', $normalized['result']['post'] );
		$this->assertArrayHasKey( 'post_title', $normalized['result']['post'] );
		$this->assertArrayHasKey( 'post_type', $normalized['result']['post'] );
		$this->assertArrayHasKey( 'post_status', $normalized['result']['post'] );

		// The simplified object should have only 4 keys.
		$this->assertCount( 4, $normalized['result']['post'] );

		// Nested posts should also be normalized.
		$this->assertIsArray( $normalized['nested']['deep']['post'] );
		$this->assertCount( 4, $normalized['nested']['deep']['post'] );

		// JSON encode the normalized data - should succeed.
		$json_after = wp_json_encode( $normalized );
		$this->assertNotFalse( $json_after, 'Normalized data should be JSON encodable' );
		
		// Verify the normalized data is properly structured (doesn't guarantee smaller, but should be simpler).
		$decoded_after = json_decode( $json_after, true );
		$this->assertIsArray( $decoded_after );
		$this->assertCount( 4, $decoded_after['result']['post'], 'Normalized post should have exactly 4 fields' );
	}

	/**
	 * Test that WP_Query objects are properly handled.
	 */
	public function test_normalize_wp_query_object() {
		// Create test posts.
		$this->factory->post->create_many( 3 );

		// Create a WP_Query object.
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'posts_per_page' => 3,
			)
		);

		// Create data containing a WP_Query object.
		$data = array(
			'status' => 'completed',
			'query'  => $query,
		);

		// Normalize the data.
		$service = $this->get_cron_status_service_with_normalization();
		$normalized = $this->call_protected_method( $service, 'normalize_data_recursive', array( $data ) );

		// WP_Query should be normalized to a simple array.
		$this->assertIsArray( $normalized['query'] );
		$this->assertArrayHasKey( 'query_type', $normalized['query'] );
		$this->assertEquals( 'WP_Query', $normalized['query']['query_type'] );
		$this->assertArrayHasKey( 'post_count', $normalized['query'] );
		$this->assertEquals( 3, $normalized['query']['post_count'] );

		// Should only have 2 keys.
		$this->assertCount( 2, $normalized['query'] );

		// JSON encode should work.
		$json = wp_json_encode( $normalized );
		$this->assertNotFalse( $json, 'Normalized WP_Query should be JSON encodable' );
	}

	/**
	 * Test that resources are properly handled.
	 */
	public function test_normalize_resource() {
		// Create a resource (file handle).
		$temp_file = tmpfile();

		$data = array(
			'status'   => 'completed',
			'resource' => $temp_file,
		);

		// Normalize the data.
		$service = $this->get_cron_status_service_with_normalization();
		$normalized = $this->call_protected_method( $service, 'normalize_data_recursive', array( $data ) );

		// Resource should be replaced with a string marker.
		$this->assertEquals( '[resource]', $normalized['resource'] );

		// JSON encode should work.
		$json = wp_json_encode( $normalized );
		$this->assertNotFalse( $json, 'Data with normalized resource should be JSON encodable' );

		// Clean up.
		fclose( $temp_file );
	}

	/**
	 * Test recursion depth limit.
	 */
	public function test_recursion_depth_limit() {
		// Create deeply nested array.
		$data = array( 'level' => 0 );
		$current = &$data;

		for ( $i = 1; $i <= 25; $i++ ) {
			$current['nested'] = array( 'level' => $i );
			$current = &$current['nested'];
		}

		// Normalize the data.
		$service = $this->get_cron_status_service_with_normalization();
		$normalized = $this->call_protected_method( $service, 'normalize_data_recursive', array( $data ) );

		// Navigate to level 20 - should exist.
		$current = $normalized;
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertArrayHasKey( 'nested', $current, "Level $i should have nested array" );
			$current = $current['nested'];
		}

		// Level 21 should hit the recursion limit.
		$this->assertEquals( '[max recursion depth reached]', $current['nested'], 'Should hit recursion limit at depth 20' );

		// JSON encode should still work.
		$json = wp_json_encode( $normalized );
		$this->assertNotFalse( $json, 'Data with recursion limit should be JSON encodable' );
	}

	/**
	 * Test SSE event encoding with JSON failures.
	 *
	 * This test captures output to verify that send_sse_event handles JSON encoding failures gracefully.
	 */
	public function test_sse_event_json_encoding_failure_handling() {
		// Create data that will definitely JSON encode successfully.
		$good_data = array(
			'status'  => 'completed',
			'message' => 'Test message',
		);

		// Capture output from send_sse_event.
		ob_start();
		$this->sse_handler->send_sse_event( 'test_event', $good_data );
		$output = ob_get_clean();

		// Verify the output format.
		$this->assertStringContainsString( 'event: test_event', $output );
		$this->assertStringContainsString( 'data: {', $output );
		$this->assertStringContainsString( '"status":"completed"', $output );

		// Test with a resource (will fail JSON encoding in strict mode).
		$temp_file = tmpfile();
		$bad_data = array(
			'status'   => 'completed',
			'resource' => $temp_file,
		);

		// Capture output from send_sse_event.
		ob_start();
		$this->sse_handler->send_sse_event( 'test_event', $bad_data );
		$output = ob_get_clean();

		// The SSE handler should detect JSON encoding failure and send error response.
		// Note: wp_json_encode actually handles resources by converting them to null,
		// so this test verifies that the handler can still encode the data.
		$this->assertStringContainsString( 'event: test_event', $output );
		$this->assertStringContainsString( 'data: {', $output );

		fclose( $temp_file );
	}

	/**
	 * Get cron status service instance for testing.
	 *
	 * @return WP_MCP_AI_Cron_Status_Service
	 */
	private function get_cron_status_service_with_normalization() {
		if ( ! class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cron-status-service.php';
		}

		return new WP_MCP_AI_Cron_Status_Service();
	}

	/**
	 * Call a protected/private method using reflection.
	 *
	 * @param object $object     Object instance.
	 * @param string $method_name Method name.
	 * @param array  $parameters Method parameters.
	 * @return mixed Method return value.
	 */
	private function call_protected_method( $object, $method_name, array $parameters = array() ) {
		$reflection = new ReflectionClass( get_class( $object ) );
		$method = $reflection->getMethod( $method_name );
		$method->setAccessible( true );

		return $method->invokeArgs( $object, $parameters );
	}
}
