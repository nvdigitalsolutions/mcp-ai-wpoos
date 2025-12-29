<?php
/**
 * Tests for the Crawl4AI background job manager.
 */
class WP_MCP_AI_Crawler_Job_Manager_Test extends WP_UnitTestCase {
	/**
	 * Stubbed HTTP responses for polling.
	 *
	 * @var array
	 */
	protected $http_responses = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$settings                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['crawl4ai_base_url'] = 'https://api.example.com';
		$settings['crawl4ai_api_key']  = 'test-token';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		WP_MCP_AI_Crawler::init();

		add_filter( 'pre_http_request', array( $this, 'mock_http_request' ), 10, 3 );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http_request' ), 10 );
		parent::tearDown();
	}

	/**
	 * Ensure remote jobs progress through polling and cache the final result.
	 */
	public function test_background_polling_completes_job() {
		$task_id   = 'task-123';
		$arguments = array( 'urls' => array( 'https://example.org' ) );
		$context   = array();

		$initial = array(
			'status'   => 'pending',
			'task_id'  => $task_id,
			'results'  => array(),
			'metadata' => array(),
			'raw'      => array(),
		);

		$filter = static function ( $formatted, $decoded ) {
			unset( $decoded );
			$formatted['metadata']['filtered'] = true;
			return $formatted;
		};

		add_filter( 'wp_mcp_ai_crawl4ai_response', $filter, 10, 4 );

		$queued = WP_MCP_AI_Crawler::register_remote_job(
			$task_id,
			array(
				'base_url'       => 'https://api.example.com',
				'arguments'      => $arguments,
				'context'        => $context,
				'poll_interval'  => 1,
				'wait_timeout'   => 30,
				'status'         => 'pending',
				'initial_result' => $initial,
				'raw_response'   => array(),
			)
		);

		$this->assertTrue( $queued );

		$this->http_responses = array(
			array(
				'code' => 200,
				'body' => array(
					'status'  => 'running',
					'task_id' => $task_id,
				),
			),
			array(
				'code' => 200,
				'body' => array(
					'status'  => 'completed',
					'task_id' => $task_id,
					'results' => array(
						array(
							'url'      => 'https://example.org',
							'markdown' => '# Example',
						),
					),
				),
			),
		);

		WP_MCP_AI_Crawler::handle_poll_event( $task_id );

		$job_status = WP_MCP_AI_Crawler::get_job_status( $task_id );
		$this->assertNotNull( $job_status );
		$this->assertSame( 'running', $job_status['status'] );

		$cached_progress = WP_MCP_AI_Crawl4AI_Local_API::retrieve_task_result( $task_id );
		$this->assertIsArray( $cached_progress );
		$this->assertSame( 'running', $cached_progress['status'] );

		WP_MCP_AI_Crawler::handle_poll_event( $task_id );

		$job_status = WP_MCP_AI_Crawler::get_job_status( $task_id );
		$this->assertNull( $job_status );

		$cached_result = WP_MCP_AI_Crawl4AI_Local_API::retrieve_task_result( $task_id );
		$this->assertIsArray( $cached_result );
		$this->assertSame( 'completed', $cached_result['status'] );
		$this->assertArrayHasKey( 'metadata', $cached_result );
		$this->assertTrue( $cached_result['metadata']['filtered'] );
		$this->assertNotEmpty( $cached_result['results'] );

		remove_filter( 'wp_mcp_ai_crawl4ai_response', $filter, 10 );
	}

	/**
	 * Hook into WordPress HTTP to provide canned responses.
	 *
	 * @param mixed  $preempt Preempt value.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return mixed
	 */
	public function mock_http_request( $preempt, $args, $url ) {
		unset( $args );

		if ( false === strpos( $url, '/task/' ) ) {
			return $preempt;
		}

		$response = array_shift( $this->http_responses );
		if ( ! $response ) {
			return $preempt;
		}

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $response['body'] ),
			'response' => array(
				'code'    => $response['code'],
				'message' => 'OK',
			),
		);
	}
}
