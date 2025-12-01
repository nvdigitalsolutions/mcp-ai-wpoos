<?php
/**
 * Tests that the tools endpoint normalises incoming tool identifiers.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_REST_Tool_Slug_Normalization_Test extends WP_Test_REST_TestCase {
	/**
	 * Registered stub tool instance.
	 *
	 * @var WP_MCP_AI_REST_Tool_Slug_Normalization_Test_Stub_Tool
	 */
	protected $stub_tool;

	/**
	 * Set up the test environment.
	 */
	public function set_up() {
		parent::set_up();

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$this->stub_tool = new WP_MCP_AI_REST_Tool_Slug_Normalization_Test_Stub_Tool();
		$registry->register_tool( $this->stub_tool );

		$this->bootstrap_rest_controller();
	}

	/**
	 * Clean up the environment between tests.
	 */
	public function tear_down() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->unregister_tool( 'demo_tool_action' );

		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
			unset( $GLOBALS['wp_mcp_ai_rest_controller'] );
		}

		parent::tear_down();
	}

	/**
	 * Tool names provided in camelCase should be normalised to the registered slug.
	 */
	public function test_camel_case_tool_name_is_normalised() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Tool Normalisation Assistant',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( 'demo_tool_action' ) );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'tool', 'demoToolAction' );
		$request->set_param( 'arguments', array( 'value' => 'ok' ) );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'demo_tool_action', $data['tool'] );
		$this->assertSame( array( 'value' => 'ok' ), $this->stub_tool->last_arguments );
	}

	/**
	 * Bootstrap the REST controller for tests.
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

/**
 * Minimal stub tool used to assert slug normalisation.
 */
class WP_MCP_AI_REST_Tool_Slug_Normalization_Test_Stub_Tool implements WP_MCP_AI_Tool_Interface {
	/**
	 * Captured arguments from the last execute call.
	 *
	 * @var array|null
	 */
	public $last_arguments = null;

	/** {@inheritdoc} */
	public function get_slug() {
		return 'demo_tool_action';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return 'Demo Tool';
	}

	/** {@inheritdoc} */
	public function get_description() {
		return 'Demo tool description.';
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'value' => array(
					'type' => 'string',
				),
			),
		);
	}

	/** {@inheritdoc} */
	public function execute( array $arguments = array(), array $context = array() ) {
		$this->last_arguments = $arguments;

		return array(
			'status' => 'ok',
			'args'   => $arguments,
		);
	}
}
