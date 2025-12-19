<?php
/**
 * Test suite for Sora video generation tool.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for WP_MCP_AI_Tool_Generate_Sora_Video.
 */
class Test_Sora_Video_Tool extends WP_UnitTestCase {
	/**
	 * Test tool instantiation.
	 */
	public function test_tool_instantiation() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Sora_Video();

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Generate_Sora_Video', $tool );
	}

	/**
	 * Test tool slug.
	 */
	public function test_tool_slug() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Sora_Video();

		$this->assertEquals( 'generate_sora_video', $tool->get_slug() );
	}

	/**
	 * Test tool name.
	 */
	public function test_tool_name() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Sora_Video();

		$this->assertNotEmpty( $tool->get_name() );
	}

	/**
	 * Test tool description.
	 */
	public function test_tool_description() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Sora_Video();

		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test parameters schema.
	 */
	public function test_parameters_schema() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php';
		$tool   = new WP_MCP_AI_Tool_Generate_Sora_Video();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'prompt', $schema['properties'] );
		$this->assertArrayHasKey( 'model', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'prompt', $schema['required'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php';
		$tool  = new WP_MCP_AI_Tool_Generate_Sora_Video();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-credentials', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'async', $flags );
		$this->assertContains( 'long-running', $flags );
	}

	/**
	 * Test model requirements.
	 */
	public function test_model_requirements() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php';
		$tool         = new WP_MCP_AI_Tool_Generate_Sora_Video();
		$requirements = $tool->get_model_requirements();

		$this->assertIsArray( $requirements );
		$this->assertContains( 'video-generation', $requirements );
	}

	/**
	 * Test execution without prompt returns error.
	 */
	public function test_execute_without_prompt() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Sora_Video();

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_prompt', $result->get_error_code() );
	}

	/**
	 * Test execution without upload_files capability returns error.
	 */
	public function test_execute_without_upload_capability() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Sora_Video();

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $tool->execute(
			array( 'prompt' => 'Test video' ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test LLM sanitizer strips video data.
	 */
	public function test_sanitize_for_llm() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php';
		$tool = new WP_MCP_AI_Tool_Generate_Sora_Video();

		$result = array(
			'success'    => true,
			'video_url'  => 'data:video/mp4;base64,dGVzdA==',
			'prompt'     => 'Test prompt',
			'model'      => 'sora-2',
			'some_extra' => 'data',
		);

		$sanitized = $tool->sanitize_for_llm( $result );

		$this->assertIsArray( $sanitized );
		$this->assertArrayNotHasKey( 'video_url', $sanitized );
		$this->assertArrayHasKey( 'video_data_stripped', $sanitized );
		$this->assertTrue( $sanitized['video_data_stripped'] );
		$this->assertArrayHasKey( 'success', $sanitized );
		$this->assertArrayHasKey( 'prompt', $sanitized );
		$this->assertArrayHasKey( 'model', $sanitized );
	}

	/**
	 * Test validated tool instantiation.
	 */
	public function test_validated_tool_instantiation() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-sora-video-validated.php';
		$tool = new WP_MCP_AI_Tool_Generate_Sora_Video_Validated();

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Generate_Sora_Video_Validated', $tool );
		$this->assertEquals( 'generate_sora_video_validated', $tool->get_slug() );
	}

	/**
	 * Test API endpoint is correct.
	 */
	public function test_api_endpoint() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php';

		// Use reflection to access the constant.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Generate_Sora_Video' );
		$endpoint   = $reflection->getConstant( 'API_ENDPOINT' );

		// Verify the endpoint is correct (not the old /generations endpoint).
		$this->assertEquals( 'https://api.openai.com/v1/videos', $endpoint );
		$this->assertStringNotContainsString( '/generations', $endpoint );
	}
}
