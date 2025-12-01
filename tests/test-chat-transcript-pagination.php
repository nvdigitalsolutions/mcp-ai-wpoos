<?php
/**
 * Tests for chat transcript pagination functionality.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Chat_Transcript_Pagination_Test extends WP_UnitTestCase {
	/**
	 * Administrator user ID for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Assistant post ID used in requests.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * REST controller instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest_controller;

	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		rest_get_server();
		do_action( 'init' );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that chat transcript endpoint accepts per_page parameter.
	 */
	public function test_transcript_endpoint_accepts_per_page_parameter() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
			$this->markTestSkipped( 'Requires JetEngine CCT to be active and configured' );
		}

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', $this->admin_id );
		$request->set_param( 'per_page', 10 );

		$response = rest_do_request( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'per_page', $data );
		$this->assertEquals( 10, $data['per_page'] );
	}

	/**
	 * Test that chat transcript endpoint accepts page parameter.
	 */
	public function test_transcript_endpoint_accepts_page_parameter() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
			$this->markTestSkipped( 'Requires JetEngine CCT to be active and configured' );
		}

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', $this->admin_id );
		$request->set_param( 'page', 2 );

		$response = rest_do_request( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'page', $data );
		$this->assertEquals( 2, $data['page'] );
	}

	/**
	 * Test that chat transcript endpoint returns total count.
	 */
	public function test_transcript_endpoint_returns_total_count() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
			$this->markTestSkipped( 'Requires JetEngine CCT to be active and configured' );
		}

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', $this->admin_id );

		$response = rest_do_request( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'total', $data );
		$this->assertIsInt( $data['total'] );
	}

	/**
	 * Test that per_page parameter defaults to 20 when not provided.
	 */
	public function test_transcript_per_page_defaults_to_twenty() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
			$this->markTestSkipped( 'Requires JetEngine CCT to be active and configured' );
		}

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', $this->admin_id );

		$response = rest_do_request( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'per_page', $data );
		$this->assertEquals( 20, $data['per_page'] );
	}

	/**
	 * Test that page parameter defaults to 1 when not provided.
	 */
	public function test_transcript_page_defaults_to_one() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
			$this->markTestSkipped( 'Requires JetEngine CCT to be active and configured' );
		}

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', $this->admin_id );

		$response = rest_do_request( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'page', $data );
		$this->assertEquals( 1, $data['page'] );
	}

	/**
	 * Test that per_page parameter is capped at 100.
	 */
	public function test_transcript_per_page_capped_at_one_hundred() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
			$this->markTestSkipped( 'Requires JetEngine CCT to be active and configured' );
		}

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', $this->admin_id );
		$request->set_param( 'per_page', 500 );

		$response = rest_do_request( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'per_page', $data );
		$this->assertEquals( 100, $data['per_page'] );
	}

	/**
	 * Test that negative per_page parameter defaults to 1.
	 */
	public function test_transcript_negative_per_page_defaults_to_one() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
			$this->markTestSkipped( 'Requires JetEngine CCT to be active and configured' );
		}

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', $this->admin_id );
		$request->set_param( 'per_page', -5 );

		$response = rest_do_request( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'per_page', $data );
		$this->assertEquals( 1, $data['per_page'] );
	}

	/**
	 * Test that negative page parameter defaults to 1.
	 */
	public function test_transcript_negative_page_defaults_to_one() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
			$this->markTestSkipped( 'Requires JetEngine CCT to be active and configured' );
		}

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', $this->admin_id );
		$request->set_param( 'page', -2 );

		$response = rest_do_request( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'page', $data );
		$this->assertEquals( 1, $data['page'] );
	}
}
