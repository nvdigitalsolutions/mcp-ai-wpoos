<?php
/**
 * Tests for agentic workflow with different tool types.
 *
 * Tests tool execution with various tool categories:
 * - Image generation tools (generate_openai_image, generate_gemini_image)
 * - Speech tools (generate_openai_speech, transcribe_openai_audio)
 * - Data retrieval tools (search_content, get_recent_posts)
 * - Content creation tools (save_post, create_woo_product)
 * - External API tools (run_crawl4ai_job, quickbooks_report)
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test agentic workflow with various tool types.
 */
class WP_MCP_AI_Test_Agentic_Workflow_Tool_Types extends WP_UnitTestCase {

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test assistant.
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Tool Types Test Assistant',
			)
		);

		// Create admin user.
		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );

		// Initialize tool registry.
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->registry->init();
	}

	/**
	 * Test image generation tool execution.
	 */
	public function test_image_generation_tool_execution() {
		// Register mock image tool.
		$image_tool = $this->create_mock_image_tool();
		$this->registry->register_tool( $image_tool );

		$mock_client  = $this->create_mock_client_with_tool_call( 'generate_test_image' );
		$mock_router  = $this->create_mock_router_with_client( $mock_client );
		$chat_service = $this->create_chat_service( $mock_router );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Generate an image of a cat.',
			),
		);

		$response = $chat_service->process_chat_request(
			$this->assistant_id,
			$messages,
			array(),
			array(),
			array( 'save_transcript' => false ),
			$this->user_id
		);

		$this->assertArrayHasKey( 'tool_results', $response );
		$tool_result = $response['tool_results'][0];

		$content = json_decode( $tool_result['content'], true );
		$this->assertArrayHasKey( 'url', $content );
		$this->assertArrayHasKey( 'attachment_id', $content );
	}

	/**
	 * Test data retrieval tool execution.
	 */
	public function test_data_retrieval_tool_execution() {
		// Create some test posts.
		$post_ids = $this->factory->post->create_many( 3 );

		$mock_client  = $this->create_mock_client_with_tool_call( 'get_recent_posts' );
		$mock_router  = $this->create_mock_router_with_client( $mock_client );
		$chat_service = $this->create_chat_service( $mock_router );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Get recent posts.',
			),
		);

		$response = $chat_service->process_chat_request(
			$this->assistant_id,
			$messages,
			array(),
			array(),
			array( 'save_transcript' => false ),
			$this->user_id
		);

		$this->assertArrayHasKey( 'tool_results', $response );
		$tool_result = $response['tool_results'][0];

		$content = json_decode( $tool_result['content'], true );
		$this->assertIsArray( $content );
	}

	/**
	 * Test content creation tool execution.
	 */
	public function test_content_creation_tool_execution() {
		$mock_client  = $this->create_mock_client_with_tool_call( 'save_post' );
		$mock_router  = $this->create_mock_router_with_client( $mock_client );
		$chat_service = $this->create_chat_service( $mock_router );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Create a blog post about WordPress.',
			),
		);

		$response = $chat_service->process_chat_request(
			$this->assistant_id,
			$messages,
			array(),
			array(),
			array( 'save_transcript' => false ),
			$this->user_id
		);

		$this->assertArrayHasKey( 'tool_results', $response );
	}

	/**
	 * Test mixed tool types in single request.
	 */
	public function test_mixed_tool_types_execution() {
		$call_count = 0;

		$mock_client = $this->getMockBuilder( 'WP_MCP_AI_OpenAI_Client' )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$mock_client
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages ) use ( &$call_count ) {
					++$call_count;

					if ( 1 === $call_count ) {
						// First call: request multiple different tool types.
						return array(
							'id'      => 'test-mixed-tools',
							'choices' => array(
								array(
									'message' => array(
										'role'       => 'assistant',
										'content'    => 'Getting information...',
										'tool_calls' => array(
											array(
												'id'       => 'call_posts_001',
												'type'     => 'function',
												'function' => array(
													'name' => 'get_recent_posts',
													'arguments' => wp_json_encode( array( 'limit' => 5 ) ),
												),
											),
											array(
												'id'       => 'call_time_002',
												'type'     => 'function',
												'function' => array(
													'name' => 'get_current_time',
													'arguments' => '{}',
												),
											),
										),
									),
								),
							),
						);
					}

					return $this->create_simple_response( 'Here is the information.' );
				}
			);

		$mock_router  = $this->create_mock_router_with_client( $mock_client );
		$chat_service = $this->create_chat_service( $mock_router );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Get recent posts and current time.',
			),
		);

		$response = $chat_service->process_chat_request(
			$this->assistant_id,
			$messages,
			array(),
			array(),
			array( 'save_transcript' => false ),
			$this->user_id
		);

		$this->assertArrayHasKey( 'tool_results', $response );
		$this->assertCount( 2, $response['tool_results'], 'Should have 2 tool results' );
	}

	/**
	 * Test tool result structure for different tool types.
	 */
	public function test_tool_result_structure_consistency() {
		$tools_to_test = array(
			'get_current_time',
			'get_site_summary',
			'search_content',
		);

		foreach ( $tools_to_test as $tool_name ) {
			$mock_client  = $this->create_mock_client_with_tool_call( $tool_name );
			$mock_router  = $this->create_mock_router_with_client( $mock_client );
			$chat_service = $this->create_chat_service( $mock_router );

			$messages = array(
				array(
					'role'    => 'user',
					'content' => 'Execute ' . $tool_name,
				),
			);

			$response = $chat_service->process_chat_request(
				$this->assistant_id,
				$messages,
				array(),
				array(),
				array( 'save_transcript' => false ),
				$this->user_id
			);

			$this->assertArrayHasKey( 'tool_results', $response, "Tool $tool_name should return results" );

			if ( ! empty( $response['tool_results'] ) ) {
				$tool_result = $response['tool_results'][0];

				// All tools should return consistent structure.
				$this->assertEquals( 'tool', $tool_result['role'], "Tool $tool_name should have role 'tool'" );
				$this->assertArrayHasKey( 'tool_call_id', $tool_result, "Tool $tool_name should have tool_call_id" );
				$this->assertArrayHasKey( 'content', $tool_result, "Tool $tool_name should have content" );

				// Content should be valid JSON.
				$content = json_decode( $tool_result['content'], true );
				$this->assertNotNull( $content, "Tool $tool_name content should be valid JSON" );
			}
		}
	}

	/**
	 * Create mock language model router.
	 *
	 * @return WP_MCP_AI_Language_Model_Router Mock router.
	 */
	protected function create_mock_router() {
		return $this->getMockBuilder( 'WP_MCP_AI_Language_Model_Router' )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_client' ) )
			->getMock();
	}

	/**
	 * Create mock router with client.
	 *
	 * @param object $client Mock client.
	 * @return WP_MCP_AI_Language_Model_Router Mock router.
	 */
	protected function create_mock_router_with_client( $client ) {
		$mock_router = $this->create_mock_router();
		$mock_router
			->method( 'get_client' )
			->willReturn( $client );

		return $mock_router;
	}

	/**
	 * Create mock client with tool call.
	 *
	 * @param string $tool_name Tool name.
	 * @return object Mock client.
	 */
	protected function create_mock_client_with_tool_call( $tool_name = 'get_current_time' ) {
		$mock_client = $this->getMockBuilder( 'WP_MCP_AI_OpenAI_Client' )
			->disableOriginalConstructor()
			->onlyMethods( array( 'create_chat_completion' ) )
			->getMock();

		$call_count = 0;
		$mock_client
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages ) use ( &$call_count, $tool_name ) {
					++$call_count;

					if ( 1 === $call_count ) {
						return $this->create_response_with_tool_call( $tool_name );
					}

					return $this->create_simple_response( 'Task completed.' );
				}
			);

		return $mock_client;
	}

	/**
	 * Create simple response.
	 *
	 * @param string $content Response content.
	 * @return array Response data.
	 */
	protected function create_simple_response( $content ) {
		return array(
			'id'      => 'test-response-' . wp_rand(),
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => $content,
					),
				),
			),
		);
	}

	/**
	 * Create response with tool call.
	 *
	 * @param string $tool_name Tool name.
	 * @return array Response data.
	 */
	protected function create_response_with_tool_call( $tool_name ) {
		return array(
			'id'      => 'test-tool-response-' . wp_rand(),
			'choices' => array(
				array(
					'message' => array(
						'role'       => 'assistant',
						'content'    => 'Let me get that for you.',
						'tool_calls' => array(
							array(
								'id'       => 'call_' . $tool_name . '_' . wp_rand(),
								'type'     => 'function',
								'function' => array(
									'name'      => $tool_name,
									'arguments' => '{}',
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Create chat service.
	 *
	 * @param WP_MCP_AI_Language_Model_Router $router Router.
	 * @return WP_MCP_AI_Chat_Service Chat service.
	 */
	protected function create_chat_service( $router ) {
		$rate_limiter         = new WP_MCP_AI_Rate_Limit_Manager();
		$token_budget_manager = new WP_MCP_AI_Token_Budget_Manager();

		return new WP_MCP_AI_Chat_Service(
			$router,
			$rate_limiter,
			$token_budget_manager,
			$this->registry
		);
	}

	/**
	 * Create mock image generation tool.
	 *
	 * @return WP_MCP_AI_Tool_Interface Mock tool.
	 */
	protected function create_mock_image_tool() {
		return new class() implements WP_MCP_AI_Tool_Interface {
			public function get_slug() {
				return 'generate_test_image';
			}

			public function get_name() {
				return 'Generate Test Image';
			}

			public function get_description() {
				return 'Generate a test image';
			}

			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			public function execute( array $arguments = array(), array $context = array() ) {
				return array(
					'url'           => 'https://example.com/test-image.png',
					'attachment_id' => 123,
					'file_name'     => 'test-image.png',
					'mime_type'     => 'image/png',
				);
			}
		};
	}
}
