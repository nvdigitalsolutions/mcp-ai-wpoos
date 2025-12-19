<?php
/**
 * Tests for auto-enabled utility tools (speech and transcribe).
 *
 * Verifies that generate_openai_speech and transcribe_openai_audio tools
 * are automatically available for all assistants without explicit configuration.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for auto-enabled utility tools.
 */
class WP_MCP_AI_Auto_Enabled_Utility_Tools_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID used for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Test assistant ID without utility tools explicitly configured.
	 *
	 * @var int
	 */
	protected $assistant_id;

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

		// Get registry instance.
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Create a test assistant WITHOUT speech/transcribe tools in config.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant Without Utility Tools',
			)
		);

		// Configure assistant with only basic tools (not speech/transcribe).
		$config = array(
			'tools' => array(
				'get_current_date_time',
			),
		);
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_assistant_config', $config );
	}

	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Bootstrap the REST controller for testing.
	 *
	 * @param WP_MCP_AI_Language_Model_Router $client Mock client.
	 */
	protected function bootstrap_rest_controller( $client ) {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $this->registry, $client );

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Test that generate_openai_speech tool is auto-enabled for all assistants.
	 *
	 * The assistant was configured without this tool, but it should still
	 * be available when requested via the /tools endpoint.
	 */
	public function test_generate_openai_speech_is_auto_enabled() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		// Attempt to execute the speech tool.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'tool', 'generate_openai_speech' );
		$request->set_param(
			'arguments',
			array(
				'text' => 'Test speech',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		// Should NOT return 403 forbidden error.
		$this->assertNotEquals( 403, $response->get_status(), 'Speech tool should be auto-enabled and not return 403' );

		// The tool might fail for other reasons (missing API key, etc.),
		// but it should not be blocked due to permissions.
		if ( $response->get_status() >= 400 ) {
			$data = $response->get_data();
			$this->assertNotEquals(
				'wp_mcp_ai_tool_forbidden',
				isset( $data['code'] ) ? $data['code'] : '',
				'Speech tool should not be forbidden for any assistant'
			);
		}
	}

	/**
	 * Test that transcribe_openai_audio tool is auto-enabled for all assistants.
	 *
	 * The assistant was configured without this tool, but it should still
	 * be available when requested via the /tools endpoint.
	 */
	public function test_transcribe_openai_audio_is_auto_enabled() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		// Attempt to execute the transcribe tool.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'tool', 'transcribe_openai_audio' );
		$request->set_param(
			'arguments',
			array(
				'file_id' => 'test-file-id',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		// Should NOT return 403 forbidden error.
		$this->assertNotEquals( 403, $response->get_status(), 'Transcribe tool should be auto-enabled and not return 403' );

		// The tool might fail for other reasons (missing file, API key, etc.),
		// but it should not be blocked due to permissions.
		if ( $response->get_status() >= 400 ) {
			$data = $response->get_data();
			$this->assertNotEquals(
				'wp_mcp_ai_tool_forbidden',
				isset( $data['code'] ) ? $data['code'] : '',
				'Transcribe tool should not be forbidden for any assistant'
			);
		}
	}

	/**
	 * Test that non-utility tools still require explicit configuration.
	 *
	 * This ensures our auto-enable logic doesn't accidentally allow ALL tools.
	 */
	public function test_non_utility_tools_still_require_configuration() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		// Attempt to execute a tool that is NOT auto-enabled and NOT in config.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'tool', 'create_post' );
		$request->set_param(
			'arguments',
			array(
				'title'   => 'Test Post',
				'content' => 'Test content',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		// SHOULD return 403 forbidden error because create_post is not auto-enabled.
		$this->assertEquals(
			403,
			$response->get_status(),
			'Non-utility tools should still require explicit configuration'
		);

		$data = $response->get_data();
		$this->assertEquals(
			'wp_mcp_ai_tool_forbidden',
			isset( $data['code'] ) ? $data['code'] : '',
			'Should return forbidden error code for non-configured tools'
		);
	}
}
