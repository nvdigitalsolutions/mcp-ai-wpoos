<?php
/**
 * Test the Pro SPA Surfaces discovery controller.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test class for WP_MCP_AI_Pro_SPA_Surfaces_Controller.
 *
 * @since 2.2.0
 */
class Test_WP_MCP_AI_Pro_SPA_Surfaces_Controller extends WP_UnitTestCase {

	/**
	 * Load the controller under test.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_SPA_Surfaces_Controller' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-pro-spa-surfaces-controller.php';
		}
	}

	/**
	 * Clear surface-discovery transients and options left by a test.
	 */
	public function tearDown(): void {
		delete_transient( 'wp_mcp_ai_pro_spa_page_nvoos_chat_spa' );
		delete_transient( 'wp_mcp_ai_pro_spa_page_nvoos_docs' );
		parent::tearDown();
	}

	/**
	 * A catalogued addon is reported only when its shortcode is registered.
	 */
	public function test_catalog_addon_reported_when_shortcode_registered() {
		$this->assertNotContains( 'docs-hub', wp_list_pluck( WP_MCP_AI_Pro_SPA_Surfaces_Controller::detect_surfaces(), 'id' ) );

		add_shortcode( 'nvoos_docs', '__return_empty_string' );

		$surfaces = WP_MCP_AI_Pro_SPA_Surfaces_Controller::detect_surfaces();
		$this->assertContains( 'docs-hub', wp_list_pluck( $surfaces, 'id' ) );

		remove_shortcode( 'nvoos_docs' );
	}

	/**
	 * A class-only catalogue entry (empty shortcode) is detected via class_exists.
	 */
	public function test_class_only_addon_detected_when_class_loaded() {
		$this->assertNotContains(
			'content-graph',
			wp_list_pluck( WP_MCP_AI_Pro_SPA_Surfaces_Controller::detect_surfaces(), 'id' )
		);

		// Simulate the standalone nvoos-content-graph plugin's namespaced class.
		if ( ! class_exists( 'NvoosContentGraph\Plugin' ) ) {
			class_alias( 'stdClass', 'NvoosContentGraph\Plugin' );
		}

		$surfaces = WP_MCP_AI_Pro_SPA_Surfaces_Controller::detect_surfaces();
		$this->assertContains( 'content-graph', wp_list_pluck( $surfaces, 'id' ) );
	}

	/**
	 * A shortcode surface resolves to the first page rendering that shortcode.
	 */
	public function test_shortcode_surface_resolves_to_page_permalink() {
		// A published page containing the chat-spa shortcode.
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[nvoos_chat_spa assistant_id="1"]',
			)
		);

		add_shortcode( 'nvoos_chat_spa', '__return_empty_string' );

		$surfaces = WP_MCP_AI_Pro_SPA_Surfaces_Controller::detect_surfaces();
		$chat     = null;
		foreach ( $surfaces as $surface ) {
			if ( 'chat-spa' === $surface['id'] ) {
				$chat = $surface;
				break;
			}
		}

		$this->assertNotNull( $chat );
		$this->assertSame( get_permalink( $page_id ), $chat['url'] );

		remove_shortcode( 'nvoos_chat_spa' );
		wp_delete_post( $page_id, true );
	}

	/**
	 * A route surface (content-graph) carries a `route` fragment for the client
	 * to resolve against its own hosted-SPA URL (not a server-resolved URL).
	 */
	public function test_route_surface_carries_route_fragment() {
		if ( ! class_exists( 'NvoosContentGraph\Plugin' ) ) {
			class_alias( 'stdClass', 'NvoosContentGraph\Plugin' );
		}

		$surfaces = WP_MCP_AI_Pro_SPA_Surfaces_Controller::detect_surfaces();
		$graph    = null;
		foreach ( $surfaces as $surface ) {
			if ( 'content-graph' === $surface['id'] ) {
				$graph = $surface;
				break;
			}
		}

		$this->assertNotNull( $graph );
		$this->assertArrayHasKey( 'route', $graph );
		$this->assertSame( '#/knowledge-graph', $graph['route'] );
		// The `url` is a site-root placeholder; the client derives the real URL.
		$this->assertNotEmpty( $graph['url'] );
	}

	/**
	 * The Assistant surface is always present (Pro itself).
	 */
	public function test_assistant_surface_always_present() {
		$surfaces = WP_MCP_AI_Pro_SPA_Surfaces_Controller::detect_surfaces();

		$ids = wp_list_pluck( $surfaces, 'id' );
		$this->assertContains( 'assistant', $ids );

		$assistant = null;
		foreach ( $surfaces as $surface ) {
			if ( 'assistant' === $surface['id'] ) {
				$assistant = $surface;
				break;
			}
		}

		// The Assistant (Pro SPA v2) surface is an SPA-internal route, so it
		// carries a `route` fragment for the client to resolve against its own
		// hosted-SPA URL (never a bare console/site URL).
		$this->assertNotNull( $assistant );
		$this->assertArrayHasKey( 'route', $assistant );
		$this->assertSame( '#/chat', $assistant['route'] );
	}

	/**
	 * Every surface descriptor carries the stable shape.
	 */
	public function test_surface_shape() {
		$surfaces = WP_MCP_AI_Pro_SPA_Surfaces_Controller::detect_surfaces();

		foreach ( $surfaces as $surface ) {
			$this->assertArrayHasKey( 'id', $surface );
			$this->assertArrayHasKey( 'title', $surface );
			$this->assertArrayHasKey( 'url', $surface );
			$this->assertArrayHasKey( 'description', $surface );
			$this->assertNotEmpty( $surface['id'] );
			$this->assertNotEmpty( $surface['title'] );
			$this->assertNotEmpty( $surface['url'] );
		}
	}

	/**
	 * The `wp_mcp_ai_pro_spa_surfaces` filter can extend the list.
	 */
	public function test_filter_extends_surfaces() {
		add_filter(
			'wp_mcp_ai_pro_spa_surfaces',
			static function ( $surfaces ) {
				$surfaces[] = array(
					'id'          => 'custom',
					'title'       => 'Custom',
					'url'         => 'https://example.com/',
					'description' => 'a custom surface',
				);
				return $surfaces;
			}
		);

		$surfaces = WP_MCP_AI_Pro_SPA_Surfaces_Controller::detect_surfaces();
		$ids      = wp_list_pluck( $surfaces, 'id' );

		$this->assertContains( 'custom', $ids );

		remove_all_filters( 'wp_mcp_ai_pro_spa_surfaces' );
	}

	/**
	 * An authenticated user passes the permission check.
	 */
	public function test_permission_allows_logged_in_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/spa-surfaces' );
		$this->assertTrue( WP_MCP_AI_Pro_SPA_Surfaces_Controller::check_permission( $request ) );

		wp_set_current_user( 0 );
	}

	/**
	 * An unauthenticated request with no credential is denied.
	 */
	public function test_permission_denies_anonymous() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/spa-surfaces' );
		$result  = WP_MCP_AI_Pro_SPA_Surfaces_Controller::check_permission( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );

		wp_set_current_user( 0 );
	}
}
