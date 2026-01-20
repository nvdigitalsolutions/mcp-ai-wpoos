<?php
/**
 * Tests for Cron Status endpoint with unified team IDs
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Cron_Status_Unified_Team_ID
 */
class Test_Cron_Status_Unified_Team_ID extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test user.
		$this->user_id = $this->factory->user->create();
	}

	/**
	 * Test endpoint accepts string-based unified team IDs.
	 */
	public function test_cron_status_accepts_unified_team_id() {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );
		$request->set_param( 'assistant_id', 'unified_team_8901' );
		$request->set_param( 'limit', 10 );

		$response = rest_do_request( $request );

		// Should not return 500 error.
		$this->assertNotEquals( 500, $response->get_status() );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'jobs', $data );
		$this->assertArrayHasKey( 'counts', $data );

		// Should include assistant_id in response when filtered.
		if ( ! empty( $data['assistant_id'] ) ) {
			$this->assertEquals( 'unified_team_8901', $data['assistant_id'] );
		}
	}

	/**
	 * Test endpoint accepts numeric assistant IDs.
	 */
	public function test_cron_status_accepts_numeric_assistant_id() {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );
		$request->set_param( 'assistant_id', 8901 );
		$request->set_param( 'limit', 10 );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'jobs', $data );
		$this->assertArrayHasKey( 'counts', $data );
	}

	/**
	 * Test endpoint accepts profession test IDs.
	 */
	public function test_cron_status_accepts_profession_id() {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );
		$request->set_param( 'assistant_id', 'profession_123' );
		$request->set_param( 'limit', 10 );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'jobs', $data );
		$this->assertArrayHasKey( 'counts', $data );
	}

	/**
	 * Test sanitize_assistant_id helper method in Tools Controller.
	 */
	public function test_sanitize_assistant_id_method() {
		// Test integer input.
		$result = WP_MCP_AI_REST_Tools_Controller::sanitize_assistant_id( 8901 );
		$this->assertSame( 8901, $result );

		// Test numeric string input.
		$result = WP_MCP_AI_REST_Tools_Controller::sanitize_assistant_id( '8901' );
		$this->assertSame( 8901, $result );

		// Test unified team ID.
		$result = WP_MCP_AI_REST_Tools_Controller::sanitize_assistant_id( 'unified_team_8901' );
		$this->assertSame( 'unified_team_8901', $result );

		// Test profession ID.
		$result = WP_MCP_AI_REST_Tools_Controller::sanitize_assistant_id( 'profession_123' );
		$this->assertSame( 'profession_123', $result );

		// Test empty input.
		$result = WP_MCP_AI_REST_Tools_Controller::sanitize_assistant_id( '' );
		$this->assertNull( $result );

		// Test null input.
		$result = WP_MCP_AI_REST_Tools_Controller::sanitize_assistant_id( null );
		$this->assertNull( $result );
	}

	/**
	 * Test POST request with unified team ID.
	 */
	public function test_cron_status_post_with_unified_team_id() {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/cron-status' );
		$request->set_param( 'assistant_id', 'unified_team_8901' );
		$request->set_param( 'limit', 10 );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'jobs', $data );
		$this->assertArrayHasKey( 'counts', $data );
	}

	/**
	 * Test that malicious input is sanitized.
	 */
	public function test_sanitize_assistant_id_blocks_malicious_input() {
		// Test path traversal attempt.
		$result = WP_MCP_AI_REST_Tools_Controller::sanitize_assistant_id( '../../../etc/passwd' );
		$this->assertNotContains( '/', $result );

		// Test script tag.
		$result = WP_MCP_AI_REST_Tools_Controller::sanitize_assistant_id( '<script>alert("xss")</script>' );
		$this->assertNotContains( '<', $result );
		$this->assertNotContains( '>', $result );
	}
}
