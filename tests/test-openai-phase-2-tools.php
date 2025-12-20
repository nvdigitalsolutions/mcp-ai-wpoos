<?php
/**
 * Tests for Phase 2 OpenAI API Integration Tools.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Phase 2 OpenAI tools: Semantic Search, Model Suggestions, and Batch Embeddings.
 */
class Test_OpenAI_Phase_2_Tools extends WP_UnitTestCase {
	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

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
	 * Test semantic_content_search tool registration.
	 */
	public function test_semantic_content_search_tool_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'semantic_content_search' );

		$this->assertNotNull( $tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
		$this->assertSame( 'semantic_content_search', $tool->get_slug() );
	}

	/**
	 * Test semantic_content_search tool requires read capability.
	 */
	public function test_semantic_content_search_requires_permission() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'semantic_content_search' );

		$result = $tool->execute(
			array( 'query' => 'test query' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test semantic_content_search tool requires query parameter.
	 */
	public function test_semantic_content_search_requires_query() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'semantic_content_search' );

		$result = $tool->execute(
			array(),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_query', $result->get_error_code() );
	}

	/**
	 * Test semantic_content_search tool implements capability flags.
	 */
	public function test_semantic_content_search_has_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'semantic_content_search' );

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $tool );

		$flags = $tool->get_capability_flags();
		$this->assertIsArray( $flags );
		$this->assertNotEmpty( $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'external-api', $flags );
	}

	/**
	 * Test suggest_best_model tool registration.
	 */
	public function test_suggest_best_model_tool_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'suggest_best_model' );

		$this->assertNotNull( $tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
		$this->assertSame( 'suggest_best_model', $tool->get_slug() );
	}

	/**
	 * Test suggest_best_model tool requires permission.
	 */
	public function test_suggest_best_model_requires_permission() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'suggest_best_model' );

		$result = $tool->execute(
			array( 'task_type' => 'chat' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test suggest_best_model tool requires task_type parameter.
	 */
	public function test_suggest_best_model_requires_task_type() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'suggest_best_model' );

		$result = $tool->execute(
			array(),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_task_type', $result->get_error_code() );
	}

	/**
	 * Test suggest_best_model tool returns valid recommendations.
	 */
	public function test_suggest_best_model_returns_recommendations() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'suggest_best_model' );

		$result = $tool->execute(
			array(
				'task_type'         => 'chat',
				'budget_preference' => 'low',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'recommended_model', $result );
		$this->assertArrayHasKey( 'reasoning', $result );
		$this->assertArrayHasKey( 'alternatives', $result );
		$this->assertIsArray( $result['alternatives'] );
	}

	/**
	 * Test suggest_best_model tool handles embeddings task.
	 */
	public function test_suggest_best_model_embeddings_task() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'suggest_best_model' );

		$result = $tool->execute(
			array(
				'task_type'    => 'embeddings',
				'requirements' => array( 'cost' ),
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'embedding', $result['recommended_model'] );
	}

	/**
	 * Test suggest_best_model tool implements capability flags.
	 */
	public function test_suggest_best_model_has_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'suggest_best_model' );

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $tool );

		$flags = $tool->get_capability_flags();
		$this->assertIsArray( $flags );
		$this->assertNotEmpty( $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'cacheable', $flags );
	}

	/**
	 * Test batch_embed_content tool registration.
	 */
	public function test_batch_embed_content_tool_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'batch_embed_content' );

		$this->assertNotNull( $tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
		$this->assertSame( 'batch_embed_content', $tool->get_slug() );
	}

	/**
	 * Test batch_embed_content tool requires edit_posts capability.
	 */
	public function test_batch_embed_content_requires_permission() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'batch_embed_content' );

		// Create a subscriber user (no edit_posts).
		$subscriber_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		$result = $tool->execute(
			array(),
			array( 'user_id' => $subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test batch_embed_content tool implements capability flags.
	 */
	public function test_batch_embed_content_has_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'batch_embed_content' );

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $tool );

		$flags = $tool->get_capability_flags();
		$this->assertIsArray( $flags );
		$this->assertNotEmpty( $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'modifies-state', $flags );
	}

	/**
	 * Test Phase 2 tools are in tool group map.
	 */
	public function test_phase_2_tools_in_group_map() {
		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$group_map = $registry->get_tool_group_map();

		$this->assertArrayHasKey( 'semantic_content_search', $group_map );
		$this->assertSame( 'external-tools', $group_map['semantic_content_search'] );

		$this->assertArrayHasKey( 'suggest_best_model', $group_map );
		$this->assertSame( 'external-tools', $group_map['suggest_best_model'] );

		$this->assertArrayHasKey( 'batch_embed_content', $group_map );
		$this->assertSame( 'external-tools', $group_map['batch_embed_content'] );
	}

	/**
	 * Test all Phase 2 tools have proper names.
	 */
	public function test_phase_2_tools_have_names() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$tools = array(
			'semantic_content_search' => 'Semantic Content Search',
			'suggest_best_model'      => 'Suggest Best Model',
			'batch_embed_content'     => 'Batch Embed Content',
		);

		foreach ( $tools as $slug => $expected_name ) {
			$tool = $registry->get_tool( $slug );
			$this->assertNotNull( $tool, "Tool {$slug} should be registered" );
			$this->assertStringContainsString( $expected_name, $tool->get_name() );
		}
	}

	/**
	 * Test all Phase 2 tools have proper descriptions.
	 */
	public function test_phase_2_tools_have_descriptions() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$tools = array(
			'semantic_content_search',
			'suggest_best_model',
			'batch_embed_content',
		);

		foreach ( $tools as $slug ) {
			$tool = $registry->get_tool( $slug );
			$this->assertNotNull( $tool, "Tool {$slug} should be registered" );

			$description = $tool->get_description();
			$this->assertNotEmpty( $description, "Tool {$slug} should have a description" );
			$this->assertGreaterThan( 20, strlen( $description ), "Tool {$slug} description should be meaningful" );
		}
	}

	/**
	 * Test all Phase 2 tools have parameter schemas.
	 */
	public function test_phase_2_tools_have_parameter_schemas() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$tools = array(
			'semantic_content_search',
			'suggest_best_model',
			'batch_embed_content',
		);

		foreach ( $tools as $slug ) {
			$tool = $registry->get_tool( $slug );
			$this->assertNotNull( $tool, "Tool {$slug} should be registered" );

			$schema = $tool->get_parameters_schema();
			$this->assertIsArray( $schema, "Tool {$slug} should have a parameter schema" );
			$this->assertArrayHasKey( 'type', $schema, "Tool {$slug} schema should have a type" );
			$this->assertSame( 'object', $schema['type'], "Tool {$slug} schema type should be object" );
		}
	}
}
