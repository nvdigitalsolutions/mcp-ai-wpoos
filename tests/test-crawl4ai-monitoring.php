<?php
/**
 * Test Crawl4AI v0.7.7 monitoring endpoints.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Crawl4AI monitoring API.
 */
class Test_Crawl4AI_Monitoring extends WP_UnitTestCase {
	/**
	 * Test monitor endpoint returns expected structure.
	 */
	public function test_monitor_endpoint_structure() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/crawl4ai/monitor' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'crawl_jobs', $data );
		$this->assertArrayHasKey( 'cache', $data );
		$this->assertArrayHasKey( 'browser_pool', $data );
		$this->assertArrayHasKey( 'system', $data );
		$this->assertArrayHasKey( 'version', $data );
		$this->assertArrayHasKey( 'timestamp', $data );

		// Check crawl_jobs structure.
		$this->assertArrayHasKey( 'active', $data['crawl_jobs'] );
		$this->assertArrayHasKey( 'queued', $data['crawl_jobs'] );
		$this->assertArrayHasKey( 'running', $data['crawl_jobs'] );
		$this->assertArrayHasKey( 'completed', $data['crawl_jobs'] );
		$this->assertArrayHasKey( 'failed', $data['crawl_jobs'] );

		// Check cache structure.
		$this->assertArrayHasKey( 'total_tasks', $data['cache'] );
		$this->assertArrayHasKey( 'size_mb', $data['cache'] );
		$this->assertArrayHasKey( 'urls_cached', $data['cache'] );

		// Check version.
		$this->assertEquals( '0.7.7-compatible', $data['version'] );
	}

	/**
	 * Test health endpoint returns healthy status.
	 */
	public function test_health_endpoint() {
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/crawl4ai/health' );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'status', $data );
		$this->assertArrayHasKey( 'version', $data );
		$this->assertArrayHasKey( 'mode', $data );
		$this->assertArrayHasKey( 'timestamp', $data );

		$this->assertEquals( 'healthy', $data['status'] );
		$this->assertEquals( '0.7.7-compatible', $data['version'] );
		$this->assertEquals( 'local', $data['mode'] );
	}

	/**
	 * Test monitor endpoint requires authentication.
	 */
	public function test_monitor_requires_authentication() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/crawl4ai/monitor' );
		$response = rest_do_request( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test monitor endpoint requires manage_options capability.
	 */
	public function test_monitor_requires_manage_options() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/crawl4ai/monitor' );
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test browser pool constants are defined.
	 */
	public function test_browser_pool_constants() {
		$this->assertEquals( 'permanent', WP_MCP_AI_Tool_Run_Crawl4AI_Job::BROWSER_POOL_PERMANENT );
		$this->assertEquals( 'hot', WP_MCP_AI_Tool_Run_Crawl4AI_Job::BROWSER_POOL_HOT );
		$this->assertEquals( 'cold', WP_MCP_AI_Tool_Run_Crawl4AI_Job::BROWSER_POOL_COLD );
	}

	/**
	 * Test browser pool option is included in parameters schema.
	 */
	public function test_browser_pool_in_schema() {
		$tool   = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'options', $schema['properties'] );
		$this->assertArrayHasKey( 'properties', $schema['properties']['options'] );
		$this->assertArrayHasKey( 'browser_pool', $schema['properties']['options']['properties'] );

		$browser_pool_schema = $schema['properties']['options']['properties']['browser_pool'];

		$this->assertArrayHasKey( 'enum', $browser_pool_schema );
		$this->assertContains( 'permanent', $browser_pool_schema['enum'] );
		$this->assertContains( 'hot', $browser_pool_schema['enum'] );
		$this->assertContains( 'cold', $browser_pool_schema['enum'] );
	}
}
