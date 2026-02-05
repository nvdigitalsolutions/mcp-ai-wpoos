<?php
/**
 * Tests for Pro Toolkit Slash Commands
 *
 * Tests the new command handlers for ecommerce, social media, and video production toolkits.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Pro Toolkit Slash Commands Test Case
 */
class Test_Slash_Commands_Pro_Toolkit extends WP_UnitTestCase {

	/**
	 * Toolkit manager instance.
	 *
	 * @var WP_MCP_AI_Slash_Command_Toolkit_Manager
	 */
	protected $toolkit_manager;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes.
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/slash-commands-init.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-handler.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-toolkit-manager.php';

		// Initialize slash commands.
		wp_mcp_ai_init_slash_commands();

		// Get toolkit manager instance.
		$this->toolkit_manager = WP_MCP_AI_Slash_Command_Toolkit_Manager::get_instance();
	}

	/**
	 * Test toolkit manager is initialized.
	 */
	public function test_toolkit_manager_initialized() {
		$this->assertNotNull( $this->toolkit_manager );
		$this->assertInstanceOf( 'WP_MCP_AI_Slash_Command_Toolkit_Manager', $this->toolkit_manager );
	}

	/**
	 * Test ecommerce commands are registered.
	 */
	public function test_ecommerce_commands_registered() {
		$handler = wp_mcp_ai_get_slash_command_handler();
		$this->assertNotNull( $handler );

		// Check if upsell-suggest command is registered.
		$commands = $handler->get_registered_commands();
		$this->assertArrayHasKey( 'upsell-suggest', $commands );
		$this->assertArrayHasKey( 'abandoned-recover', $commands );
		$this->assertArrayHasKey( 'ecom-analytics', $commands );
	}

	/**
	 * Test social media commands are registered.
	 */
	public function test_social_media_commands_registered() {
		$handler = wp_mcp_ai_get_slash_command_handler();
		$this->assertNotNull( $handler );

		$commands = $handler->get_registered_commands();
		$this->assertArrayHasKey( 'hashtag-suggest', $commands );
		$this->assertArrayHasKey( 'social-analytics', $commands );
	}

	/**
	 * Test video production commands are registered.
	 */
	public function test_video_production_commands_registered() {
		$handler = wp_mcp_ai_get_slash_command_handler();
		$this->assertNotNull( $handler );

		$commands = $handler->get_registered_commands();
		$this->assertArrayHasKey( 'video-subtitle', $commands );
		$this->assertArrayHasKey( 'video-template', $commands );
		$this->assertArrayHasKey( 'video-analytics', $commands );
	}

	/**
	 * Test hashtag-suggest command execution.
	 */
	public function test_hashtag_suggest_command() {
		// Set up user with proper capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$args = array(
			'content' => 'This is a test post about WordPress development and artificial intelligence',
			'count'   => 5,
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_hashtag_suggest( $args, $context );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'hashtags', $result['data'] );
		$this->assertNotEmpty( $result['data']['hashtags'] );
	}

	/**
	 * Test social-analytics command execution.
	 */
	public function test_social_analytics_command() {
		// Set up user with proper capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$args = array(
			'platform' => 'all',
			'period'   => 'week',
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_social_analytics( $args, $context );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'total_posts', $result['data'] );
		$this->assertArrayHasKey( 'engagement', $result['data'] );
	}

	/**
	 * Test video-subtitle command validation.
	 */
	public function test_video_subtitle_command_validation() {
		// Set up user with proper capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Test missing required parameter.
		$args = array();
		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_video_subtitle( $args, $context );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'required', strtolower( $result['message'] ) );
	}

	/**
	 * Test video-template command execution.
	 */
	public function test_video_template_command() {
		// Set up user with proper capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$args = array(
			'template' => 'intro-template',
			'input'    => '123,456',
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_video_template( $args, $context );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'job_id', $result['data'] );
		$this->assertArrayHasKey( 'status', $result['data'] );
		$this->assertEquals( 'queued', $result['data']['status'] );
	}

	/**
	 * Test video-analytics command execution.
	 */
	public function test_video_analytics_command() {
		// Set up user with proper capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$args = array(
			'period' => 'month',
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_video_analytics( $args, $context );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'views', $result['data'] );
		$this->assertArrayHasKey( 'engagement', $result['data'] );
	}

	/**
	 * Test command parameter documentation.
	 */
	public function test_command_parameter_documentation() {
		$handler = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_registered_commands();

		// Check upsell-suggest has proper documentation.
		$this->assertArrayHasKey( 'upsell-suggest', $commands );
		$command = $commands['upsell-suggest'];
		$this->assertArrayHasKey( 'usage', $command );
		$this->assertArrayHasKey( 'parameters', $command );
		$this->assertNotEmpty( $command['parameters'] );

		// Check video-subtitle has proper documentation.
		$this->assertArrayHasKey( 'video-subtitle', $commands );
		$command = $commands['video-subtitle'];
		$this->assertArrayHasKey( 'usage', $command );
		$this->assertArrayHasKey( 'parameters', $command );
		$this->assertNotEmpty( $command['parameters'] );
	}

	/**
	 * Test command capability requirements.
	 */
	public function test_command_capability_requirements() {
		$handler = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_registered_commands();

		// E-commerce commands should require manage_woocommerce.
		$this->assertArrayHasKey( 'upsell-suggest', $commands );
		$this->assertEquals( 'manage_woocommerce', $commands['upsell-suggest']['capability'] );

		// Social media commands should require edit_posts.
		$this->assertArrayHasKey( 'hashtag-suggest', $commands );
		$this->assertEquals( 'edit_posts', $commands['hashtag-suggest']['capability'] );

		// Video commands should require upload_files.
		$this->assertArrayHasKey( 'video-subtitle', $commands );
		$this->assertEquals( 'upload_files', $commands['video-subtitle']['capability'] );
	}

	/**
	 * Test hashtag suggest content processing.
	 */
	public function test_hashtag_suggest_content_processing() {
		// Set up user with proper capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$args = array(
			'content' => 'Amazing WordPress plugin for artificial intelligence integration',
			'count'   => 10,
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_hashtag_suggest( $args, $context );

		$this->assertTrue( $result['success'] );
		$hashtags = $result['data']['hashtags'];

		// Verify hashtags format.
		foreach ( $hashtags as $hashtag ) {
			$this->assertStringStartsWith( '#', $hashtag );
		}
	}
}
