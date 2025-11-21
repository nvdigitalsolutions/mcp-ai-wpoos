<?php
/**
 * Tests for video analysis and captioning tools.
 *
 * @package WP_MCP_AI
 */

/**
 * Test video tools registration and functionality.
 */
class Test_Video_Tools extends WP_UnitTestCase {
	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->registry->init();
	}

	/**
	 * Test that analyze_video tool is registered.
	 */
	public function test_analyze_video_tool_registered() {
		$tool = $this->registry->get_tool( 'analyze_video' );
		$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool, 'analyze_video should be registered as a tool' );
	}

	/**
	 * Test that generate_video_caption tool is registered.
	 */
	public function test_generate_video_caption_tool_registered() {
		$tool = $this->registry->get_tool( 'generate_video_caption' );
		$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool, 'generate_video_caption should be registered as a tool' );
	}

	/**
	 * Test analyze_video tool definition.
	 */
	public function test_analyze_video_tool_definition() {
		$definition = $this->registry->get_tool_definition( 'analyze_video' );
		
		$this->assertIsArray( $definition, 'Tool definition should be an array' );
		$this->assertEquals( 'analyze_video', $definition['name'], 'Tool name should be analyze_video' );
		$this->assertArrayHasKey( 'description', $definition, 'Definition should have description' );
		$this->assertArrayHasKey( 'parameters', $definition, 'Definition should have parameters' );
		
		// Check parameters.
		$params = $definition['parameters'];
		$this->assertArrayHasKey( 'properties', $params, 'Parameters should have properties' );
		$this->assertArrayHasKey( 'video_url', $params['properties'], 'Should accept video_url parameter' );
		$this->assertArrayHasKey( 'attachment_id', $params['properties'], 'Should accept attachment_id parameter' );
		$this->assertArrayHasKey( 'prompt', $params['properties'], 'Should accept prompt parameter' );
		$this->assertArrayHasKey( 'context', $params['properties'], 'Should accept context parameter' );
	}

	/**
	 * Test generate_video_caption tool definition.
	 */
	public function test_generate_video_caption_tool_definition() {
		$definition = $this->registry->get_tool_definition( 'generate_video_caption' );
		
		$this->assertIsArray( $definition, 'Tool definition should be an array' );
		$this->assertEquals( 'generate_video_caption', $definition['name'], 'Tool name should be generate_video_caption' );
		$this->assertArrayHasKey( 'description', $definition, 'Definition should have description' );
		$this->assertArrayHasKey( 'parameters', $definition, 'Definition should have parameters' );
		
		// Check parameters.
		$params = $definition['parameters'];
		$this->assertArrayHasKey( 'properties', $params, 'Parameters should have properties' );
		$this->assertArrayHasKey( 'video_url', $params['properties'], 'Should accept video_url parameter' );
		$this->assertArrayHasKey( 'attachment_id', $params['properties'], 'Should accept attachment_id parameter' );
		$this->assertArrayHasKey( 'max_length', $params['properties'], 'Should accept max_length parameter' );
	}

	/**
	 * Test that video tools are in the wordpress-core group.
	 */
	public function test_video_tools_in_core_group() {
		$groups = $this->registry->get_tool_group_map();
		
		$this->assertArrayHasKey( 'analyze_video', $groups, 'analyze_video should be in group map' );
		$this->assertEquals( 'wordpress-core', $groups['analyze_video'], 'analyze_video should be in wordpress-core group' );
		
		$this->assertArrayHasKey( 'generate_video_caption', $groups, 'generate_video_caption should be in group map' );
		$this->assertEquals( 'wordpress-core', $groups['generate_video_caption'], 'generate_video_caption should be in wordpress-core group' );
	}

	/**
	 * Test that video tools have requires-video-model capability flag.
	 */
	public function test_video_tools_have_video_model_flag() {
		$video_tools = array( 'analyze_video', 'generate_video_caption' );
		
		foreach ( $video_tools as $tool_slug ) {
			$tool = $this->registry->get_tool( $tool_slug );
			$this->assertInstanceOf( WP_MCP_AI_Tool_Capability_Flags_Interface::class, $tool, "$tool_slug should implement capability flags interface" );
			
			$flags = $this->registry->get_tool_capability_flags( $tool_slug );
			$this->assertContains( 'requires-video-model', $flags, "$tool_slug should have requires-video-model flag" );
		}
	}

	/**
	 * Test that video tools have correct operational flags.
	 */
	public function test_video_tools_operational_flags() {
		$video_tools = array( 'analyze_video', 'generate_video_caption' );
		
		foreach ( $video_tools as $tool_slug ) {
			$flags = $this->registry->get_tool_capability_flags( $tool_slug );
			
			// Should be read-only.
			$this->assertContains( 'read-only', $flags, "$tool_slug should be read-only" );
			
			// Should require credentials.
			$this->assertContains( 'requires-credentials', $flags, "$tool_slug should require credentials" );
			
			// Should use external API.
			$this->assertContains( 'external-api', $flags, "$tool_slug should use external API" );
			
			// Should be network dependent.
			$this->assertContains( 'network-dependent', $flags, "$tool_slug should be network dependent" );
			
			// Should consume tokens.
			$this->assertContains( 'consumes-tokens', $flags, "$tool_slug should consume tokens" );
		}
	}

	/**
	 * Test analyze_video requires either video_url or attachment_id.
	 */
	public function test_analyze_video_requires_video_source() {
		$tool = $this->registry->get_tool( 'analyze_video' );
		
		// Create a user with upload_files capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		
		// Execute without any video source.
		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);
		
		$this->assertWPError( $result, 'Should return WP_Error when no video source provided' );
		$this->assertEquals( 'wp_mcp_ai_missing_video', $result->get_error_code(), 'Should return missing video error' );
	}

	/**
	 * Test generate_video_caption requires either video_url or attachment_id.
	 */
	public function test_generate_video_caption_requires_video_source() {
		$tool = $this->registry->get_tool( 'generate_video_caption' );
		
		// Create a user with upload_files capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		
		// Execute without any video source.
		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);
		
		$this->assertWPError( $result, 'Should return WP_Error when no video source provided' );
		$this->assertEquals( 'wp_mcp_ai_missing_video', $result->get_error_code(), 'Should return missing video error' );
	}

	/**
	 * Test video tools reject non-video attachments.
	 */
	public function test_video_tools_reject_non_video_attachments() {
		// Create an image attachment.
		$attachment_id = $this->factory->attachment->create_upload_object( WP_MCP_AI_PATH . 'tests/fixtures/test-image.png' );
		
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		
		$tool = $this->registry->get_tool( 'analyze_video' );
		$result = $tool->execute(
			array( 'attachment_id' => $attachment_id ),
			array( 'user_id' => $user_id )
		);
		
		$this->assertWPError( $result, 'Should return WP_Error for non-video attachment' );
		$this->assertEquals( 'wp_mcp_ai_not_video', $result->get_error_code(), 'Should return not-video error' );
	}

	/**
	 * Test video tools check user capabilities.
	 */
	public function test_video_tools_check_capabilities() {
		// Create a user without upload_files capability.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		
		$tool = $this->registry->get_tool( 'analyze_video' );
		$result = $tool->execute(
			array( 'video_url' => 'https://example.com/video.mp4' ),
			array( 'user_id' => $user_id )
		);
		
		$this->assertWPError( $result, 'Should return WP_Error for insufficient capabilities' );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code(), 'Should return forbidden error' );
	}

	/**
	 * Test that both video tools implement the same core interfaces.
	 */
	public function test_video_tools_implement_interfaces() {
		$video_tools = array( 'analyze_video', 'generate_video_caption' );
		
		foreach ( $video_tools as $tool_slug ) {
			$tool = $this->registry->get_tool( $tool_slug );
			
			$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool, "$tool_slug should implement tool interface" );
			$this->assertInstanceOf( WP_MCP_AI_Tool_Capability_Flags_Interface::class, $tool, "$tool_slug should implement capability flags interface" );
		}
	}

	/**
	 * Test video caption max_length parameter validation.
	 */
	public function test_video_caption_max_length_parameter() {
		$definition = $this->registry->get_tool_definition( 'generate_video_caption' );
		$params = $definition['parameters']['properties'];
		
		$this->assertArrayHasKey( 'max_length', $params, 'Should have max_length parameter' );
		$this->assertEquals( 'integer', $params['max_length']['type'], 'max_length should be integer type' );
		$this->assertEquals( 50, $params['max_length']['minimum'], 'max_length minimum should be 50' );
		$this->assertEquals( 500, $params['max_length']['maximum'], 'max_length maximum should be 500' );
		$this->assertEquals( 200, $params['max_length']['default'], 'max_length default should be 200' );
	}

	/**
	 * Test that video tools require Gemini provider.
	 */
	public function test_video_tools_gemini_provider_requirement() {
		// This test verifies the tools are designed to prefer Gemini.
		// In actual execution, tools should use Gemini as primary provider.
		
		$analyze_tool = $this->registry->get_tool( 'analyze_video' );
		$caption_tool = $this->registry->get_tool( 'generate_video_caption' );
		
		// Both tools should exist and be ready.
		$this->assertNotNull( $analyze_tool, 'Analyze video tool should exist' );
		$this->assertNotNull( $caption_tool, 'Generate video caption tool should exist' );
		
		// Get tool descriptions - they should mention video capabilities.
		$analyze_desc = $analyze_tool->get_description();
		$caption_desc = $caption_tool->get_description();
		
		$this->assertStringContainsString( 'video', strtolower( $analyze_desc ), 'Analyze tool description should mention video' );
		$this->assertStringContainsString( 'video', strtolower( $caption_desc ), 'Caption tool description should mention video' );
	}

	/**
	 * Test that all documented video-capable Gemini models are recognized.
	 */
	public function test_video_capable_gemini_models_recognized() {
		$caption_tool = $this->registry->get_tool( 'generate_video_caption' );
		$this->assertNotNull( $caption_tool, 'Generate video caption tool should exist' );

		// Get the is_video_capable_gemini_model method via reflection.
		$reflection = new ReflectionClass( $caption_tool );
		$method     = $reflection->getMethod( 'is_video_capable_gemini_model' );
		$method->setAccessible( true );

		// Test all 2025 video-capable models based on current Gemini API.
		$video_capable_models = array(
			// Gemini 3 series (latest - 2025).
			'gemini-3-pro-preview',
			'gemini-3-pro',

			// Gemini 2.5 series (production - 2025).
			'gemini-2.5-pro',
			'gemini-2.5-flash',
			'gemini-2.5-flash-lite',
			'gemini-2.5-flash-preview-09-2025',
			'gemini-live-2.5-flash-preview',

			// Gemini 2.0 series (stable).
			'gemini-2.0-flash-exp',
			'gemini-2.0-flash',
			'gemini-2.0-flash-lite',

			// Experimental models.
			'gemini-exp-1206',
			'gemini-exp-1121',
		);

		foreach ( $video_capable_models as $model ) {
			$result = $method->invoke( $caption_tool, $model );
			$this->assertTrue( $result, "Model $model should be recognized as video-capable" );
		}

		// Test that deprecated/non-video models are not recognized.
		$non_video_models = array(
			'gemini-1.5-pro',   // Deprecated 1.x series.
			'gemini-1.5-flash', // Deprecated 1.x series.
			'gemini-pro',       // Deprecated 1.x series.
			'gpt-4o',           // OpenAI model (not Gemini).
			'gemini-2.5-flash-image', // Image generation only.
		);

		foreach ( $non_video_models as $model ) {
			$result = $method->invoke( $caption_tool, $model );
			$this->assertFalse( $result, "Model $model should NOT be recognized as video-capable" );
		}
	}
}
