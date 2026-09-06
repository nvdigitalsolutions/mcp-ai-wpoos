<?php
/**
 * Assistant directory REST controller port tests (Wave D5a).
 *
 * Characterization suite for `AssistantController`. Assertions mirror the
 * base plugin's assistant-directory surface: constants, route
 * registration (standalone-only), the directory response contract
 * (summary fields, rest links, capabilities, implementation,
 * X-WP-Total headers), search/include/pagination/_fields handling,
 * settings-driven provider/model fallback, cache behaviour, create/delete
 * flows with meta persistence, permission gates, and the
 * `wp_mcp_ai_rest_assistant_*` filters.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Rest\AssistantController;

/**
 * @group rest
 */
class Test_Assistant_Controller extends \WP_UnitTestCase {

	/**
	 * Controller instance under test.
	 *
	 * @var AssistantController
	 */
	private $controller;

	public function setUp(): void {
		parent::setUp();

		// Register the assistant CPT when absent (standalone matrix).
		if ( ! \post_type_exists( 'mcp_ai_assistant' ) ) {
			\register_post_type( 'mcp_ai_assistant', array( 'public' => true ) );
		}

		// Isolate the directory filters (the base plugin adds its own in
		// monolith installs).
		\remove_all_filters( 'wp_mcp_ai_rest_assistant_query_args' );
		\remove_all_filters( 'wp_mcp_ai_rest_assistant_summary' );
		\remove_all_filters( 'wp_mcp_ai_rest_assistant_index' );
		\remove_all_filters( 'wp_mcp_ai_rest_assistant_capabilities' );
		\remove_all_filters( 'wp_mcp_ai_normalised_rest_url' );
		\remove_all_filters( 'wp_mcp_ai_allowed_providers' );
		\remove_all_filters( 'wp_mcp_ai_assistant_access_cache_enabled' );

		// Invalidate the directory cache + base settings static cache.
		$this->clear_directory_cache();

		\delete_option( 'wp_mcp_ai_settings' );
		\delete_option( 'nvoos_content_graph_settings' );
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ) {
			\WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		}

