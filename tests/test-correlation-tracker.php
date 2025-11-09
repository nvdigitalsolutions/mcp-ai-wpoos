<?php
/**
 * Tests for Correlation Tracker.
 *
 * @package WP_MCP_AI
 */

/**
 * Correlation Tracker test case.
 */
class Test_Correlation_Tracker extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Correlation_Tracker::reset();
	}

	/**
	 * Test correlation ID generation.
	 */
	public function test_generate_correlation_id() {
		$id1 = WP_MCP_AI_Correlation_Tracker::generate_correlation_id();
		$id2 = WP_MCP_AI_Correlation_Tracker::generate_correlation_id();

		$this->assertIsString( $id1 );
		$this->assertIsString( $id2 );
		$this->assertNotEquals( $id1, $id2 );
		$this->assertStringContainsString( 'wpmcp-', $id1 );
	}

	/**
	 * Test correlation ID validation.
	 */
	public function test_validate_correlation_id() {
		$valid_id = 'wpmcp-12345-abc123-xyz';
		$this->assertTrue( WP_MCP_AI_Correlation_Tracker::is_valid_correlation_id( $valid_id ) );

		// Invalid: too long.
		$invalid_id = str_repeat( 'a', 200 );
		$this->assertFalse( WP_MCP_AI_Correlation_Tracker::is_valid_correlation_id( $invalid_id ) );

		// Invalid: special characters.
		$this->assertFalse( WP_MCP_AI_Correlation_Tracker::is_valid_correlation_id( 'test@#$%' ) );
	}

	/**
	 * Test getting correlation ID for request.
	 */
	public function test_get_correlation_id() {
		$id1 = WP_MCP_AI_Correlation_Tracker::get_correlation_id();
		$id2 = WP_MCP_AI_Correlation_Tracker::get_correlation_id();

		// Should return same ID for same request.
		$this->assertEquals( $id1, $id2 );
	}

	/**
	 * Test forcing new correlation ID.
	 */
	public function test_force_new_correlation_id() {
		$id1 = WP_MCP_AI_Correlation_Tracker::get_correlation_id();
		$id2 = WP_MCP_AI_Correlation_Tracker::get_correlation_id( true );

		$this->assertNotEquals( $id1, $id2 );
	}

	/**
	 * Test child correlation ID creation.
	 */
	public function test_create_child_correlation_id() {
		$parent = 'wpmcp-12345-abc';
		$child = WP_MCP_AI_Correlation_Tracker::create_child_correlation_id( $parent, 'task1' );

		$this->assertStringContainsString( $parent, $child );
		$this->assertStringContainsString( 'task1', $child );
	}

	/**
	 * Test storing correlation ID for entity.
	 */
	public function test_store_entity_correlation_id() {
		$post_id = $this->factory->post->create();
		$correlation_id = 'test-correlation-123';

		$result = WP_MCP_AI_Correlation_Tracker::store_correlation_id( 'post', $post_id, $correlation_id );
		$this->assertTrue( $result );

		$retrieved = WP_MCP_AI_Correlation_Tracker::get_entity_correlation_id( 'post', $post_id );
		$this->assertEquals( $correlation_id, $retrieved );
	}

	/**
	 * Test adding correlation to REST response.
	 */
	public function test_add_correlation_header() {
		$response = new WP_REST_Response( array( 'test' => 'data' ) );
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$server = rest_get_server();

		$result = WP_MCP_AI_Correlation_Tracker::add_correlation_header( false, $response, $request, $server );

		// Should return false (not served yet).
		$this->assertFalse( $result );

		// Check header was added.
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'X-Correlation-ID', $headers );

		// Check data was modified.
		$data = $response->get_data();
		$this->assertArrayHasKey( 'correlation_id', $data );
	}
}
