<?php
/**
 * Tests for Phase 2 Pro Toolkit Slash Commands
 *
 * Tests the additional command handlers for ecommerce, social media, and video production.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Phase 2 Pro Toolkit Commands Test Case
 */
class Test_Slash_Commands_Pro_Toolkit_Phase2 extends WP_UnitTestCase {

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
	 * Test Phase 2 e-commerce commands are registered.
	 */
	public function test_phase2_ecommerce_commands_registered() {
		$handler  = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_registered_commands();

		$this->assertArrayHasKey( 'discount-optimize', $commands );
		$this->assertArrayHasKey( 'inventory-forecast', $commands );
		$this->assertArrayHasKey( 'customer-segment', $commands );
	}

	/**
	 * Test Phase 2 social media commands are registered.
	 */
	public function test_phase2_social_media_commands_registered() {
		$handler  = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_registered_commands();

		$this->assertArrayHasKey( 'social-schedule', $commands );
		$this->assertArrayHasKey( 'content-calendar', $commands );
		$this->assertArrayHasKey( 'competitor-track', $commands );
	}

	/**
	 * Test Phase 2 video production commands are registered.
	 */
	public function test_phase2_video_commands_registered() {
		$handler  = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_registered_commands();

		$this->assertArrayHasKey( 'video-merge', $commands );
		$this->assertArrayHasKey( 'video-thumbnail', $commands );
		$this->assertArrayHasKey( 'video-compress', $commands );
	}

	/**
	 * Test social-schedule command validation.
	 */
	public function test_social_schedule_validation() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Test missing required parameters.
		$args    = array( 'content' => 'Test post' );
		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_social_schedule( $args, $context );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'required', strtolower( $result['message'] ) );
	}

	/**
	 * Test social-schedule command execution.
	 */
	public function test_social_schedule_execution() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$args = array(
			'content'   => 'Test scheduled post',
			'platforms' => 'facebook,twitter',
			'time'      => date( 'Y-m-d H:i', strtotime( '+1 day' ) ),
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_social_schedule( $args, $context );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'post_id', $result['data'] );
		$this->assertEquals( 'scheduled', $result['data']['status'] );
	}

	/**
	 * Test content-calendar command execution.
	 */
	public function test_content_calendar_execution() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$args = array(
			'action' => 'view',
			'period' => 30,
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_content_calendar( $args, $context );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
	}

	/**
	 * Test competitor-track command validation.
	 */
	public function test_competitor_track_validation() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Without competitor analysis tool, test simplified implementation.
		$args = array(
			'competitor' => '@example',
			'platform'   => 'twitter',
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_competitor_track( $args, $context );

		// Should succeed with mock data.
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertEquals( '@example', $result['data']['competitor'] );
	}

	/**
	 * Test video-merge command validation.
	 */
	public function test_video_merge_validation() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Test missing required parameter.
		$args    = array();
		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_video_merge( $args, $context );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'required', strtolower( $result['message'] ) );
	}

	/**
	 * Test video-merge command execution.
	 */
	public function test_video_merge_execution() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$args = array(
			'videos'      => '123,456,789',
			'output-name' => 'merged-video',
			'transitions' => true,
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_video_merge( $args, $context );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'job_id', $result['data'] );
		$this->assertEquals( 'queued', $result['data']['status'] );
		$this->assertEquals( 3, $result['data']['video_count'] );
	}

	/**
	 * Test video-thumbnail command validation.
	 */
	public function test_video_thumbnail_validation() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Test missing required parameter.
		$args    = array();
		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_video_thumbnail_generate( $args, $context );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'required', strtolower( $result['message'] ) );
	}

	/**
	 * Test video-compress command execution.
	 */
	public function test_video_compress_execution() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Create a dummy attachment for testing.
		$attachment_id = $this->factory->attachment->create_object(
			'test-video.mp4',
			0,
			array(
				'post_mime_type' => 'video/mp4',
				'post_type'      => 'attachment',
			)
		);

		$args = array(
			'video-id' => $attachment_id,
			'quality'  => 'high',
			'format'   => 'mp4',
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_video_compress( $args, $context );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'job_id', $result['data'] );
		$this->assertEquals( 'high', $result['data']['quality'] );
	}

	/**
	 * Test command parameter documentation exists.
	 */
	public function test_phase2_commands_have_documentation() {
		$handler  = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_registered_commands();

		$phase2_commands = array(
			'discount-optimize',
			'inventory-forecast',
			'social-schedule',
			'content-calendar',
			'competitor-track',
			'video-merge',
			'video-thumbnail',
			'video-compress',
		);

		foreach ( $phase2_commands as $command_name ) {
			$this->assertArrayHasKey( $command_name, $commands );
			$command = $commands[ $command_name ];

			// Check for usage documentation.
			$this->assertArrayHasKey( 'usage', $command );
			$this->assertNotEmpty( $command['usage'] );

			// Check for parameters documentation.
			$this->assertArrayHasKey( 'parameters', $command );
		}
	}

	/**
	 * Test command capability requirements are correct.
	 */
	public function test_phase2_command_capabilities() {
		$handler  = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_registered_commands();

		// E-commerce commands require manage_woocommerce.
		$ecommerce_commands = array( 'discount-optimize', 'inventory-forecast' );
		foreach ( $ecommerce_commands as $cmd ) {
			$this->assertEquals( 'manage_woocommerce', $commands[ $cmd ]['capability'] );
		}

		// Social media commands require edit_posts.
		$social_commands = array( 'social-schedule', 'content-calendar', 'competitor-track' );
		foreach ( $social_commands as $cmd ) {
			$this->assertEquals( 'edit_posts', $commands[ $cmd ]['capability'] );
		}

		// Video commands require upload_files.
		$video_commands = array( 'video-merge', 'video-thumbnail', 'video-compress' );
		foreach ( $video_commands as $cmd ) {
			$this->assertEquals( 'upload_files', $commands[ $cmd ]['capability'] );
		}
	}
}
