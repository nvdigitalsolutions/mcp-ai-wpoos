<?php
/**
 * MCP JSON-RPC controller port tests (Wave D5c).
 *
 * Characterization suite for `McpController`. Assertions mirror the
 * base plugin's MCP surface: constants, protocol versions, route
 * registration (standalone-only), the JSON-RPC 2.0 envelope (parse
 * errors, invalid requests, notifications, batching, header mismatch),
 * server/discover negotiation, tools/list contract, the tools/call
 * stub, CORS headers, and permission gates — exercised against the
 * base tool registry monolith / the nvoos/core registry standalone.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Rest\McpController;
use NvoosContentGraphAi\Rest\ToolsController;

/**
 * @group rest
 */
class Test_Mcp_Controller extends \WP_UnitTestCase {

	/**
	 * Controller instance under test.
	 *
	 * @var McpController
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

		$this->controller = new McpController( new ToolsController() );
	}

	public function tearDown(): void {
		$this->clear_tools_cache();
		$this->reset_settings_state();
		$this->clear_tool_rate_limit_transients();
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
	 * Reset per-mode settings state (CORS tests write settings).
	 *
	 * @return void
	 */
	private function reset_settings_state(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			\delete_option( 'wp_mcp_ai_settings' );
			\WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		\delete_option( 'nvoos_content_graph_settings' );
	}

