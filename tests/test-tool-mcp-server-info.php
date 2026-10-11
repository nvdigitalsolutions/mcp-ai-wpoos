<?php
/**
 * Tests for the mcp_server_info identity tool and the list_mcp_tools
 * `_meta` discovery block.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Tool_MCP_Server_Info_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Create a test assistant that allows the identity tools.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Identity Assistant',
			)
		);

		update_post_meta(
			$this->assistant_id,
			WP_MCP_AI_Assistant_CPT::META_TOOLS,
			array(
				'mcp_server_info',
				'list_mcp_tools',
			)
		);
		update_post_meta( $this->assistant_id, WP_MCP_AI_Assistant_CPT::META_PROVIDER, 'openai' );
		update_post_meta( $this->assistant_id, WP_MCP_AI_Assistant_CPT::META_MODEL, 'gpt-4' );

		// Set as default assistant so unscoped MCP requests resolve.
		$settings                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['default_assistant'] = $this->assistant_id;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Resolve the mcp_server_info tool from the registry.
	 *
	 * @return WP_MCP_AI_Tool_Interface
	 */
	private function get_identity_tool() {
		return WP_MCP_AI_Tool_Registry::get_instance()->get_tool( 'mcp_server_info' );
	}

	/**
	 * Resolve the list_mcp_tools tool from the registry.
	 *
	 * @return WP_MCP_AI_Tool_Interface
	 */
	private function get_list_tool() {
		return WP_MCP_AI_Tool_Registry::get_instance()->get_tool( 'list_mcp_tools' );
	}

	/**
	 * Test the tool is registered with the discovery toolkit and read capability.
	 */
	public function test_tool_is_registered_with_discovery_toolkit() {
		$tool = $this->get_identity_tool();

		$this->assertNotNull( $tool, 'mcp_server_info should be registered' );
		$this->assertSame( 'mcp_server_info', $tool->get_slug() );
		$this->assertSame( 'read', $tool->get_required_capability() );
		$this->assertSame( 'discovery', $tool->get_definition()['toolkit'] );

		$schema = $tool->get_parameters_schema();
		$this->assertSame( 'object', $schema['type'] );
	}

	/**
	 * Test execute() denies unauthenticated callers.
	 */
	public function test_execute_denied_without_read_capability() {
		wp_set_current_user( 0 );

		$result = $this->get_identity_tool()->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * Test execute() returns the canonical envelope with only non-sensitive identity data.
	 */
	public function test_execute_returns_canonical_identity_envelope() {
		$result = $this->get_identity_tool()->execute( array(), array( 'user_id' => $this->admin_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'], 'Success envelope expected' );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'server', $result );

		$this->assertSame( 'NV oOS', $result['server']['name'] );
		$this->assertNotEmpty( $result['server']['version'] );
		$this->assertContains( $result['server']['mode'], array( 'base', 'complete' ), true );
		$this->assertIsBool( $result['server']['pro_active'] );
		$this->assertNotEmpty( $result['server']['site_name'] );
		$this->assertNotEmpty( $result['server']['site_url'] );
		$this->assertStringContainsString( 'mcp-ai/v1/mcp', $result['server']['mcp_endpoint'] );

		// Security posture: no secrets, tokens, emails, or user data.
		$this->assertArrayNotHasKey( 'admin_email', $result );
		$this->assertArrayNotHasKey( 'admin_email', $result['server'] );
		$this->assertArrayNotHasKey( 'token', $result );
		$this->assertArrayNotHasKey( 'bridge_name', $result );
	}

	/**
	 * Test the client-supplied bridge name is sanitised and truncated in the identity payload.
	 */
	public function test_bridge_name_is_sanitized_and_truncated() {
		$payload = WP_MCP_AI_Tool_MCP_Server_Info::build_server_identity(
			array(
				'bridge_name' => '<script>alert(1)</script>nvoos-ideabits-gateway-basic' . str_repeat( 'x', 300 ),
			)
		);

		$this->assertArrayHasKey( 'bridge_name', $payload );
		$this->assertStringNotContainsString( '<script>', $payload['bridge_name'] );
		$this->assertLessThanOrEqual( WP_MCP_AI_Tool_MCP_Server_Info::BRIDGE_NAME_MAX_LENGTH, strlen( $payload['bridge_name'] ) );
		$this->assertStringContainsString( 'nvoos-ideabits-gateway-basic', $payload['bridge_name'] );
	}

	/**
	 * Test build_server_identity omits the bridge name when the context lacks it.
	 */
	public function test_identity_omits_bridge_name_without_context() {
		$payload = WP_MCP_AI_Tool_MCP_Server_Info::build_server_identity( array() );

		$this->assertArrayNotHasKey( 'bridge_name', $payload );
		$this->assertArrayHasKey( 'server', $payload );
	}

	/**
	 * Test list_mcp_tools includes the _meta identity block by default.
	 */
	public function test_list_mcp_tools_includes_meta_by_default() {
		$result = $this->get_list_tool()->execute(
			array(
				'limit'           => 5,
				'include_schemas' => false,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( '_meta', $result, 'Identity _meta block should be present by default' );
		$this->assertArrayHasKey( 'server', $result['_meta'] );
		$this->assertSame( 'NV oOS', $result['_meta']['server']['name'] );
	}

	/**
	 * Test list_mcp_tools omits the _meta block when include_meta is false.
	 */
	public function test_list_mcp_tools_include_meta_false_omits_meta() {
		$result = $this->get_list_tool()->execute(
			array(
				'limit'           => 5,
				'include_schemas' => false,
				'include_meta'    => false,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( '_meta', $result, 'Identity _meta block should be omitted when include_meta=false' );
	}

	/**
	 * Test the lazy-load path (tool_slug) also carries the _meta block.
	 */
	public function test_list_mcp_tools_lazy_load_includes_meta() {
		$result = $this->get_list_tool()->execute(
			array( 'tool_slug' => 'mcp_server_info' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'mcp_server_info', $result['name'] );
		$this->assertArrayHasKey( '_meta', $result );
		$this->assertSame( 'NV oOS', $result['_meta']['server']['name'] );
	}

	/**
	 * Test the end-to-end MCP tools/call path surfaces the X-MCP-Bridge-Name
	 * header in the mcp_server_info result.
	 */
	public function test_mcp_tools_call_surfaces_bridge_name_from_header() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest_controller = new WP_MCP_AI_REST( $registry, $mock_client );

		rest_get_server();
		do_action( 'rest_api_init' );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'X-WP-MCP-AI-Internal-Diagnostic', '1' );
		$request->set_header( 'X-MCP-Bridge-Name', 'nvoos-ideabits-gateway-basic' );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 21,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'mcp_server_info',
						'arguments' => array(),
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'content', $data['result'] );

		$tool_result = json_decode( $data['result']['content'][0]['text'], true );

		$this->assertIsArray( $tool_result, 'Tool result should decode from MCP text content' );
		$this->assertTrue( $tool_result['success'] );
		$this->assertSame( 'nvoos-ideabits-gateway-basic', $tool_result['bridge_name'] );
		$this->assertSame( 'NV oOS', $tool_result['server']['name'] );

		unset( $rest_controller );
	}
}
