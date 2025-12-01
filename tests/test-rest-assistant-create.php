<?php
/**
 * Tests for the assistant POST REST endpoint.
 */
class WP_MCP_AI_REST_Assistant_Create_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID used for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Bootstrap REST controller.
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );
	}

	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Bootstrap the REST controller with dependencies.
	 *
	 * @param WP_MCP_AI_Language_Model_Router $mock_client Mock LM router.
	 */
	private function bootstrap_rest_controller( $mock_client ) {
		$this->registry = wp_mcp_ai_get_registry();
		$container      = wp_mcp_ai_container();

		$authenticator = new WP_MCP_AI_REST_Authenticator();
		$validator     = new WP_MCP_AI_REST_Validator();

		$main_controller = new WP_MCP_AI_REST( $this->registry, $mock_client, $authenticator, $validator );
		$mcp_controller  = new WP_MCP_AI_REST_MCP_Controller( $main_controller, $authenticator, $validator );

		$mcp_controller->register_routes();
	}

	/**
	 * Test that POST endpoint is blocked when setting is disabled.
	 */
	public function test_create_blocked_when_setting_disabled() {
		// Ensure the setting is disabled (default).
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['rest_enable_assistant_create'] = false;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'title' => 'Test Assistant' ) ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 403, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data );
		$this->assertSame( 'rest_assistant_create_disabled', $data['code'] );
	}

	/**
	 * Test that POST endpoint works when setting is enabled.
	 */
	public function test_create_succeeds_when_setting_enabled() {
		// Enable the setting.
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['rest_enable_assistant_create'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'title'       => 'Test Assistant',
					'description' => 'A test assistant created via REST API',
					'provider'    => 'openai',
					'model'       => 'gpt-4',
					'temperature' => 0.7,
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertArrayHasKey( 'title', $data );
		$this->assertSame( 'Test Assistant', $data['title'] );
		$this->assertArrayHasKey( 'provider', $data );
		$this->assertSame( 'openai', $data['provider'] );
		$this->assertArrayHasKey( 'model', $data );
		$this->assertSame( 'gpt-4', $data['model'] );
		$this->assertArrayHasKey( 'temperature', $data );
		$this->assertSame( 0.7, $data['temperature'] );

		// Verify the assistant was actually created in the database.
		$assistant_id = $data['id'];
		$post         = get_post( $assistant_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( WP_MCP_AI_Assistant_CPT::POST_TYPE, $post->post_type );
		$this->assertSame( 'Test Assistant', $post->post_title );
		$this->assertSame( 'A test assistant created via REST API', $post->post_content );

		// Verify metadata was saved.
		$this->assertSame( 'openai', get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_PROVIDER, true ) );
		$this->assertSame( 'gpt-4', get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_MODEL, true ) );
		$this->assertSame( '0.7', get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TEMPERATURE, true ) );
	}

	/**
	 * Test creating assistant with minimal data (only title).
	 */
	public function test_create_with_minimal_data() {
		// Enable the setting.
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['rest_enable_assistant_create'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'title' => 'Minimal Assistant' ) ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertSame( 'Minimal Assistant', $data['title'] );

		// Verify the assistant was created.
		$assistant_id = $data['id'];
		$post         = get_post( $assistant_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'publish', $post->post_status );
	}

	/**
	 * Test creating assistant with tools.
	 */
	public function test_create_with_tools() {
		// Enable the setting.
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['rest_enable_assistant_create'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'title' => 'Assistant with Tools',
					'tools' => array( 'search_content', 'save_post', 'web_search' ),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'tools', $data );
		$this->assertIsArray( $data['tools'] );
		$this->assertContains( 'search_content', $data['tools'] );
		$this->assertContains( 'save_post', $data['tools'] );
		$this->assertContains( 'web_search', $data['tools'] );

		// Verify tools metadata was saved.
		$assistant_id = $data['id'];
		$saved_tools  = get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, true );
		$this->assertIsArray( $saved_tools );
		$this->assertContains( 'search_content', $saved_tools );
		$this->assertContains( 'save_post', $saved_tools );
		$this->assertContains( 'web_search', $saved_tools );
	}

	/**
	 * Test creating assistant with system prompt.
	 */
	public function test_create_with_system_prompt() {
		// Enable the setting.
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['rest_enable_assistant_create'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$system_prompt = 'You are a helpful WordPress assistant.';

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'title'         => 'Assistant with Prompt',
					'system_prompt' => $system_prompt,
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$data         = $response->get_data();
		$assistant_id = $data['id'];

		// Verify system prompt was saved.
		$saved_prompt = get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SYSTEM_PROMPT, true );
		$this->assertSame( $system_prompt, $saved_prompt );
	}

	/**
	 * Test creating assistant with custom status.
	 */
	public function test_create_with_draft_status() {
		// Enable the setting.
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['rest_enable_assistant_create'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'title'  => 'Draft Assistant',
					'status' => 'draft',
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'draft', $data['status'] );

		// Verify post status.
		$assistant_id = $data['id'];
		$post         = get_post( $assistant_id );
		$this->assertSame( 'draft', $post->post_status );
	}

	/**
	 * Test that POST endpoint returns 400 for missing title.
	 */
	public function test_create_returns_400_for_missing_title() {
		// Enable the setting.
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['rest_enable_assistant_create'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'description' => 'No title provided' ) ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data );
		$this->assertSame( 'rest_missing_title', $data['code'] );
	}

	/**
	 * Test that POST endpoint requires authentication.
	 */
	public function test_create_requires_authentication() {
		// Enable the setting.
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['rest_enable_assistant_create'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Clear current user (unauthenticated).
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'title' => 'Test Assistant' ) ) );
		// No authentication headers.

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Test that wp_mcp_ai_rest_assistant_created action fires.
	 */
	public function test_create_fires_action_hook() {
		// Enable the setting.
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['rest_enable_assistant_create'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$action_fired   = false;
		$action_id      = null;
		$action_request = null;

		add_action(
			'wp_mcp_ai_rest_assistant_created',
			function ( $assistant_id, $request ) use ( &$action_fired, &$action_id, &$action_request ) {
				$action_fired   = true;
				$action_id      = $assistant_id;
				$action_request = $request;
			},
			10,
			2
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'title' => 'Test Assistant' ) ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertTrue( $action_fired, 'wp_mcp_ai_rest_assistant_created action should fire' );
		$this->assertIsInt( $action_id );
		$this->assertGreaterThan( 0, $action_id );
		$this->assertInstanceOf( WP_REST_Request::class, $action_request );
	}

	/**
	 * Test that Location header is set correctly.
	 */
	public function test_create_sets_location_header() {
		// Enable the setting.
		$settings                                 = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['rest_enable_assistant_create'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'title' => 'Test Assistant' ) ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Location', $headers );

		$data         = $response->get_data();
		$assistant_id = $data['id'];
		$expected_url = rest_url( 'mcp-ai/v1/assistants/' . $assistant_id );
		$this->assertSame( $expected_url, $headers['Location'] );
	}
}
