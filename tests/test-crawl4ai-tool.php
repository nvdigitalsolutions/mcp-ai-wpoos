<?php
/**
 * Tests for the Crawl4AI tool integration.
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
	public function test_tool_unavailable_without_configuration() {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		$this->assertFalse( WP_MCP_AI_Tool_Run_Crawl4AI_Job::is_available() );
		$this->assertSame(
			__( 'The Crawl4AI tool is disabled because no API endpoint has been configured.', 'wp-mcp-ai' ),
			WP_MCP_AI_Tool_Run_Crawl4AI_Job::get_unavailable_reason()
		);
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

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertNotEmpty( $result['results'] );
		$this->assertSame( 'https://example.com', $result['results'][0]['url'] );
		$this->assertNotEmpty( $requests );
		$this->assertStringContainsString( '/crawl', $requests[0]['url'] );
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

			if ( false !== strpos( $url, '/task/task-123' ) ) {
				++$call_count;

				if ( 2 === $call_count ) {
					return array(
						'body'     => wp_json_encode( array( 'status' => 'running' ) ),
						'response' => array( 'code' => 200 ),
						'headers'  => array(),
					);
				}

				return array(
					'body'     => wp_json_encode(
						array(
							'status'  => 'completed',
							'results' => array(
								array(
									'url'      => 'https://example.com/page',
									'markdown' => 'Done',
								),
							),
						)
					),
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
				);
			}

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
		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 'task-123', $result['task_id'] );
		$this->assertNotEmpty( $result['results'] );
		$this->assertGreaterThanOrEqual( 3, $call_count );
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
