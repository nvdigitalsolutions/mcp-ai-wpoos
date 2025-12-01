<?php
/**
 * Tests for chat transcript filtering by assistant_id.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Chat_Transcript_Filtering_Test extends WP_UnitTestCase {
	/**
	 * Administrator user ID for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * First assistant post ID.
	 *
	 * @var int
	 */
	protected $assistant_id_1;

	/**
	 * Second assistant post ID.
	 *
	 * @var int
	 */
	protected $assistant_id_2;

	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->assistant_id_1 = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant 1',
			)
		);

		$this->assistant_id_2 = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant 2',
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
	 * Test that the REST endpoint accepts assistant_id parameter for filtering sessions.
	 */
	public function test_chat_transcripts_endpoint_accepts_assistant_id_parameter() {
		$mock_client     = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();
		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		// Get the registered routes.
		$routes = rest_get_server()->get_routes();

		// Check that the chat-transcripts endpoint is registered.
		$this->assertArrayHasKey( '/mcp-ai/v1/chat-transcripts', $routes, 'Chat transcripts endpoint should be registered' );

		// Check that assistant_id is in the accepted parameters.
		$route_definition = $routes['/mcp-ai/v1/chat-transcripts'];
		$this->assertIsArray( $route_definition, 'Route definition should be an array' );
		$this->assertNotEmpty( $route_definition, 'Route definition should not be empty' );

		// Get the first route handler.
		$route_handler = $route_definition[0];
		$this->assertArrayHasKey( 'args', $route_handler, 'Route handler should have args' );
		$this->assertArrayHasKey( 'assistant_id', $route_handler['args'], 'Route should accept assistant_id parameter' );
	}

	/**
	 * Test that get_transcript_sessions method signature includes assistant_id parameter.
	 */
	public function test_get_transcript_sessions_accepts_assistant_id() {
		$mock_client     = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();
		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$method = new ReflectionMethod( $rest_controller, 'get_transcript_sessions' );

		// Check that the method accepts 4 parameters (user_id, per_page, page, assistant_id).
		$parameters = $method->getParameters();
		$this->assertCount( 4, $parameters, 'get_transcript_sessions should accept 4 parameters' );

		// Check that the 4th parameter is assistant_id.
		$assistant_id_param = $parameters[3];
		$this->assertEquals( 'assistant_id', $assistant_id_param->getName(), 'Fourth parameter should be assistant_id' );
		$this->assertTrue( $assistant_id_param->isOptional(), 'assistant_id parameter should be optional' );
		$this->assertEquals( 0, $assistant_id_param->getDefaultValue(), 'assistant_id should default to 0' );
	}

	/**
	 * Test that get_transcript_session method signature includes assistant_id parameter.
	 */
	public function test_get_transcript_session_accepts_assistant_id() {
		$mock_client     = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();
		$rest_controller = new WP_MCP_AI_REST( WP_MCP_AI_Tool_Registry::get_instance(), $mock_client );

		$method = new ReflectionMethod( $rest_controller, 'get_transcript_session' );

		// Check that the method accepts 3 parameters (user_id, session_key, assistant_id).
		$parameters = $method->getParameters();
		$this->assertCount( 3, $parameters, 'get_transcript_session should accept 3 parameters' );

		// Check that the 3rd parameter is assistant_id.
		$assistant_id_param = $parameters[2];
		$this->assertEquals( 'assistant_id', $assistant_id_param->getName(), 'Third parameter should be assistant_id' );
		$this->assertTrue( $assistant_id_param->isOptional(), 'assistant_id parameter should be optional' );
		$this->assertEquals( 0, $assistant_id_param->getDefaultValue(), 'assistant_id should default to 0' );
	}

	/**
	 * Test that widget config includes assistantId when assistant_mode is specific.
	 */
	public function test_widget_config_includes_assistant_id() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			$this->markTestSkipped( 'Elementor is not active' );
		}

		$widget = new WP_MCP_AI_Elementor_Dashboard_User_Chats_Widget();

		// Get the widget controls.
		$controls = $widget->get_controls();

		// Check that assistant_mode control exists.
		$this->assertArrayHasKey( 'assistant_mode', $controls, 'Widget should have assistant_mode control' );

		// Check that assistant_id control exists.
		$this->assertArrayHasKey( 'assistant_id', $controls, 'Widget should have assistant_id control' );

		// Check that assistant_id is conditional on assistant_mode being 'specific'.
		$assistant_id_control = $controls['assistant_id'];
		$this->assertArrayHasKey( 'condition', $assistant_id_control, 'assistant_id control should have condition' );
		$this->assertEquals(
			array( 'assistant_mode' => 'specific' ),
			$assistant_id_control['condition'],
			'assistant_id should only show when assistant_mode is specific'
		);
	}
}
