<?php
/**
 * Dedicated tests for the pagination and _fields parameters on GET /assistants.
 *
 * Validates the behaviour added in the "Delicious Brains REST API scaling"
 * commit: per_page / page query params, X-WP-Total / X-WP-TotalPages response
 * headers, and the _fields field-projection parameter.
 *
 * @package WP_MCP_AI
 */

/**
 * Tests for pagination and _fields on the assistant directory endpoint.
 */
class WP_MCP_AI_REST_Assistants_Pagination_Fields_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID used for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * IDs of the assistants created for each test.
	 *
	 * @var int[]
	 */
	protected $assistant_ids = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Create 5 published assistants so pagination tests have enough data.
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assistant_ids[] = wp_insert_post(
				array(
					'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => sprintf( 'Assistant %d', $i ),
				)
			);
		}

		$this->bootstrap_rest_controller();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// X-WP-Total / X-WP-TotalPages headers
	// -------------------------------------------------------------------------

	/**
	 * X-WP-Total and X-WP-TotalPages headers are present on an unlimited request.
	 */
	public function test_response_includes_total_headers_for_unlimited_request() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'X-WP-Total', $headers, 'X-WP-Total header must be present' );
		$this->assertArrayHasKey( 'X-WP-TotalPages', $headers, 'X-WP-TotalPages header must be present' );
		$this->assertSame( 5, (int) $headers['X-WP-Total'], 'Total should equal the number of published assistants' );
		$this->assertSame( 1, (int) $headers['X-WP-TotalPages'], 'TotalPages should be 1 when all items are returned' );
	}

	/**
	 * per_page limits the number of items in the response body.
	 */
	public function test_per_page_limits_items_returned() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'per_page', 2 );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertCount( 2, $data['assistants'], 'Only 2 assistants should be returned for per_page=2' );
	}

	/**
	 * X-WP-Total reflects the full set even when per_page is applied.
	 */
	public function test_x_wp_total_reflects_full_count_when_paginating() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'per_page', 2 );

		$response = rest_get_server()->dispatch( $request );
		$headers  = $response->get_headers();

		$this->assertSame( 5, (int) $headers['X-WP-Total'], 'X-WP-Total must report total matching posts, not page size' );
		$this->assertSame( 3, (int) $headers['X-WP-TotalPages'], 'Ceiling(5/2)=3 pages' );
	}

	/**
	 * The page parameter advances through pages.
	 */
	public function test_page_param_returns_correct_page_of_results() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'per_page', 2 );
		$request->set_param( 'page', 1 );

		$response_p1 = rest_get_server()->dispatch( $request );
		$data_p1     = $response_p1->get_data();

		$request2 = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request2->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request2->set_param( 'per_page', 2 );
		$request2->set_param( 'page', 2 );

		$response_p2 = rest_get_server()->dispatch( $request2 );
		$data_p2     = $response_p2->get_data();

		$ids_p1 = wp_list_pluck( $data_p1['assistants'], 'id' );
		$ids_p2 = wp_list_pluck( $data_p2['assistants'], 'id' );

		// Pages must not overlap.
		$this->assertEmpty( array_intersect( $ids_p1, $ids_p2 ), 'Pages 1 and 2 must not share assistant IDs' );

		// Combined they should cover exactly 4 out of 5 assistants.
		$combined = array_merge( $ids_p1, $ids_p2 );
		$this->assertCount( 4, $combined );
	}

	/**
	 * The last page contains only the remaining items.
	 */
	public function test_last_page_contains_remainder_items() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'per_page', 2 );
		$request->set_param( 'page', 3 );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data['assistants'], 'Last page should contain only the 1 remaining assistant' );
	}

	// -------------------------------------------------------------------------
	// _fields parameter
	// -------------------------------------------------------------------------

	/**
	 * _fields limits which keys are present in each assistant object.
	 */
	public function test_fields_param_limits_returned_keys() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( '_fields', 'id,title' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertNotEmpty( $data['assistants'] );

		foreach ( $data['assistants'] as $assistant ) {
			$this->assertArrayHasKey( 'id', $assistant, '_fields must always include id' );
			$this->assertArrayHasKey( 'title', $assistant, 'Requested field "title" must be present' );
			// No other keys except id and title should appear.
			$extra = array_diff( array_keys( $assistant ), array( 'id', 'title' ) );
			$this->assertEmpty( $extra, 'Only requested fields should be present, got: ' . implode( ', ', $extra ) );
		}
	}

	/**
	 * id is always included even if not explicitly listed in _fields.
	 */
	public function test_fields_param_always_includes_id() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( '_fields', 'title' ); // Deliberately omit 'id'.

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		foreach ( $data['assistants'] as $assistant ) {
			$this->assertArrayHasKey( 'id', $assistant, 'id must always be included regardless of _fields value' );
		}
	}

	/**
	 * _fields with a single field still works correctly.
	 */
	public function test_fields_param_single_field() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( '_fields', 'provider' );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertNotEmpty( $data['assistants'] );

		foreach ( $data['assistants'] as $assistant ) {
			$this->assertArrayHasKey( 'id', $assistant );
			// Only id and provider should be present.
			$extra = array_diff( array_keys( $assistant ), array( 'id', 'provider' ) );
			$this->assertEmpty( $extra );
		}
	}

	/**
	 * When _fields is absent every summary field is returned.
	 */
	public function test_no_fields_param_returns_full_summary() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertNotEmpty( $data['assistants'] );

		$first = $data['assistants'][0];
		// The full summary includes at minimum id and title.
		$this->assertArrayHasKey( 'id', $first );
		$this->assertArrayHasKey( 'title', $first );
		$this->assertGreaterThan( 2, count( $first ), 'Full summary must contain more than just id and title' );
	}

	// -------------------------------------------------------------------------
	// Combination: pagination + _fields
	// -------------------------------------------------------------------------

	/**
	 * Combining per_page and _fields returns a paginated and projected response.
	 */
	public function test_per_page_and_fields_work_together() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'per_page', 3 );
		$request->set_param( '_fields', 'id,title' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data    = $response->get_data();
		$headers = $response->get_headers();

		$this->assertCount( 3, $data['assistants'], 'per_page=3 should return 3 items' );
		$this->assertSame( 5, (int) $headers['X-WP-Total'], 'X-WP-Total must still reflect the full count' );

		foreach ( $data['assistants'] as $assistant ) {
			$extra = array_diff( array_keys( $assistant ), array( 'id', 'title' ) );
			$this->assertEmpty( $extra );
		}
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	/**
	 * Bootstrap the REST controller with a mock router.
	 */
	protected function bootstrap_rest_controller() {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $mock_client );

		rest_get_server();
		do_action( 'rest_api_init' );
	}
}
