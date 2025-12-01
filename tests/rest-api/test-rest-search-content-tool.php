<?php
/**
 * Tests for the Search Content tool REST endpoint.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_REST_Search_Content_Tool_Test extends WP_Test_REST_TestCase {
	/**
	 * Ensure REST routes are registered before each test.
	 */
	public function set_up() {
		parent::set_up();

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$this->bootstrap_rest_controller();
	}

	/**
	 * Clean up the REST controller registration.
	 */
	public function tear_down() {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
			unset( $GLOBALS['wp_mcp_ai_rest_controller'] );
		}

		parent::tear_down();
	}

	/**
	 * The search tool should respect taxonomy and meta filters when returning posts.
	 */
	public function test_search_content_filters_posts_by_taxonomy_and_meta() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Content Discovery Assistant',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( 'search_content' ) );

		$matching_category = self::factory()->category->create( array( 'slug' => 'news' ) );
		$other_category    = self::factory()->category->create( array( 'slug' => 'updates' ) );

		$included_post = self::factory()->post->create(
			array(
				'post_title'   => 'AI Launch Recap',
				'post_content' => 'Highlights from the AI launch event with product demos.',
				'post_status'  => 'publish',
			)
		);
		wp_set_post_categories( $included_post, array( $matching_category ) );
		update_post_meta( $included_post, 'mcp_topic', 'ai' );

		$similar_post = self::factory()->post->create(
			array(
				'post_title'   => 'AI Launch Checklist',
				'post_content' => 'AI launch tasks for the operations crew.',
				'post_status'  => 'publish',
			)
		);
		wp_set_post_categories( $similar_post, array( $matching_category ) );
		update_post_meta( $similar_post, 'mcp_topic', 'ops' );

		$excluded_post = self::factory()->post->create(
			array(
				'post_title'   => 'Weekly Maintenance Notice',
				'post_content' => 'Scheduled downtime for maintenance.',
				'post_status'  => 'publish',
			)
		);
		wp_set_post_categories( $excluded_post, array( $other_category ) );
		update_post_meta( $excluded_post, 'mcp_topic', 'infrastructure' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'tool', 'search_content' );
		$request->set_param(
			'arguments',
			array(
				'search_term'      => 'AI',
				'post_type'        => 'post',
				'limit'            => 5,
				'taxonomy_filters' => array(
					array(
						'taxonomy' => 'category',
						'terms'    => array( 'news' ),
						'field'    => 'slug',
					),
				),
				'meta_filters'     => array(
					array(
						'key'   => 'mcp_topic',
						'value' => 'ai',
					),
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'search_content', $data['tool'] );
		$this->assertIsArray( $data['result'] );
		$this->assertCount( 1, $data['result'] );
		$this->assertSame( $included_post, $data['result'][0]['ID'] );
		$this->assertSame( 'post', $data['result'][0]['post_type'] );
	}

	/**
	 * Bootstrap the REST controller instance for tests.
	 */
	protected function bootstrap_rest_controller() {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$client   = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $client );

		rest_get_server();
		do_action( 'rest_api_init' );
	}
}
