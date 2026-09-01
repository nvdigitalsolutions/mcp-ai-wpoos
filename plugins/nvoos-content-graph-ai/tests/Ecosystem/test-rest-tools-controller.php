<?php
/**
 * Tools listing REST controller port tests (Wave D5b).
 *
 * Characterization suite for `ToolsController`. Assertions mirror the
 * base plugin's tools-listing surface: constants, route registration
 * (standalone-only), the `tools` response contract (name / description /
 * inputSchema), assistant scoping, access validation, `_fields`
 * filtering, cache behaviour, and permission gates — exercised against
 * the base tool registry monolith / the nvoos/core registry standalone.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Rest\ToolsController;

/**
 * @group rest
 */
class Test_Tools_Controller extends \WP_UnitTestCase {

	/**
	 * Controller instance under test.
	 *
	 * @var ToolsController
	 */
	private $controller;

	/**
	 * Tool slugs known to the active registry.
	 *
	 * @var array<string>
	 */
	private $known_slugs;

	public function setUp(): void {
		parent::setUp();

		if ( ! \post_type_exists( 'mcp_ai_assistant' ) ) {
			\register_post_type( 'mcp_ai_assistant', array( 'public' => true ) );
		}

		$this->clear_tools_cache();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// The base registry returns a numeric list — collect slugs from
			// the first two tool objects.
			$all   = \WP_MCP_AI_Tool_Registry::get_instance()->get_tools();
			$slugs = array();
			foreach ( array_slice( $all, 0, 2 ) as $tool ) {
				if ( is_object( $tool ) && method_exists( $tool, 'get_slug' ) ) {
					$slugs[] = $tool->get_slug();
				}
			}
			$this->known_slugs = $slugs;
		} else {
			$this->known_slugs = array( 'ai_analyze_image', 'ai_create_text_embeddings' );
		}

		$this->controller = new ToolsController();
	}

	public function tearDown(): void {
		$this->clear_tools_cache();
		\wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Clear the tools listing cache in both install modes.
	 *
	 * @return void
	 */
	private function clear_tools_cache(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_REST_Cache' ) ) {
			\WP_MCP_AI_REST_Cache::invalidate_endpoint( 'tools' );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache cleanup for tests.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_wp_mcp_ai_rest_tools_' ) . '%'
			)
		);
	}

	/**
	 * Build a tools-list request.
	 *
	 * @param array $params Query parameters.
	 * @return WP_REST_Request
	 */
	private function tools_request( array $params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', '/mcp-ai/v1/tools' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	public function test_constants_match_base(): void {
		$this->assertSame( 'mcp-ai/v1', ToolsController::REST_NAMESPACE );
		$this->assertSame( 'mcp_ai_assistant', ToolsController::POST_TYPE );
	}

	public function test_routes_register_standalone_only(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// The base plugin owns these routes in monolith installs.
			$this->assertTrue( true );
			return;
		}

		$server = \rest_get_server();
		$this->controller->registerRoutes();

		$routes = $server->get_routes( 'mcp-ai/v1' );
		$this->assertArrayHasKey( '/mcp-ai/v1/tools', $routes );
	}

	public function test_all_tools_listing_contract(): void {
		$response = $this->controller->handle_tools_list( $this->tools_request() );

		$this->assertSame( 200, $response->get_status() );
		$tools = $response->get_data()['tools'];

		$this->assertNotEmpty( $tools );
		foreach ( $tools as $tool ) {
			$this->assertArrayHasKey( 'name', $tool );
			$this->assertArrayHasKey( 'description', $tool );
			$this->assertArrayHasKey( 'inputSchema', $tool );
			$this->assertIsString( $tool['name'] );
			$this->assertIsArray( $tool['inputSchema'] );
		}

		// The active registry's known tools are present.
		$names = wp_list_pluck( $tools, 'name' );
		foreach ( $this->known_slugs as $slug ) {
			$this->assertContains( $slug, $names );
		}
	}

	public function test_assistant_scoped_listing(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);
		\update_post_meta( $post_id, '_wp_mcp_ai_tools', $this->known_slugs );

		$response = $this->controller->handle_tools_list(
			$this->tools_request( array( 'assistant_id' => $post_id ) )
		);

		$tools = $response->get_data()['tools'];
		$names = wp_list_pluck( $tools, 'name' );

		$this->assertCount( count( $this->known_slugs ), $names );

		// The base registry may auto-upgrade to `_validated` variants —
		// accept both forms (byte-identical base behaviour).
		foreach ( $this->known_slugs as $slug ) {
			$match = false;
			foreach ( $names as $name ) {
				if ( $slug === $name || $slug . '_validated' === $name ) {
					$match = true;
					break;
				}
			}
			$this->assertTrue( $match, "Expected a tool matching {$slug}." );
		}
	}

	public function test_invalid_assistant_is_forbidden(): void {
		$response = $this->controller->handle_tools_list(
			$this->tools_request( array( 'assistant_id' => 999999 ) )
		);

		$this->assertWPError( $response );
		$this->assertSame( 'wp_mcp_ai_assistant_forbidden', $response->get_error_code() );

		$wrong_type = self::factory()->post->create();
		$response   = $this->controller->handle_tools_list(
			$this->tools_request( array( 'assistant_id' => $wrong_type ) )
		);
		$this->assertWPError( $response );
	}

	public function test_fields_filtering_always_keeps_name(): void {
		$response = $this->controller->handle_tools_list(
			$this->tools_request( array( '_fields' => 'description' ) )
		);

		$tool = $response->get_data()['tools'][0];
		$this->assertSame( array( 'name', 'description' ), array_keys( $tool ) );

		$response = $this->controller->handle_tools_list(
			$this->tools_request( array( '_fields' => 'bogus_field' ) )
		);
		$tool = $response->get_data()['tools'][0];
		// Byte-identical base behaviour: unknown fields leave the entry
		// unfiltered.
		$this->assertSame( array( 'name', 'description', 'inputSchema' ), array_keys( $tool ) );
	}

	public function test_cache_serves_second_call(): void {
		$first  = $this->controller->handle_tools_list( $this->tools_request() );
		$second = $this->controller->handle_tools_list( $this->tools_request() );

		// assertEquals: schema payloads carry stdClass instances that only
		// compare equal by value.
		$this->assertEquals( $first->get_data(), $second->get_data() );
	}

	public function test_permission_gates(): void {
		$request = $this->tools_request();

		$this->assertWPError( $this->controller->permissions_check( $request ) );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		\wp_set_current_user( $author );
		$this->assertTrue( $this->controller->permissions_check( $request ) );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );
		$this->assertWPError( $this->controller->permissions_check( $request ) );
	}
}
