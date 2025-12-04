<?php
/**
 * Tests for the Crawl4AI local REST API wrapper.
 */
class WP_MCP_AI_Crawl4AI_Local_API_Test extends WP_Test_REST_TestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up the test suite.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Reset state between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Ensure the Crawl4AI routes are registered.
	 */
	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/mcp-ai/v1/crawl4ai/crawl', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/crawl4ai/task/(?P<task_id>[A-Za-z0-9_\-]+)', $routes );
	}

	/**
	 * Ensure the crawl endpoint requires the manage_options capability.
	 */
	public function test_crawl_endpoint_requires_manage_options() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/crawl4ai/crawl' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'url' => 'https://example.com' ) ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Ensure crawl submissions return results and cache the payload locally.
	 */
	public function test_crawl_endpoint_returns_results_and_caches_task() {
		wp_set_current_user( $this->admin_id );

		$filter = function ( $pre, $args, $url ) {
			if ( false === strpos( $url, 'https://example.com' ) ) {
				return $pre;
			}

			return array(
				'body'     => '<html><body><h1>Example</h1><p>Content</p></body></html>',
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/crawl4ai/crawl' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'urls' => array( 'https://example.com' ) ) ) );

		$response = rest_get_server()->dispatch( $request );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( 'completed', $data['status'] );
		$this->assertNotEmpty( $data['task_id'] );
		$this->assertNotEmpty( $data['results'] );

		$task_key = $this->get_task_cache_key( $data['task_id'] );

		if ( is_multisite() ) {
			$cached = get_site_transient( $task_key );
		} else {
			$cached = get_transient( $task_key );
		}

		$this->assertIsArray( $cached );
		$this->assertSame( $data['status'], $cached['status'] );

		$this->cleanup_task_cache( $data['task_id'] );
	}

	/**
	 * Ensure cached results can be retrieved via the task endpoint.
	 */
	public function test_task_endpoint_returns_cached_result() {
		wp_set_current_user( $this->admin_id );

		$filter = function ( $pre, $args, $url ) {
			if ( false === strpos( $url, 'https://example.com' ) ) {
				return $pre;
			}

			return array(
				'body'     => '<html><body><h1>Example</h1><p>Content</p></body></html>',
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$create_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/crawl4ai/crawl' );
		$create_request->set_header( 'Content-Type', 'application/json' );
		$create_request->set_body( wp_json_encode( array( 'urls' => array( 'https://example.com' ) ) ) );

		$create_response = rest_get_server()->dispatch( $create_request );
		$data            = $create_response->get_data();

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertSame( 200, $create_response->get_status() );

		$task_id = $data['task_id'];

		$lookup_request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/crawl4ai/task/' . $task_id );
		$lookup_response = rest_get_server()->dispatch( $lookup_request );

		$this->assertSame( 200, $lookup_response->get_status() );

		$lookup_data = $lookup_response->get_data();

		$this->assertSame( $task_id, $lookup_data['task_id'] );
		$this->assertSame( 'completed', $lookup_data['status'] );
		$this->assertNotEmpty( $lookup_data['results'] );

		$this->cleanup_task_cache( $task_id );
	}

	/**
	 * Ensure task caches are scoped to the current site on multisite.
	 */
	public function test_task_cache_is_scoped_to_site_on_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite.' );
		}

		wp_set_current_user( $this->admin_id );

		$filter = function ( $pre, $args, $url ) {
			if ( false === strpos( $url, 'https://example.com' ) ) {
				return $pre;
			}

			return array(
				'body'     => '<html><body><h1>Example</h1><p>Content</p></body></html>',
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		$create_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/crawl4ai/crawl' );
		$create_request->set_header( 'Content-Type', 'application/json' );
		$create_request->set_body( wp_json_encode( array( 'urls' => array( 'https://example.com' ) ) ) );

		$create_response = rest_get_server()->dispatch( $create_request );

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertSame( 200, $create_response->get_status() );

		$data    = $create_response->get_data();
		$task_id = $data['task_id'];

		$second_blog_id = self::factory()->blog->create();
		add_user_to_blog( $second_blog_id, $this->admin_id, 'administrator' );

		switch_to_blog( $second_blog_id );
		wp_set_current_user( $this->admin_id );

		$lookup_request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/crawl4ai/task/' . $task_id );
		$lookup_response = rest_get_server()->dispatch( $lookup_request );

		$this->assertSame( 404, $lookup_response->get_status() );

		restore_current_blog();

		$this->cleanup_task_cache( $task_id );
	}

	/**
	 * Remove cached results for a given task ID.
	 *
	 * @param string $task_id Task identifier.
	 */
	protected function cleanup_task_cache( $task_id ) {
		$task_key = $this->get_task_cache_key( $task_id );

		if ( is_multisite() ) {
			delete_site_transient( $task_key );
		} else {
			delete_transient( $task_key );
		}
	}

	/**
	 * Build the expected cache key for a task ID.
	 *
	 * @param string $task_id Task identifier.
	 * @return string
	 */
	protected function get_task_cache_key( $task_id ) {
		$hash = md5( $task_id );

		if ( is_multisite() ) {
			return 'wp_mcp_ai_crawl4ai_task_' . absint( get_current_blog_id() ) . '_' . $hash;
		}

		return 'wp_mcp_ai_crawl4ai_task_' . $hash;
	}

	/**
	 * Test get_all_tasks returns empty array when no tasks exist.
	 */
	public function test_get_all_tasks_returns_empty_array_when_no_tasks() {
		$tasks = WP_MCP_AI_Crawl4AI_Local_API::get_all_tasks();

		$this->assertIsArray( $tasks );
		$this->assertEmpty( $tasks );
	}

	/**
	 * Test get_all_tasks retrieves cached tasks.
	 */
	public function test_get_all_tasks_retrieves_cached_tasks() {
		wp_set_current_user( $this->admin_id );

		$filter = function ( $pre, $args, $url ) {
			if ( false === strpos( $url, 'https://example.com' ) ) {
				return $pre;
			}

			return array(
				'body'     => '<html><body><h1>Example</h1><p>Content</p></body></html>',
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		// Create first task.
		$request1 = new WP_REST_Request( 'POST', '/mcp-ai/v1/crawl4ai/crawl' );
		$request1->set_header( 'Content-Type', 'application/json' );
		$request1->set_body( wp_json_encode( array( 'urls' => array( 'https://example.com/page1' ) ) ) );
		$response1 = rest_get_server()->dispatch( $request1 );
		$data1     = $response1->get_data();

		// Create second task.
		$request2 = new WP_REST_Request( 'POST', '/mcp-ai/v1/crawl4ai/crawl' );
		$request2->set_header( 'Content-Type', 'application/json' );
		$request2->set_body( wp_json_encode( array( 'urls' => array( 'https://example.com/page2' ) ) ) );
		$response2 = rest_get_server()->dispatch( $request2 );
		$data2     = $response2->get_data();

		remove_filter( 'pre_http_request', $filter, 10 );

		$this->assertSame( 200, $response1->get_status() );
		$this->assertSame( 200, $response2->get_status() );

		// Retrieve all tasks.
		$tasks = WP_MCP_AI_Crawl4AI_Local_API::get_all_tasks();

		$this->assertIsArray( $tasks );
		$this->assertCount( 2, $tasks );
		$this->assertArrayHasKey( $data1['task_id'], $tasks );
		$this->assertArrayHasKey( $data2['task_id'], $tasks );

		// Cleanup.
		$this->cleanup_task_cache( $data1['task_id'] );
		$this->cleanup_task_cache( $data2['task_id'] );
	}

	/**
	 * Test get_statistics calculates correct statistics.
	 */
	public function test_get_statistics_calculates_correctly() {
		wp_set_current_user( $this->admin_id );

		$filter = function ( $pre, $args, $url ) {
			if ( false === strpos( $url, 'https://example.com' ) ) {
				return $pre;
			}

			return array(
				'body'     => '<html><body><h1>Example</h1><p>Content</p></body></html>',
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		// Create multiple tasks.
		$task_ids = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/crawl4ai/crawl' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( array( 'urls' => array( "https://example.com/page{$i}" ) ) ) );
			$response     = rest_get_server()->dispatch( $request );
			$data         = $response->get_data();
			$task_ids[]   = $data['task_id'];
		}

		remove_filter( 'pre_http_request', $filter, 10 );

		// Get statistics.
		$stats = WP_MCP_AI_Crawl4AI_Local_API::get_statistics();

		$this->assertIsArray( $stats );
		$this->assertSame( 3, $stats['total_jobs'] );
		$this->assertSame( 3, $stats['completed_jobs'] );
		$this->assertSame( 0, $stats['running_jobs'] );
		$this->assertSame( 0, $stats['failed_jobs'] );
		$this->assertSame( 0, $stats['browser_pools'] );

		// Cleanup.
		foreach ( $task_ids as $task_id ) {
			$this->cleanup_task_cache( $task_id );
		}
	}

	/**
	 * Test get_recent_jobs returns jobs sorted by date.
	 */
	public function test_get_recent_jobs_returns_sorted_jobs() {
		wp_set_current_user( $this->admin_id );

		$filter = function ( $pre, $args, $url ) {
			if ( false === strpos( $url, 'https://example.com' ) ) {
				return $pre;
			}

			return array(
				'body'     => '<html><body><h1>Example</h1><p>Content</p></body></html>',
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		// Create multiple tasks.
		$task_ids = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/crawl4ai/crawl' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( array( 'urls' => array( "https://example.com/page{$i}" ) ) ) );
			$response     = rest_get_server()->dispatch( $request );
			$data         = $response->get_data();
			$task_ids[]   = $data['task_id'];
			// Small delay to ensure different stored_at timestamps.
			sleep( 1 );
		}

		remove_filter( 'pre_http_request', $filter, 10 );

		// Get recent jobs.
		$jobs = WP_MCP_AI_Crawl4AI_Local_API::get_recent_jobs( 10 );

		$this->assertIsArray( $jobs );
		$this->assertCount( 5, $jobs );

		// Verify first job is the most recent.
		$this->assertSame( $task_ids[4], $jobs[0]['id'] );

		// Verify jobs have expected fields.
		foreach ( $jobs as $job ) {
			$this->assertArrayHasKey( 'id', $job );
			$this->assertArrayHasKey( 'url', $job );
			$this->assertArrayHasKey( 'status', $job );
			$this->assertArrayHasKey( 'started', $job );
			$this->assertArrayHasKey( 'duration', $job );
			$this->assertArrayHasKey( 'browser_pool', $job );
		}

		// Cleanup.
		foreach ( $task_ids as $task_id ) {
			$this->cleanup_task_cache( $task_id );
		}
	}

	/**
	 * Test get_recent_jobs respects limit parameter.
	 */
	public function test_get_recent_jobs_respects_limit() {
		wp_set_current_user( $this->admin_id );

		$filter = function ( $pre, $args, $url ) {
			if ( false === strpos( $url, 'https://example.com' ) ) {
				return $pre;
			}

			return array(
				'body'     => '<html><body><h1>Example</h1><p>Content</p></body></html>',
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		// Create multiple tasks.
		$task_ids = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/crawl4ai/crawl' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( array( 'urls' => array( "https://example.com/page{$i}" ) ) ) );
			$response     = rest_get_server()->dispatch( $request );
			$data         = $response->get_data();
			$task_ids[]   = $data['task_id'];
		}

		remove_filter( 'pre_http_request', $filter, 10 );

		// Get limited jobs.
		$jobs = WP_MCP_AI_Crawl4AI_Local_API::get_recent_jobs( 3 );

		$this->assertIsArray( $jobs );
		$this->assertCount( 3, $jobs );

		// Cleanup.
		foreach ( $task_ids as $task_id ) {
			$this->cleanup_task_cache( $task_id );
		}
	}
}
