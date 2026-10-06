<?php
/**
 * Tests for the OOS-engine chat path tool-slug pipeline parity.
 *
 * `handle_chat_request_oos()` must apply the same tool-slug pipeline as the
 * legacy `build_tools_payload()` — the attention filter (noop below its
 * threshold), then the `wp_mcp_ai_chat_effective_tools` filter that appends
 * dynamically registered tools (MCP App bridge tools, granted toolkit MCP
 * server tools) that cannot be selected in the Tools metabox.
 *
 * @package WP_MCP_AI
 */
class Test_OOS_Chat_Effective_Tools extends WP_UnitTestCase {

	/**
	 * Captured provider request body.
	 *
	 * @var string
	 */
	private $captured_body = '';

	/**
	 * Set up — enable the OOS engine, seed settings, intercept HTTP.
	 */
	public function set_up() {
		parent::set_up();

		$settings                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['enable_oos_engine'] = 1;
		$settings['openai_api_key']    = 'sk-test-oos';
		$settings['default_provider']  = 'openai';
		$settings['default_model']     = 'gpt-4o-mini';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$this->captured_body = '';

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( (string) $url, 'chat/completions' ) ) {
					$this->captured_body = isset( $args['body'] ) ? (string) $args['body'] : '';

					return array(
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => wp_json_encode(
							array(
								'id'      => 'chatcmpl-oos-test',
								'object'  => 'chat.completion',
								'created' => time(),
								'model'   => 'gpt-4o-mini',
								'choices' => array(
									array(
										'index'         => 0,
										'message'       => array(
											'role'    => 'assistant',
											'content' => 'Hello from OOS.',
										),
										'finish_reason' => 'stop',
									),
								),
								'usage'   => array(
									'prompt_tokens'     => 10,
									'completion_tokens' => 5,
									'total_tokens'      => 15,
								),
							)
						),
					);
				}

				// Swallow any other outbound request (loopback cron spawns, etc.)
				// so the test never touches the real network.
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => '',
				);
			},
			10,
			3
		);
	}

	/**
	 * Tear down — restore settings and drop the test filters.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_chat_effective_tools' );
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * The OOS engine must send dynamically appended effective-tools slugs to
	 * the provider, deduplicated.
	 */
	public function test_oos_chat_includes_effective_tools_filter_slugs() {
		$assistant_id = $this->create_assistant_post();
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_PROVIDER, 'openai' );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_MODEL, 'gpt-4o-mini' );

		// Append the same dynamic slug twice — the pipeline must dedupe.
		add_filter(
			'wp_mcp_ai_chat_effective_tools',
			static function ( $slugs ) {
				$slugs[] = 'count_tokens';
				$slugs[] = 'count_tokens';
				return $slugs;
			}
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->bootstrap_rest_controller();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame(
			200,
			$response->get_status(),
			'Unexpected OOS chat status: ' . wp_json_encode( $response->get_data() )
		);

		$this->assertNotSame( '', $this->captured_body, 'The OOS engine must reach the provider.' );
		$payload = json_decode( $this->captured_body, true );
		$this->assertIsArray( $payload, 'Provider body must be JSON: ' . $this->captured_body );
		$this->assertArrayHasKey( 'tools', $payload );

		$names = wp_list_pluck( wp_list_pluck( $payload['tools'], 'function' ), 'name' );
		$this->assertContains( 'count_tokens', $names, 'Appended dynamic slugs must reach the provider.' );
		$this->assertSame(
			1,
			count( array_keys( $names, 'count_tokens', true ) ),
			'Appended slugs must be deduplicated.'
		);
	}

	/**
	 * With no effective-tools additions, the OOS provider payload carries no
	 * tools for an assistant that has none configured.
	 */
	public function test_oos_chat_sends_no_tools_without_effective_filter_additions() {
		$assistant_id = $this->create_assistant_post();
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_PROVIDER, 'openai' );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_MODEL, 'gpt-4o-mini' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->bootstrap_rest_controller();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame(
			200,
			$response->get_status(),
			'Unexpected OOS chat status: ' . wp_json_encode( $response->get_data() )
		);

		$this->assertNotSame( '', $this->captured_body, 'The OOS engine must reach the provider.' );
		$payload = json_decode( $this->captured_body, true );
		$this->assertIsArray( $payload, 'Provider body must be JSON: ' . $this->captured_body );
		$this->assertTrue(
			empty( $payload['tools'] ),
			'No tools should be sent when the assistant has none and no filter appends any.'
		);
	}

	/**
	 * Prepare the REST controller instance for testing.
	 */
	protected function bootstrap_rest_controller() {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$mock     = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $mock );

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Create a published assistant post for testing.
	 *
	 * @return int
	 */
	protected function create_assistant_post() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Test OOS Assistant',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $assistant_id );
		$this->assertNotEmpty( $assistant_id );

		return $assistant_id;
	}
}
