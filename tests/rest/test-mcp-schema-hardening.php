<?php
/**
 * Quirk-suite tests for the hardened MCP tools/list + tools/call surface.
 *
 * Regression tests for proposal 066 (MCP client-quirk hardening): every
 * advertised tool schema must have a root object type, hazardous schemas
 * must be skipped instead of poisoning the catalog, outputSchema-declared
 * tools must attach structuredContent, and registry mutations must signal
 * tools/list changes.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.Files.FileName,Generic.Files.OneObjectStructurePerFile.MultipleFound -- Quirk suite carries its configurable fixture tool inline so the fixture stays co-located with the hardening assertions.

/**
 * Minimal fixture tool with a configurable slug and schema.
 */
class WP_MCP_AI_Schema_Hardening_Fixture_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Fixture slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Fixture schema.
	 *
	 * @var array
	 */
	private $schema;

	/**
	 * Constructor.
	 *
	 * @param string $slug   Tool slug.
	 * @param array  $schema Tool input schema.
	 */
	public function __construct( $slug, array $schema ) {
		$this->slug   = $slug;
		$this->schema = $schema;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return 'Schema Hardening Fixture';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return 'Fixture tool used by the schema hardening quirk suite.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return $this->schema;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => 'Fixture-only tool.',
			'when_not_to_use' => 'Production — this tool exists only in tests.',
			'related_tools'   => array(),
			'notes'           => 'Registered by the schema hardening quirk suite.',
		);
	}

	/**
	 * Execute the fixture tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Canonical envelope.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		unset( $arguments, $context );
		return array( 'result' => array( 'fixture' => true ) );
	}
}

/**
 * MCP schema hardening quirk suite.
 */
class WP_MCP_AI_MCP_Schema_Hardening_Test extends WP_UnitTestCase {

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
	 * Fixture slugs registered during a test.
	 *
	 * @var array<int,string>
	 */
	protected $fixture_slugs = array();

	/**
	 * REST controller instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest_controller;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Tool_Schema_Auditor' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-schema-auditor.php';
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Schema Hardening Assistant',
			)
		);

		update_post_meta(
			$this->assistant_id,
			WP_MCP_AI_Assistant_CPT::META_TOOLS,
			array( 'nvoos_get_profile', 'get_site_health' )
		);

		// No default assistant: tools/list without an assistant_id must
		// return the full catalog so the hardening invariants can sweep
		// every registered tool (including the fixtures).
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$this->rest_controller                = new WP_MCP_AI_REST( $registry, $mock_client );
		$GLOBALS['wp_mcp_ai_rest_controller'] = $this->rest_controller;

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		foreach ( $this->fixture_slugs as $slug ) {
			$registry->unregister_tool( $slug );
		}
		$this->fixture_slugs = array();

		remove_all_filters( 'wp_mcp_ai_tools_list_schema_max_bytes' );
		remove_all_filters( 'wp_mcp_ai_tools_list_max_slug_length' );

		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Register a fixture tool and remember its slug for tear-down.
	 *
	 * @param string $slug   Tool slug.
	 * @param array  $schema Tool schema.
	 * @return void
	 */
	protected function register_fixture( $slug, array $schema ) {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( new WP_MCP_AI_Schema_Hardening_Fixture_Tool( $slug, $schema ) );
		$this->fixture_slugs[] = $slug;
	}

