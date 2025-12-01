<?php
/**
 * Tests for the Crawl4AI tool integration.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Crawl4AI_Tool_Test extends WP_UnitTestCase {

	/**
	 * Reset the current user between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Ensure the tool reports as unavailable when no endpoint is configured.
	 */
	public function test_tool_available_without_configuration() {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		$this->assertTrue( WP_MCP_AI_Tool_Run_Crawl4AI_Job::is_available() );
	}

	/**
	 * Ensure the tool can be disabled via the local enabled filter.
	 */
	public function test_tool_can_be_disabled_via_filter() {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		add_filter( 'wp_mcp_ai_crawl4ai_local_enabled', '__return_false' );

		$this->assertFalse( WP_MCP_AI_Tool_Run_Crawl4AI_Job::is_available() );

		$tool   = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$result = $tool->execute( array( 'url' => 'https://example.com' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_crawl4ai_unavailable', $result->get_error_code() );

		remove_filter( 'wp_mcp_ai_crawl4ai_local_enabled', '__return_false' );
	}

	/**
	 * Ensure the tool can be enabled via the base URL filter.
	 */
	public function test_tool_available_when_filter_supplies_base_url() {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		$filter = function () {
			return 'https://127.0.0.1:11235/';
		};

		add_filter( 'wp_mcp_ai_crawl4ai_base_url', $filter );

		$this->assertTrue( WP_MCP_AI_Tool_Run_Crawl4AI_Job::is_available() );

		remove_filter( 'wp_mcp_ai_crawl4ai_base_url', $filter );
	}

	/**
	 * Ensure the tool uses the base URL provided by the filter when executing.
	 */
	public function test_execute_uses_base_url_from_filter() {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		$filter = function () {
			return 'https://127.0.0.1:11235/';
		};

		add_filter( 'wp_mcp_ai_crawl4ai_base_url', $filter );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool      = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$requests  = array();
		$responses = array(
			'body'     => wp_json_encode(
				array(
					'task_id' => 'immediate-123',
					'status'  => 'completed',
					'results' => array(
						array(
							'url'      => 'https://example.com',
							'markdown' => '# Example',
						),
					),
				)
			),
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
		);

		$callback = function ( $pre, $args, $url ) use ( &$requests, $responses ) {
			$requests[] = array(
				'url'     => $url,
				'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
			);

			return $responses;
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		$result = $tool->execute(
			array(
				'urls' => array( 'https://example.com' ),
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $callback, 10 );
		remove_filter( 'wp_mcp_ai_crawl4ai_base_url', $filter );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertNotEmpty( $requests );
		$this->assertStringStartsWith( 'http://127.0.0.1:11235/crawl', $requests[0]['url'] );
	}

	/**
	 * Ensure HTTPS loopback hosts are normalised to HTTP when executing requests.
	 */
	public function test_execute_normalises_loopback_https_base_url() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();

		$settings['crawl4ai_base_url'] = 'https://127.0.0.1:11235/';
		$settings['crawl4ai_api_key']  = 'test-token';
		$settings['request_timeout']   = 5;

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool      = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$requests  = array();
		$responses = array(
			'body'     => wp_json_encode(
				array(
					'task_id' => 'loopback-123',
					'status'  => 'completed',
					'results' => array(
						array(
							'url'      => 'https://example.com',
							'markdown' => '# Example',
						),
					),
				)
			),
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
		);

		$callback = function ( $pre, $args, $url ) use ( &$requests, $responses ) {
			$requests[] = $url;

			return $responses;
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		$result = $tool->execute(
			array(
				'urls' => array( 'https://example.com' ),
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertNotEmpty( $requests );
		$this->assertStringStartsWith( 'http://127.0.0.1:11235/crawl', $requests[0] );
	}

	/**
	 * Ensure the tool forwards crawl requests and returns immediate results.
	 */
	public function test_execute_returns_results_without_waiting() {
		$this->configure_crawl4ai_settings();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool      = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$requests  = array();
		$responses = array(
			'body'     => wp_json_encode(
				array(
					'task_id' => 'instant-123',
					'status'  => 'completed',
					'results' => array(
						array(
							'url'      => 'https://example.com',
							'markdown' => '# Example',
						),
					),
				)
			),
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
		);

		$callback = function ( $pre, $args, $url ) use ( &$requests, $responses ) {
			$requests[] = array(
				'url'     => $url,
				'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
				'method'  => isset( $args['method'] ) ? $args['method'] : 'GET',
			);

			return $responses;
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		$result = $tool->execute(
			array(
				'urls' => array( 'https://example.com' ),
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertNotEmpty( $result['results'] );
		$this->assertSame( 'https://example.com', $result['results'][0]['url'] );
		$this->assertNotEmpty( $requests );
		$this->assertStringContainsString( '/crawl', $requests[0]['url'] );
		$this->assertSame( 'POST', $requests[0]['method'] );
	}

	/**
	 * Ensure the tool polls for completion when requested.
	 */
	public function test_execute_waits_for_completion_when_requested() {
		$this->configure_crawl4ai_settings();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool       = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$call_count = 0;

		$poll_callback = function ( $pre, $args, $url ) use ( &$call_count ) {
			if ( false !== strpos( $url, '/crawl' ) ) {
				++$call_count;

				return array(
					'body'     => wp_json_encode(
						array(
							'task_id' => 'task-123',
							'status'  => 'pending',
						)
					),
					'response' => array( 'code' => 202 ),
					'headers'  => array(),
				);
			}

			$this->fail( 'Unexpected HTTP request to ' . $url );

			return $pre;
		};

		add_filter( 'pre_http_request', $poll_callback, 10, 3 );

		$result = $tool->execute(
			array(
				'urls'                => array( 'https://example.com/page' ),
				'wait_for_completion' => true,
				'poll_interval'       => 0,
				'timeout'             => 10,
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $poll_callback, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( 'task-123', $result['task_id'] );
		$this->assertEmpty( $result['results'] );
		$this->assertSame( 1, $call_count );

		$job_status = WP_MCP_AI_Crawler::get_job_status( 'task-123' );
		$this->assertNotNull( $job_status );
		$this->assertSame( 'pending', $job_status['status'] );

		$cached = WP_MCP_AI_Crawl4AI_Local_API::retrieve_task_result( 'task-123' );
		$this->assertIsArray( $cached );
		$this->assertSame( 'pending', $cached['status'] );
		$this->assertArrayHasKey( 'metadata', $cached );
		$this->assertArrayHasKey( 'poll_interval', $cached['metadata'] );

		$scheduled = wp_next_scheduled( WP_MCP_AI_Crawler::CRON_HOOK, array( 'task-123' ) );
		$this->assertNotFalse( $scheduled );
	}

	/**
	 * Ensure the tool performs a local crawl when no external endpoint exists.
	 */
	public function test_execute_crawls_locally_without_endpoint() {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool      = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$requests  = array();
		$responses = array(
			'body'     => '<html><body><h1>Example</h1><p>Content here.</p></body></html>',
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
		);

		$callback = function ( $pre, $args, $url ) use ( &$requests, $responses ) {
			$method = isset( $args['method'] ) ? $args['method'] : 'GET';

			if ( 'GET' !== $method ) {
				return $pre;
			}

			$requests[] = array(
				'url'    => $url,
				'method' => $method,
				'args'   => $args,
			);

			return $responses;
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		$result = $tool->execute(
			array(
				'urls' => array( 'https://example.com/page' ),
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertNotEmpty( $result['results'] );
		$this->assertSame( 'https://example.com/page', $result['results'][0]['url'] );
		$this->assertStringContainsString( '# Example', $result['results'][0]['markdown'] );
		$this->assertNotEmpty( $requests );
		$this->assertSame( 'GET', $requests[0]['method'] );
		$this->assertArrayHasKey( 'reject_unsafe_urls', $requests[0]['args'] );
		$this->assertTrue( $requests[0]['args']['reject_unsafe_urls'] );
	}

	/**
	 * Ensure local crawl failures surface the first error for easier debugging.
	 */
	public function test_execute_local_crawl_reports_first_error() {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();

		$callback = function ( $preempt, $args, $url ) {
			return new WP_Error( 'http_request_failed', 'Connection refused' );
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		$result = $tool->execute(
			array(
				'urls' => array( 'https://example.com/fail' ),
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_crawl4ai_local_failed', $result->get_error_code() );

		$message = $result->get_error_message();
		$this->assertStringContainsString( 'Unable to crawl the requested URLs.', $message );
		$this->assertStringContainsString( 'https://example.com/fail', $message );
		$this->assertStringContainsString( 'Connection refused', $message );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'errors', $data );
		$this->assertArrayHasKey( 'https://example.com/fail', $data['errors'] );
	}

	/**
	 * Ensure loopback URLs are rejected when executing a crawl.
	 */
	public function test_execute_rejects_loopback_urls() {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$result = $tool->execute(
			array(
				'url' => 'http://127.0.0.1/internal',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_crawl4ai_unsafe_url', $result->get_error_code() );
	}

	/**
	 * Ensure large results are truncated when they exceed the configured token budget.
	 */
	public function test_execute_truncates_large_results_to_token_limit() {
		$this->configure_crawl4ai_settings();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();

		$limit_filter = function ( $limit, $response ) {
			return 10;
		};

		$chars_filter = function ( $chars_per_token, $response ) {
			return 1;
		};

		add_filter( 'wp_mcp_ai_crawl4ai_result_token_limit', $limit_filter, 10, 2 );
		add_filter( 'wp_mcp_ai_crawl4ai_chars_per_token', $chars_filter, 10, 2 );

		$long_content = str_repeat( 'A', 200 );

		$responses = array(
			'body'     => wp_json_encode(
				array(
					'task_id' => 'instant-456',
					'status'  => 'completed',
					'results' => array(
						array(
							'url'      => 'https://example.com',
							'markdown' => $long_content,
							'text'     => $long_content,
							'html'     => '<p>' . $long_content . '</p>',
						),
					),
				)
			),
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
		);

		$callback = function ( $preempt, $args, $url ) use ( $responses ) {
			return $responses;
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		$result = $tool->execute(
			array(
				'urls' => array( 'https://example.com' ),
			),
			array( 'user_id' => $user_id )
		);

		remove_filter( 'pre_http_request', $callback, 10 );
		remove_filter( 'wp_mcp_ai_crawl4ai_result_token_limit', $limit_filter, 10 );
		remove_filter( 'wp_mcp_ai_crawl4ai_chars_per_token', $chars_filter, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertArrayHasKey( 'metadata', $result );
		$this->assertTrue( $result['metadata']['truncated'] );
		$this->assertSame( 10, $result['metadata']['approximate_token_limit'] );

		$this->assertSame( 10, strlen( $result['results'][0]['markdown'] ) );
		$this->assertSame( 0, strlen( $result['results'][0]['text'] ) );
		$this->assertSame( 0, strlen( $result['results'][0]['html'] ) );

		$this->assertArrayHasKey( 'metadata', $result['results'][0] );
		$this->assertContains( 'markdown', $result['results'][0]['metadata']['truncated_fields'] );
		$this->assertContains( 'text', $result['results'][0]['metadata']['truncated_fields'] );
		$this->assertContains( 'html', $result['results'][0]['metadata']['truncated_fields'] );

		$this->assertArrayHasKey( 'raw', $result );
		$this->assertSame( $result['results'], $result['raw']['results'] );
	}

	/**
	 * Ensure private network URLs are rejected when executing a crawl.
	 */
	public function test_execute_rejects_private_network_urls() {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$result = $tool->execute(
			array(
				'urls' => array( 'http://192.168.1.50/dashboard' ),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_crawl4ai_unsafe_url', $result->get_error_code() );
	}

	/**
	 * Helper to configure Crawl4AI settings for the tests.
	 */
	protected function configure_crawl4ai_settings() {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();

		$settings['crawl4ai_base_url'] = 'https://api.example.com';
		$settings['crawl4ai_api_key']  = 'test-token';
		$settings['request_timeout']   = 5;

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
	}
}
