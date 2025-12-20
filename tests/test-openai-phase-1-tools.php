<?php
/**
 * Tests for Phase 1 OpenAI API Integration Tools.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Phase 1 OpenAI tools: Files, Models, and Embeddings.
 */
class Test_OpenAI_Phase_1_Tools extends WP_UnitTestCase {
	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create an admin user for testing.
		$this->admin_user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );

		// Set OpenAI API key for tests.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'openai_api_key'  => 'test-key-' . wp_generate_password( 32, false ),
				'request_timeout' => 30,
			)
		);
	}

	/**
	 * Test list_openai_files tool registration.
	 */
	public function test_list_openai_files_tool_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'list_openai_files' );

		$this->assertNotNull( $tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
		$this->assertSame( 'list_openai_files', $tool->get_slug() );
	}

	/**
	 * Test list_openai_files tool requires manage_options capability.
	 */
	public function test_list_openai_files_requires_permission() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'list_openai_files' );

		// Create a subscriber user (no manage_options).
		$subscriber_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		$result = $tool->execute(
			array(),
			array( 'user_id' => $subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test get_openai_file_details tool registration.
	 */
	public function test_get_openai_file_details_tool_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'get_openai_file_details' );

		$this->assertNotNull( $tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
		$this->assertSame( 'get_openai_file_details', $tool->get_slug() );
	}

	/**
	 * Test get_openai_file_details tool validates file_id parameter.
	 */
	public function test_get_openai_file_details_requires_file_id() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'get_openai_file_details' );

		$result = $tool->execute(
			array(),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_file_id', $result->get_error_code() );
	}

	/**
	 * Test list_available_models tool registration.
	 */
	public function test_list_available_models_tool_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'list_available_models' );

		$this->assertNotNull( $tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
		$this->assertSame( 'list_available_models', $tool->get_slug() );
	}

	/**
	 * Test list_available_models with mocked response.
	 */
	public function test_list_available_models_success() {
		// Mock HTTP response.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, '/v1/models' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'object' => 'list',
								'data'   => array(
									array(
										'id'       => 'gpt-4o',
										'object'   => 'model',
										'created'  => 1715367049,
										'owned_by' => 'openai',
									),
									array(
										'id'       => 'gpt-4o-mini',
										'object'   => 'model',
										'created'  => 1717527127,
										'owned_by' => 'openai',
									),
								),
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'list_available_models' );

		$result = $tool->execute(
			array(),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'models', $result );
		$this->assertCount( 2, $result['models'] );
		$this->assertSame( 2, $result['total_count'] );
	}

	/**
	 * Test get_model_information tool registration.
	 */
	public function test_get_model_information_tool_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'get_model_information' );

		$this->assertNotNull( $tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
		$this->assertSame( 'get_model_information', $tool->get_slug() );
	}

	/**
	 * Test get_model_information tool validates model_id parameter.
	 */
	public function test_get_model_information_requires_model_id() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'get_model_information' );

		$result = $tool->execute(
			array(),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_model_id', $result->get_error_code() );
	}

	/**
	 * Test create_text_embeddings tool registration.
	 */
	public function test_create_text_embeddings_tool_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'create_text_embeddings' );

		$this->assertNotNull( $tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
		$this->assertSame( 'create_text_embeddings', $tool->get_slug() );
	}

	/**
	 * Test create_text_embeddings tool validates input parameter.
	 */
	public function test_create_text_embeddings_requires_input() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'create_text_embeddings' );

		// Create an editor user (has edit_posts).
		$editor_id = $this->factory()->user->create( array( 'role' => 'editor' ) );

		$result = $tool->execute(
			array(),
			array( 'user_id' => $editor_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_input', $result->get_error_code() );
	}

	/**
	 * Test create_text_embeddings validates store_in_meta requires post_id.
	 */
	public function test_create_text_embeddings_store_in_meta_requires_post_id() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'create_text_embeddings' );

		$editor_id = $this->factory()->user->create( array( 'role' => 'editor' ) );

		$result = $tool->execute(
			array(
				'input'         => 'Test text',
				'store_in_meta' => true,
			),
			array( 'user_id' => $editor_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_post_id', $result->get_error_code() );
	}

	/**
	 * Test create_text_embeddings with valid post_id.
	 */
	public function test_create_text_embeddings_with_valid_post() {
		// Mock HTTP response.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, '/v1/embeddings' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'object' => 'list',
								'data'   => array(
									array(
										'object'    => 'embedding',
										'embedding' => array_fill( 0, 1536, 0.123 ),
										'index'     => 0,
									),
								),
								'model'  => 'text-embedding-3-small',
								'usage'  => array(
									'prompt_tokens' => 5,
									'total_tokens'  => 5,
								),
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$post_id   = $this->factory()->post->create();
		$editor_id = $this->factory()->user->create( array( 'role' => 'editor' ) );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'create_text_embeddings' );

		$result = $tool->execute(
			array(
				'input'         => 'Test text for embedding',
				'store_in_meta' => true,
				'post_id'       => $post_id,
			),
			array( 'user_id' => $editor_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'embeddings', $result );
		$this->assertTrue( $result['stored'] );
		$this->assertSame( $post_id, $result['post_id'] );

		// Verify embeddings were stored in post meta.
		$meta = get_post_meta( $post_id, '_wp_mcp_ai_embeddings', true );
		$this->assertIsArray( $meta );
		$this->assertArrayHasKey( 'embeddings', $meta );
		$this->assertArrayHasKey( 'model', $meta );
	}

	/**
	 * Test all Phase 1 tools have capability flags.
	 */
	public function test_phase_1_tools_have_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$phase_1_tools = array(
			'list_openai_files',
			'get_openai_file_details',
			'list_available_models',
			'get_model_information',
			'create_text_embeddings',
		);

		foreach ( $phase_1_tools as $tool_slug ) {
			$tool = $registry->get_tool( $tool_slug );
			$this->assertNotNull( $tool, "Tool '{$tool_slug}' should be registered" );

			// Check if tool implements capability flags interface.
			$this->assertInstanceOf(
				'WP_MCP_AI_Tool_Capability_Flags_Interface',
				$tool,
				"Tool '{$tool_slug}' should implement capability flags interface"
			);

			$flags = $tool->get_capability_flags();
			$this->assertIsArray( $flags, "Tool '{$tool_slug}' should return capability flags array" );
			$this->assertNotEmpty( $flags, "Tool '{$tool_slug}' should have at least one capability flag" );
		}
	}
}