	/**
	 * Clear tool rate-limit transients left behind by tools/call tests.
	 *
	 * @return void
	 */
	private function clear_tool_rate_limit_transients(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient cleanup for tests.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_wp_mcp_ai_tool_rl_' ) . '%'
			)
		);
	}

	/**
	 * Build a JSON-RPC POST request with the given raw body.
	 *
	 * @param string $body Raw JSON-RPC body.
	 * @return WP_REST_Request
	 */
	private function mcp_request( string $body ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );
		return $request;
	}

	/**
	 * Build a single-message JSON-RPC body.
	 *
	 * @param string     $method JSON-RPC method.
	 * @param array      $params Method params.
	 * @param mixed|null $id     Request id (null → notification).
	 * @return string
	 */
	private function rpc_body( string $method, array $params = array(), $id = 1 ): string {
		$message = array(
			'jsonrpc' => '2.0',
			'method'  => $method,
		);

		if ( null !== $id ) {
			$message['id'] = $id;
		}

		if ( ! empty( $params ) ) {
			$message['params'] = $params;
		}

		return (string) \wp_json_encode( $message );
	}

	// ─── Constants + protocol versions ──────────────────────────────

	public function test_constants_match_base(): void {
		$this->assertSame( 'mcp-ai/v1', McpController::REST_NAMESPACE );
		$this->assertSame( 'mcp_ai_assistant', McpController::POST_TYPE );
	}

	public function test_supported_protocol_versions_order(): void {
		$reflection = new \ReflectionMethod( McpController::class, 'get_supported_protocol_versions' );
		$reflection->setAccessible( true );

		$this->assertSame(
			array( '2026-07-28', '2025-06-18', '2025-03-26', '2024-11-05' ),
			$reflection->invoke( $this->controller )
		);
	}

	// ─── Route registration ─────────────────────────────────────────

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
		$server     = \rest_get_server();
		$controller = $this->controller;
		\add_action(
			'rest_api_init',
			static function () use ( $controller ): void {
				$controller->registerRoutes();
			}
		);
		\do_action( 'rest_api_init', $server );

		$routes = $server->get_routes( 'mcp-ai/v1' );
		$this->assertArrayHasKey( '/mcp-ai/v1/mcp', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/no-sse', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/sse', $routes );
	}

	// ─── Discovery ──────────────────────────────────────────────────

	public function test_get_discovery_json(): void {
		$request  = new \WP_REST_Request( 'GET', '/mcp-ai/v1/mcp' );
		$response = $this->controller->handle_mcp_get_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertSame( 'NV oOS MCP Server', $data['name'] );
		$this->assertSame( '2026-07-28', $data['protocolVersion'] );
		$this->assertTrue( $data['capabilities']['tools']['listChanged'] );
		$this->assertFalse( $data['capabilities']['sse']['enabled'] );
		$this->assertSame( array( 'GET', 'POST' ), $data['transports']['streamable_http']['methods'] );
		$this->assertSame( rest_url( 'mcp-ai/v1/mcp' ), $data['endpoints']['mcp'] );
		$this->assertSame( rest_url( 'mcp-ai/v1/assistants' ), $data['endpoints']['assistants'] );
		$this->assertSame( rest_url( 'mcp-ai/v1/tools' ), $data['endpoints']['tools'] );
	}

	public function test_sse_handshake_returns_discovery(): void {
		$request  = new \WP_REST_Request( 'GET', '/mcp-ai/v1/sse' );
		$response = $this->controller->handle_sse_handshake( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'NV oOS MCP Server', $response->get_data()['name'] );
	}

	public function test_no_sse_returns_assistant_directory(): void {
		$request  = new \WP_REST_Request( 'GET', '/mcp-ai/v1/no-sse' );
		$response = $this->controller->handle_no_sse_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'assistants', $data );
		$this->assertArrayHasKey( 'default_assistant', $data );
		$this->assertArrayHasKey( 'rest', $data );
		// Totals travel in the REST headers (base directory contract).
		$this->assertArrayHasKey( 'X-WP-Total', $response->get_headers() );
	}

	// ─── JSON-RPC envelope ──────────────────────────────────────────

	public function test_parse_error_empty_body(): void {
		$response = $this->controller->handle_mcp_request( $this->mcp_request( '' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( '2.0', $data['jsonrpc'] );
		$this->assertNull( $data['id'] );
		$this->assertSame( -32700, $data['error']['code'] );
	}

	public function test_parse_error_invalid_json(): void {
		$response = $this->controller->handle_mcp_request( $this->mcp_request( 'not json{' ) );

		$data = $response->get_data();
		$this->assertSame( -32700, $data['error']['code'] );
	}

	public function test_invalid_request_missing_jsonrpc(): void {
		$response = $this->controller->handle_mcp_request(
			$this->mcp_request( '{"method":"ping","id":1}' )
		);

		$data = $response->get_data();
		$this->assertSame( -32600, $data['error']['code'] );
		$this->assertSame( 1, $data['id'] );
	}

	public function test_ping_returns_empty_result_with_meta(): void {
		$response = $this->controller->handle_mcp_request(
			$this->mcp_request( $this->rpc_body( 'ping' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertSame( '2.0', $data['jsonrpc'] );
		$this->assertSame( 1, $data['id'] );
		$this->assertEquals( new \stdClass(), $data['result'] );
		$this->assertSame( 'ping', $response->get_headers()['Mcp-Method'] );
	}

	public function test_notification_returns_202_null(): void {
		$response = $this->controller->handle_mcp_request(
			$this->mcp_request( $this->rpc_body( 'notifications/initialized', array(), null ) )
		);

		$this->assertSame( 202, $response->get_status() );
		$this->assertNull( $response->get_data() );
	}

	public function test_unknown_method_returns_32601(): void {
		$response = $this->controller->handle_mcp_request(
			$this->mcp_request( $this->rpc_body( 'bogus/method', array(), 7 ) )
		);

		$data = $response->get_data();
		$this->assertSame( -32601, $data['error']['code'] );
		$this->assertSame( 7, $data['id'] );
		$this->assertStringContainsString( 'MCP method not found', $data['error']['message'] );
	}

	public function test_header_mismatch_returns_32020(): void {
		$request = $this->mcp_request( $this->rpc_body( 'ping' ) );
		$request->set_header( 'Mcp-Method', 'tools/list' );

		$response = $this->controller->handle_mcp_request( $request );

		$data = $response->get_data();
		$this->assertSame( -32020, $data['error']['code'] );
	}

	public function test_batch_request(): void {
		$messages = array(
			json_decode( $this->rpc_body( 'ping', array(), 1 ), true ),
			json_decode( $this->rpc_body( 'ping', array(), 2 ), true ),
		);

		$response = $this->controller->handle_mcp_request(
			$this->mcp_request( (string) \wp_json_encode( $messages ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertCount( 2, $data );
		$this->assertSame( 1, $data[0]['id'] );
		$this->assertSame( 2, $data[1]['id'] );
	}

	public function test_notifications_only_batch_returns_202(): void {
		$messages = array(
			json_decode( $this->rpc_body( 'notifications/initialized', array(), null ), true ),
			json_decode( $this->rpc_body( 'notifications/cancelled', array( 'requestId' => 'r1' ), null ), true ),
		);

		$response = $this->controller->handle_mcp_request(
			$this->mcp_request( (string) \wp_json_encode( $messages ) )
		);

		$this->assertSame( 202, $response->get_status() );
		$this->assertNull( $response->get_data() );
	}

	public function test_batch_too_large(): void {
		$messages = array_fill( 0, 21, json_decode( $this->rpc_body( 'ping', array(), 1 ), true ) );

		$response = $this->controller->handle_mcp_request(
			$this->mcp_request( (string) \wp_json_encode( $messages ) )
		);

		$data = $response->get_data();
		$this->assertSame( -32600, $data['error']['code'] );
	}

	// ─── server/discover ────────────────────────────────────────────

	public function test_server_discover_negotiates_version(): void {
		$response = $this->controller->handle_mcp_request(
			$this->mcp_request(
				$this->rpc_body(
					'server/discover',
					array( 'supportedProtocolVersions' => array( '2025-06-18', '2024-11-05' ) )
				)
			)
		);

		$result = $response->get_data()['result'];
		$this->assertSame( '2025-06-18', $result['protocolVersion'] );
		$this->assertSame( 'NV oOS', $result['serverInfo']['name'] );
		$this->assertIsString( $result['instructions'] );
		$this->assertTrue( $result['capabilities']['prompts']['listChanged'] );
		// discover_include_tools defaults to true — tools are inline.
		$this->assertIsArray( $result['tools'] );
	}

	public function test_server_discover_no_version_defaults_oldest(): void {
		$response = $this->controller->handle_mcp_request(
			$this->mcp_request( $this->rpc_body( 'server/discover' ) )
		);

		$this->assertSame(
			'2024-11-05',
			$response->get_data()['result']['protocolVersion']
		);
	}

	public function test_initialize_routes_to_discover(): void {
		$response = $this->controller->handle_mcp_request(
			$this->mcp_request(
				$this->rpc_body(
					'initialize',
					array( 'supportedProtocolVersions' => array( '2026-07-28' ) )
				)
			)
		);

		$this->assertSame(
			'2026-07-28',
			$response->get_data()['result']['protocolVersion']
		);
	}

	// ─── tools/list ─────────────────────────────────────────────────

	public function test_tools_list_contract(): void {
		$response = $this->controller->handle_mcp_request(
			$this->mcp_request( $this->rpc_body( 'tools/list' ) )
		);

		$result = $response->get_data()['result'];

		$this->assertNotEmpty( $result['tools'] );
		foreach ( $result['tools'] as $tool ) {
			$this->assertArrayHasKey( 'name', $tool );
			$this->assertArrayHasKey( 'description', $tool );
			$this->assertArrayHasKey( 'inputSchema', $tool );
			$this->assertIsString( $tool['name'] );
			$this->assertIsArray( $tool['inputSchema'] );
		}

		$this->assertSame( 0, $result['ttlMs'] );
		$this->assertSame( 'private', $result['cacheScope'] );

		// Every successful array result carries the server identity stamp.
		$this->assertSame(
			'NV oOS',
			$result['_meta']['io.modelcontextprotocol/serverInfo']['name']
		);

		// The active registry's known tools are present.
		$names = wp_list_pluck( $result['tools'], 'name' );
		foreach ( $this->known_slugs as $slug ) {
			$this->assertContains( $slug, $names );
		}
	}

	public function test_tools_list_assistant_scoped(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);
		\update_post_meta( $post_id, '_wp_mcp_ai_tools', $this->known_slugs );

		$response = $this->controller->handle_mcp_request(
			$this->mcp_request( $this->rpc_body( 'tools/list', array( 'assistant_id' => $post_id ) ) )
		);

		$tools = $response->get_data()['result']['tools'];
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

	// ─── tools/call (Wave D8 Cluster 0 — execution) ────────────────

	public function test_tools_call_unknown_tool_returns_missing(): void {
		$response = $this->controller->handle_mcp_request(
			$this->mcp_request(
				$this->rpc_body(
					'tools/call',
					array(
						'name'      => 'definitely_not_a_registered_tool',
						'arguments' => array(),
					)
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( -32603, $data['error']['code'] );
		$this->assertSame( 404, $data['error']['data']['status'] );
	}

	public function test_tools_call_executes_registered_tool_error_envelope(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// The standalone execution path is the new surface; in monolith
			// installs the base plugin serves tools/call on the same route
			// and is covered by the base suite.
			$this->markTestSkipped( 'Standalone-only execution surface.' );
		}

		$response = $this->controller->handle_mcp_request(
			$this->mcp_request(
				$this->rpc_body(
					'tools/call',
					array(
						'name'      => 'ai_summarize_text',
						'arguments' => array(),
					)
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( -32603, $data['error']['code'] );
		$this->assertStringContainsString( 'Text is required', $data['error']['message'] );
	}

	public function test_tools_call_executes_registered_tool_shapes_text_content(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Standalone-only execution surface.' );
		}

		$response = $this->controller->handle_mcp_request(
			$this->mcp_request(
				$this->rpc_body(
					'tools/call',
					array(
						'name'      => 'ai_summarize_text',
						'arguments' => array(
							'text' => 'A test paragraph for the summarizer.',
						),
					)
				)
			)
		);

		$data    = $response->get_data();
		$content = $data['result']['content'];

		$this->assertIsArray( $content );
		$this->assertSame( 'text', $content[0]['type'] );
		$this->assertStringContainsString( 'client_method', $content[0]['text'] );
		$this->assertStringContainsString( 'summarize', $content[0]['text'] );
	}

	public function test_tools_call_missing_name(): void {
		$response = $this->controller->handle_mcp_request(
			$this->mcp_request( $this->rpc_body( 'tools/call', array( 'arguments' => array() ) ) )
		);

		$data = $response->get_data();
		$this->assertSame( -32603, $data['error']['code'] );
		$this->assertSame( 400, $data['error']['data']['status'] );
	}

	public function test_tools_call_invalid_arguments_type(): void {
		$response = $this->controller->handle_mcp_request(
			$this->mcp_request(
				$this->rpc_body(
					'tools/call',
					array(
						'name'      => 'ai_analyze_image',
						'arguments' => 'not-an-object',
					)
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( -32603, $data['error']['code'] );
		$this->assertSame( 400, $data['error']['data']['status'] );
	}

	// ─── Other routed methods ───────────────────────────────────────

	public function test_prompts_list_empty_without_assistant(): void {
		$response = $this->controller->handle_mcp_request(
			$this->mcp_request( $this->rpc_body( 'prompts/list' ) )
		);

		$this->assertSame( array(), $response->get_data()['result']['prompts'] );
	}

	public function test_resources_list_empty(): void {
		$response = $this->controller->handle_mcp_request(
			$this->mcp_request( $this->rpc_body( 'resources/list' ) )
		);

		$this->assertSame( array(), $response->get_data()['result']['resources'] );
	}

	public function test_completion_requires_ref(): void {
		$response = $this->controller->handle_mcp_request(
			$this->mcp_request( $this->rpc_body( 'completion/complete', array() ) )
		);

		$data = $response->get_data();
		$this->assertSame( -32603, $data['error']['code'] );
		$this->assertSame( 400, $data['error']['data']['status'] );
	}

	public function test_logging_set_level(): void {
		$response = $this->controller->handle_mcp_request(
			$this->mcp_request( $this->rpc_body( 'logging/setLevel', array( 'level' => 'debug' ) ) )
		);

		$this->assertEquals( new \stdClass(), $response->get_data()['result'] );

		$response = $this->controller->handle_mcp_request(
			$this->mcp_request( $this->rpc_body( 'logging/setLevel', array( 'level' => 'bogus' ) ) )
		);
		$this->assertSame( -32603, $response->get_data()['error']['code'] );
	}

	// ─── CORS ───────────────────────────────────────────────────────

	public function test_cors_default_origin_is_site_url(): void {
		$response = new \WP_REST_Response();
		$this->controller->add_cors_headers( $response );

		$headers = $response->get_headers();
		$this->assertSame( \get_site_url(), $headers['Access-Control-Allow-Origin'] );
		$this->assertSame( 'GET, POST, OPTIONS', $headers['Access-Control-Allow-Methods'] );
		$this->assertStringContainsString( 'Mcp-Method', $headers['Access-Control-Allow-Headers'] );
		$this->assertSame( 'Mcp-Method, Mcp-Name, MCP-Protocol-Version', $headers['Access-Control-Expose-Headers'] );
		$this->assertSame( '3600', $headers['Access-Control-Max-Age'] );
	}

	public function test_cors_star_origin_from_settings(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			\update_option( 'wp_mcp_ai_settings', array( 'cors_allow_origin' => 'star' ) );
			\WP_MCP_AI_Admin_Settings::reset_settings_cache();
		} else {
			\update_option( 'nvoos_content_graph_settings', array( 'cors_allow_origin' => 'star' ) );
		}

		$response = new \WP_REST_Response();
		$this->controller->add_cors_headers( $response );

		$this->assertSame( '*', $response->get_headers()['Access-Control-Allow-Origin'] );
	}

	public function test_cors_origin_filter_override(): void {
		\add_filter(
			'wp_mcp_ai_cors_allow_origin',
			static function () {
				return 'https://example.com';
			}
		);

		$response = new \WP_REST_Response();
		$this->controller->add_cors_headers( $response );

		$this->assertSame( 'https://example.com', $response->get_headers()['Access-Control-Allow-Origin'] );
	}

	public function test_options_preflight(): void {
		$request  = new \WP_REST_Request( 'OPTIONS', '/mcp-ai/v1/mcp' );
		$response = $this->controller->handle_mcp_options( $request );

		$this->assertSame( 204, $response->get_status() );
		$this->assertSame( \get_site_url(), $response->get_headers()['Access-Control-Allow-Origin'] );
	}

	// ─── Permissions ────────────────────────────────────────────────

	public function test_permission_gates(): void {
		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );

		$this->assertWPError( $this->controller->permissions_check( $request ) );
		$this->assertWPError( $this->controller->permissions_check_mcp( $request ) );
		$this->assertWPError( $this->controller->permissions_check_assistant_list( $request ) );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		\wp_set_current_user( $author );
		$this->assertTrue( $this->controller->permissions_check( $request ) );
		$this->assertTrue( $this->controller->permissions_check_mcp( $request ) );
		$this->assertTrue( $this->controller->permissions_check_assistant_list( $request ) );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );
		$this->assertWPError( $this->controller->permissions_check( $request ) );
		$this->assertWPError( $this->controller->permissions_check_mcp( $request ) );
		$this->assertWPError( $this->controller->permissions_check_assistant_list( $request ) );
	}
}