		$this->controller = new AssistantController();
	}

	public function tearDown(): void {
		$this->clear_directory_cache();

		\remove_all_filters( 'wp_mcp_ai_rest_assistant_query_args' );
		\remove_all_filters( 'wp_mcp_ai_rest_assistant_summary' );
		\remove_all_filters( 'wp_mcp_ai_rest_assistant_index' );
		\remove_all_filters( 'wp_mcp_ai_rest_assistant_capabilities' );
		\remove_all_filters( 'wp_mcp_ai_normalised_rest_url' );
		\remove_all_filters( 'wp_mcp_ai_allowed_providers' );
		\remove_all_filters( 'wp_mcp_ai_assistant_access_cache_enabled' );

		\delete_option( 'wp_mcp_ai_settings' );
		\delete_option( 'nvoos_content_graph_settings' );
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ) {
			\WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		}

		\wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Clear the directory cache in both install modes.
	 *
	 * @return void
	 */
	private function clear_directory_cache(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_REST_Cache' ) ) {
			\WP_MCP_AI_REST_Cache::invalidate_endpoint( 'assistants' );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache cleanup for tests.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_wp_mcp_ai_rest_assistants_' ) . '%'
			)
		);
	}

	/**
	 * Build an assistant post with meta.
	 *
	 * @param array $meta   Meta map.
	 * @param array $post   Extra post args.
	 * @return int Post ID.
	 */
	private function create_assistant( array $meta = array(), array $post = array() ): int {
		$defaults = array(
			'post_type'   => 'mcp_ai_assistant',
			'post_status' => 'publish',
			'post_excerpt' => '', // The factory seeds a default excerpt.
		);
		$post_id  = self::factory()->post->create( array_merge( $defaults, $post ) );

		foreach ( $meta as $key => $value ) {
			\update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * Build a GET request against the directory endpoint.
	 *
	 * @param array $params Query parameters.
	 * @return WP_REST_Request
	 */
	private function index_request( array $params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	public function test_constants_match_base(): void {
		$this->assertSame( 'mcp-ai/v1', AssistantController::REST_NAMESPACE );
		$this->assertSame( 'mcp_ai_assistant', AssistantController::POST_TYPE );
		$this->assertSame( 1800, AssistantController::CACHE_TTL );
	}

	public function test_routes_register_standalone_only(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// The base plugin owns these routes in monolith installs.
			$this->assertTrue( true );
			return;
		}

		// The ecosystem bootstrap requires the plugin after plugins_loaded
		// has fired, so Plugin::register() never runs here — register via
		// a rest_api_init firing to stay on the action (WP 6.9 flags
		// off-action registration as incorrect usage).
		$server = \rest_get_server();
		$controller = $this->controller;
		\add_action(
			'rest_api_init',
			static function () use ( $controller ): void {
				$controller->registerRoutes();
			}
		);
		\do_action( 'rest_api_init', $server );

		$routes = $server->get_routes( 'mcp-ai/v1' );
		$this->assertArrayHasKey( '/mcp-ai/v1/assistants', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/assistants/(?P<id>\d+)', $routes );
	}

	public function test_directory_shape_with_empty_store(): void {
		$response = $this->controller->handle_assistants_index( $this->index_request() );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertSame( array(), $data['assistants'] );
		$this->assertSame( 0, $data['default_assistant'] );
		$this->assertSame( 'mcp-ai/v1', $data['rest']['namespace'] );
		$this->assertArrayHasKey( 'chat', $data['rest'] );
		$this->assertArrayHasKey( 'tools', $data['rest'] );
		$this->assertArrayHasKey( 'sse', $data['rest'] );
		$this->assertArrayHasKey( 'mcp', $data['rest'] );
		$this->assertSame( false, $data['capabilities']['tools']['listChanged'] );
		$this->assertSame( 'NV oOS', $data['implementation']['name'] );
		$this->assertArrayNotHasKey( 'token_scope', $data );

		$this->assertSame( 0, $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 1, $response->get_headers()['X-WP-TotalPages'] );
	}

	public function test_directory_summary_contract(): void {
		// Real attachments — the base CPT validates memory-file IDs.
		$file_a = self::factory()->attachment->create();
		$file_b = self::factory()->attachment->create();

		$assistant_id = $this->create_assistant(
			array(
				'_wp_mcp_ai_provider'       => 'openai',
				'_wp_mcp_ai_model'          => 'gpt-4o',
				'_wp_mcp_ai_temperature'    => '0.7',
				'_wp_mcp_ai_tools'          => array( 'web_search', 'web_search', 'get_post' ),
				'_wp_mcp_ai_memory_files'   => array( $file_a, $file_b ),
				'_wp_mcp_ai_vector_store_id' => 'vs_123',
			),
			array(
				'post_title'   => 'Alpha Assistant',
				'post_content' => 'A very capable assistant description.',
			)
		);

		$response = $this->controller->handle_assistants_index( $this->index_request() );
		$data     = $response->get_data();

		$entry = $data['assistants'][0];
		$this->assertSame( $assistant_id, $entry['id'] );
		$this->assertSame( 'Alpha Assistant', $entry['title'] );
		$this->assertSame( 'publish', $entry['status'] );
		$this->assertSame( 'openai', $entry['provider'] );
		$this->assertSame( 'gpt-4o', $entry['model'] );
		$this->assertSame( 0.7, $entry['temperature'] );
		$this->assertSame( array( 'web_search', 'get_post' ), $entry['tools'] );
		$this->assertSame( 2, $entry['tool_count'] );
		$this->assertSame( 2, $entry['memory_file_count'] );
		$this->assertTrue( $entry['has_vector_store'] );
		$this->assertFalse( $entry['has_corpus'] );
		$this->assertFalse( $entry['has_external_action'] );
		$this->assertStringContainsString( 'capable assistant', $entry['description'] );
		$this->assertArrayHasKey( 'updated_at', $entry );
		$this->assertArrayHasKey( 'permalink', $entry );
		$this->assertArrayHasKey( 'slug', $entry );

		// Default assistant falls back to the first listed assistant.
		$this->assertSame( $assistant_id, $data['default_assistant'] );
	}

	public function test_search_and_include_filters(): void {
		$a = $this->create_assistant( array(), array( 'post_title' => 'Alpha Assistant' ) );
		$b = $this->create_assistant( array(), array( 'post_title' => 'Beta Assistant' ) );

		$search = $this->controller->handle_assistants_index( $this->index_request( array( 'search' => 'beta' ) ) );
		$ids    = wp_list_pluck( $search->get_data()['assistants'], 'id' );
		$this->assertSame( array( $b ), $ids );

		$include = $this->controller->handle_assistants_index( $this->index_request( array( 'include' => array( $b, $a ) ) ) );
		$ids     = wp_list_pluck( $include->get_data()['assistants'], 'id' );
		$this->assertSame( array( $b, $a ), $ids );

		unset( $a );
	}

	public function test_pagination_headers(): void {
		$this->create_assistant( array(), array( 'post_title' => 'A One' ) );
		$this->create_assistant( array(), array( 'post_title' => 'B Two' ) );

		$response = $this->controller->handle_assistants_index(
			$this->index_request(
				array(
					'per_page' => 1,
					'page'     => 2,
				)
			)
		);

		$this->assertCount( 1, $response->get_data()['assistants'] );
		$this->assertSame( 2, $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 2, $response->get_headers()['X-WP-TotalPages'] );
	}

	public function test_fields_filtering_always_keeps_id(): void {
		$this->create_assistant( array(), array( 'post_title' => 'Field Test' ) );

		$response = $this->controller->handle_assistants_index(
			$this->index_request( array( '_fields' => 'title,provider' ) )
		);

		$entry = $response->get_data()['assistants'][0];
		$this->assertSame( array( 'id', 'title', 'provider' ), array_keys( $entry ) );
	}

	public function test_is_default_flag_from_settings(): void {
		$a = $this->create_assistant( array(), array( 'post_title' => 'Alpha' ) );
		$this->create_assistant( array(), array( 'post_title' => 'Beta' ) );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			\update_option( 'wp_mcp_ai_settings', array( 'default_assistant' => $a ) );
			\WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		} else {
			\update_option( 'nvoos_content_graph_settings', array( 'ai_default_assistant' => $a ) );
		}

		$response = $this->controller->handle_assistants_index( $this->index_request() );
		$data     = $response->get_data();

		$this->assertSame( $a, $data['default_assistant'] );

		$by_id = array();
		foreach ( $data['assistants'] as $entry ) {
			$by_id[ $entry['id'] ] = $entry;
		}
		$this->assertTrue( $by_id[ $a ]['is_default'] );
	}

	public function test_provider_model_fallback_from_settings(): void {
		// Assistant without provider/model meta falls back to settings.
		$assistant_id = $this->create_assistant( array(), array( 'post_title' => 'No Meta' ) );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			\update_option(
				'wp_mcp_ai_settings',
				array(
					'default_provider'     => 'gemini',
					'default_gemini_model' => 'gemini-2.5-pro',
					'default_model'        => 'gpt-4o',
				)
			);
			\WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		} else {
			\update_option(
				'nvoos_content_graph_settings',
				array(
					'ai_default_provider'     => 'gemini',
					'ai_default_gemini_model' => 'gemini-2.5-pro',
					'ai_default_model'        => 'gpt-4o',
				)
			);
		}

		$response = $this->controller->handle_assistants_index( $this->index_request() );
		$entry    = $response->get_data()['assistants'][0];

		$this->assertSame( 'gemini', $entry['provider'] );
		$this->assertSame( 'gemini-2.5-pro', $entry['model'] );
		$this->assertSame( $assistant_id, $entry['id'] );
	}

	public function test_directory_cache_serves_second_call(): void {
		$this->create_assistant( array(), array( 'post_title' => 'Cached' ) );

		$first  = $this->controller->handle_assistants_index( $this->index_request() );
		$second = $this->controller->handle_assistants_index( $this->index_request() );

		$this->assertSame( $first->get_data(), $second->get_data() );

		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			// Standalone: the transient-backed cache entry exists.
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_wp_mcp_ai_rest_assistants_' ) . '%'
				)
			);
			$this->assertGreaterThan( 0, $count );
		}
	}

	public function test_create_requires_title(): void {
		\wp_set_current_user( 1 );

		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
		$result  = $this->controller->handle_assistant_create( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_title', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_create_persists_assistant_and_meta(): void {
		\wp_set_current_user( 1 );

		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
		$request->set_param( 'title', 'New Assistant' );
		$request->set_param( 'description', '<p>Hello <strong>world</strong></p>' );
		$request->set_param( 'provider', 'openai' );
		$request->set_param( 'model', 'gpt-4o' );
		$request->set_param( 'temperature', 5.5 ); // Clamped to 2.0.
		$request->set_param( 'system_prompt', 'Be helpful.' );
		$request->set_param( 'tools', array( 'web_search', 'get_post' ) );

		$response = $this->controller->handle_assistant_create( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsInt( $data['id'] );
		$this->assertSame( 'New Assistant', $data['title'] );
		$this->assertSame( 'draft', $data['status'] );

		$post_id = $data['id'];
		$this->assertSame( 'mcp_ai_assistant', \get_post_type( $post_id ) );
		$this->assertSame( '<p>Hello <strong>world</strong></p>', \get_post_field( 'post_content', $post_id ) );
		$this->assertSame( 'openai', \get_post_meta( $post_id, '_wp_mcp_ai_provider', true ) );
		$this->assertSame( 'gpt-4o', \get_post_meta( $post_id, '_wp_mcp_ai_model', true ) );
		$this->assertSame( 2.0, (float) \get_post_meta( $post_id, '_wp_mcp_ai_temperature', true ) );
		$this->assertSame( 'Be helpful.', \get_post_meta( $post_id, '_wp_mcp_ai_system_prompt', true ) );
		$this->assertSame( array( 'web_search', 'get_post' ), \get_post_meta( $post_id, '_wp_mcp_ai_tools', true ) );
	}

	public function test_create_invalid_status_falls_back_to_draft(): void {
		\wp_set_current_user( 1 );

		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
		$request->set_param( 'title', 'Status Test' );
		$request->set_param( 'status', 'garbage' );

		$response = $this->controller->handle_assistant_create( $request );
		$this->assertSame( 'draft', $response->get_data()['status'] );
	}

	public function test_delete_flow(): void {
		\wp_set_current_user( 1 );

		$missing = new \WP_REST_Request( 'DELETE', '/mcp-ai/v1/assistants/0' );
		$result  = $this->controller->handle_assistant_delete( $missing );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_assistant_id', $result->get_error_code() );

		$not_found = new \WP_REST_Request( 'DELETE', '/mcp-ai/v1/assistants/999999' );
		$not_found->set_param( 'id', 999999 );
		$result    = $this->controller->handle_assistant_delete( $not_found );
		$this->assertSame( 'wp_mcp_ai_assistant_not_found', $result->get_error_code() );

		$wrong_type = self::factory()->post->create();
		$request    = new \WP_REST_Request( 'DELETE', '/mcp-ai/v1/assistants/' . $wrong_type );
		$request->set_param( 'id', $wrong_type );
		$result     = $this->controller->handle_assistant_delete( $request );
		$this->assertSame( 'wp_mcp_ai_assistant_not_found', $result->get_error_code() );

		$assistant_id = $this->create_assistant();
		$request      = new \WP_REST_Request( 'DELETE', '/mcp-ai/v1/assistants/' . $assistant_id );
		$request->set_param( 'id', $assistant_id );
		$response     = $this->controller->handle_assistant_delete( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertSame( $assistant_id, $response->get_data()['id'] );
		$this->assertNull( \get_post( $assistant_id ) );
	}

	public function test_permission_gates(): void {
		$request = $this->index_request();

		// Logged-out → denied.
		$this->assertWPError( $this->controller->permissions_check_list( $request ) );

		// Subscribers lack edit_posts → denied.
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );
		$this->assertWPError( $this->controller->permissions_check_list( $request ) );

		// Authors can list but cannot create/delete.
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		\wp_set_current_user( $author );
		$this->assertTrue( $this->controller->permissions_check_list( $request ) );
		$this->assertWPError( $this->controller->permissions_check_create( $request ) );
		$this->assertWPError( $this->controller->permissions_check_delete( $request ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $admin );
		$this->assertTrue( $this->controller->permissions_check_create( $request ) );
		$this->assertTrue( $this->controller->permissions_check_delete( $request ) );
	}

	public function test_summary_filter_receives_contract(): void {
		$this->create_assistant( array(), array( 'post_title' => 'Filter Me' ) );

		$received = array();
		\add_filter(
			'wp_mcp_ai_rest_assistant_summary',
			static function ( $summary, $post, $config, $settings, $request ) use ( &$received ) {
				$received = compact( 'summary', 'config', 'settings' );
				$summary['injected'] = true;
				return $summary;
			},
			10,
			5
		);

		$response = $this->controller->handle_assistants_index( $this->index_request() );
		$entry    = $response->get_data()['assistants'][0];

		$this->assertTrue( $entry['injected'] );
		$this->assertArrayHasKey( 'provider', $received['config'] );
		$this->assertArrayHasKey( 'default_provider', $received['settings'] );
	}

	public function test_index_filter_receives_contract(): void {
		$received = null;
		\add_filter(
			'wp_mcp_ai_rest_assistant_index',
			static function ( $data, $request, $auth_context ) use ( &$received ) {
				$received = compact( 'auth_context' );
				return $data;
			},
			10,
			3
		);

		$this->controller->handle_assistants_index( $this->index_request() );

		$this->assertFalse( $received['auth_context']['token_authenticated'] );
	}
}