	/**
	 * Dispatch an MCP JSON-RPC message and return the response.
	 *
	 * @param string $method JSON-RPC method.
	 * @param array  $params Method params.
	 * @return WP_REST_Response
	 */
	protected function send_mcp( $method, array $params = array() ) {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'X-WP-MCP-AI-Internal-Diagnostic', '1' );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => $method,
					'params'  => $params,
				)
			)
		);

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Configure the Auth0 OAuth settings (profile outputSchema advertisement).
	 *
	 * @param bool $configured Whether to configure Auth0.
	 * @return void
	 */
	protected function set_auth0_configuration( $configured ) {
		$settings                   = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['auth0_domain']   = $configured ? 'test-tenant.us.auth0.com' : '';
		$settings['auth0_audience'] = $configured ? rest_url( 'mcp-ai/v1/mcp' ) : '';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
	}

	/**
	 * Every tool in a full tools/list has a root object type.
	 */
	public function test_all_listed_schemas_have_object_root_type() {
		$response = $this->send_mcp( 'tools/list' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertNotEmpty( $data['result']['tools'] );

		foreach ( $data['result']['tools'] as $tool ) {
			$this->assertArrayHasKey( 'inputSchema', $tool, $tool['name'] . ' has no inputSchema' );
			$this->assertSame(
				'object',
				$tool['inputSchema']['type'],
				$tool['name'] . ' inputSchema root type is not object'
			);
			$this->assertArrayHasKey( 'properties', $tool['inputSchema'], $tool['name'] . ' has no properties' );
		}
	}

	/**
	 * A tool whose schema lacks a root type is normalized, not dropped.
	 */
	public function test_schema_without_root_type_is_normalized() {
		$this->register_fixture(
			'fixture_untyped',
			array( 'properties' => array() )
		);

		$response = $this->send_mcp( 'tools/list' );
		$data     = $response->get_data();

		$by_name = array();
		foreach ( $data['result']['tools'] as $tool ) {
			$by_name[ $tool['name'] ] = $tool;
		}

		$this->assertArrayHasKey( 'fixture_untyped', $by_name );
		$this->assertSame( 'object', $by_name['fixture_untyped']['inputSchema']['type'] );
	}

	/**
	 * A tool with a bracketed property name is skipped from the catalog.
	 */
	public function test_bracket_property_tool_is_skipped() {
		$this->register_fixture(
			'fixture_bracket',
			array(
				'type'       => 'object',
				'properties' => array(
					'ids[]' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			)
		);

		$response = $this->send_mcp( 'tools/list' );
		$data     = $response->get_data();

		$names = wp_list_pluck( $data['result']['tools'], 'name' );

		$this->assertNotContains( 'fixture_bracket', $names );
		$this->assertContains( 'nvoos_get_profile', $names, 'Healthy tools must survive a skipped hazard.' );
	}

	/**
	 * A schema over the (filtered) byte budget is skipped from the catalog.
	 */
	public function test_over_budget_schema_is_skipped() {
		add_filter( 'wp_mcp_ai_tools_list_schema_max_bytes', '__return_zero' );

		$this->register_fixture(
			'fixture_oversized',
			array(
				'type'       => 'object',
				'properties' => array( 'note' => array( 'type' => 'string' ) ),
			)
		);

		$response = $this->send_mcp( 'tools/list' );
		$data     = $response->get_data();

		$names = wp_list_pluck( $data['result']['tools'], 'name' );

		$this->assertNotContains( 'fixture_oversized', $names );
	}

	/**
	 * A slug over the length limit is skipped from the catalog.
	 */
	public function test_over_length_slug_is_skipped() {
		$this->register_fixture(
			str_repeat( 'a', 65 ),
			array(
				'type'       => 'object',
				'properties' => array(),
			)
		);

		$response = $this->send_mcp( 'tools/list' );
		$data     = $response->get_data();

		$names = wp_list_pluck( $data['result']['tools'], 'name' );

		foreach ( $names as $name ) {
			$this->assertLessThanOrEqual( 64, strlen( $name ), 'Listed tool name exceeds 64 characters: ' . $name );
		}
	}

	/**
	 * The profile tools/call attaches structuredContent when advertised.
	 */
	public function test_profile_call_attaches_structured_content_when_advertised() {
		$this->set_auth0_configuration( true );

		$response = $this->send_mcp(
			'tools/call',
			array(
				'name'         => 'nvoos_get_profile',
				'arguments'    => array(),
				'assistant_id' => $this->assistant_id,
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'content', $data['result'] );
		$this->assertArrayHasKey( 'structuredContent', $data['result'] );
		$this->assertNotEmpty( $data['result']['structuredContent']['id'] );

		$keys = array_keys( $data['result']['structuredContent'] );
		sort( $keys );
		$this->assertSame( array( 'email', 'id', 'name', 'nickname' ), $keys );
	}

	/**
	 * The profile tools/call stays text-only when no outputSchema is advertised.
	 */
	public function test_profile_call_omits_structured_content_without_auth0() {
		$this->set_auth0_configuration( false );

		$response = $this->send_mcp(
			'tools/call',
			array(
				'name'         => 'nvoos_get_profile',
				'arguments'    => array(),
				'assistant_id' => $this->assistant_id,
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'content', $data['result'] );
		$this->assertArrayNotHasKey( 'structuredContent', $data['result'] );
	}

	/**
	 * Registry mutations fire the tools/list change signal (throttled).
	 */
	public function test_registry_change_signal_fires_once_per_request() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$count    = 0;

		// did_action() counters persist across tests in a single PHPUnit
		// process (wp-phpunit does not reset $wp_actions), so clear this
		// hook's counter to simulate a fresh request.
		unset( $GLOBALS['wp_actions']['wp_mcp_ai_mcp_tools_list_changed'] );

		$counter = static function () use ( &$count ) {
			++$count;
		};
		add_action( 'wp_mcp_ai_mcp_tools_list_changed', $counter );

		$registry->notify_tools_list_changed();
		$registry->notify_tools_list_changed();

		$this->assertSame( 1, $count, 'The change signal must be throttled to once per request.' );

		remove_action( 'wp_mcp_ai_mcp_tools_list_changed', $counter );
	}
}
