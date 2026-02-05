<?php
/**
 * Tests for Phase 3 Pro Toolkit Slash Commands
 *
 * Tests the Phase 3 command handlers.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Phase 3 Pro Toolkit Commands Test Case
 */
class Test_Slash_Commands_Pro_Toolkit_Phase3 extends WP_UnitTestCase {

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
	 * Test Phase 3 e-commerce commands are registered.
	 */
	public function test_phase3_ecommerce_commands_registered() {
		$handler = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_registered_commands();

		$this->assertArrayHasKey( 'bundle-create', $commands );
		$this->assertArrayHasKey( 'shipping-optimize', $commands );
		$this->assertArrayHasKey( 'fraud-detect', $commands );
	}

	/**
	 * Test Phase 3 social media commands are registered.
	 */
	public function test_phase3_social_media_commands_registered() {
		$handler = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_registered_commands();

		$this->assertArrayHasKey( 'post-optimize', $commands );
		$this->assertArrayHasKey( 'influencer-find', $commands );
		$this->assertArrayHasKey( 'campaign-create', $commands );
	}

	/**
	 * Test Phase 3 video production commands are registered.
	 */
	public function test_phase3_video_commands_registered() {
		$handler = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_registered_commands();

		$this->assertArrayHasKey( 'video-trim', $commands );
		$this->assertArrayHasKey( 'video-voiceover', $commands );
		$this->assertArrayHasKey( 'video-render', $commands );
	}

	/**
	 * Test bundle-create command validation.
	 */
	public function test_bundle_create_validation() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Test missing required parameters.
		$args = array( 'name' => 'Test Bundle' );
		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_bundle_create( $args, $context );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'required', strtolower( $result['message'] ) );
	}

	/**
	 * Test bundle-create command execution.
	 */
	public function test_bundle_create_execution() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$args = array(
			'name'     => 'Test Bundle',
			'products' => '123,456,789',
			'discount' => 15,
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_bundle_create( $args, $context );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'bundle_id', $result['data'] );
		$this->assertEquals( 3, $result['data']['product_count'] );
	}

	/**
	 * Test shipping-optimize command execution.
	 */
	public function test_shipping_optimize_execution() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$args = array(
			'zone'          => 'domestic',
			'analyze-costs' => true,
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_shipping_optimize( $args, $context );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'potential_savings', $result['data'] );
		$this->assertArrayHasKey( 'recommendations', $result['data'] );
	}

	/**
	 * Test fraud-detect command execution.
	 */
	public function test_fraud_detect_execution() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$args = array(
			'scan-recent' => true,
			'threshold'   => 'medium',
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_fraud_detect( $args, $context );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'flagged_count', $result['data'] );
	}

	/**
	 * Test post-optimize command validation.
	 */
	public function test_post_optimize_validation() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Test missing required parameter.
		$args = array();
		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_post_optimize( $args, $context );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'required', strtolower( $result['message'] ) );
	}

	/**
	 * Test post-optimize command execution.
	 */
	public function test_post_optimize_execution() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$args = array(
			'content'  => 'Check out our new product!',
			'platform' => 'twitter',
			'goal'     => 'engagement',
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_post_optimize( $args, $context );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'optimized', $result['data'] );
		$this->assertArrayHasKey( 'suggestions', $result['data'] );
		$this->assertArrayHasKey( 'engagement_score', $result['data'] );
	}

	/**
	 * Test influencer-find command execution.
	 */
	public function test_influencer_find_execution() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$args = array(
			'niche'         => 'technology',
			'platform'      => 'instagram',
			'min-followers' => 10000,
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_influencer_find( $args, $context );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'influencers', $result['data'] );
		$this->assertGreaterThan( 0, $result['data']['found_count'] );
	}

	/**
	 * Test campaign-create command execution.
	 */
	public function test_campaign_create_execution() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$args = array(
			'name'     => 'Summer Sale Campaign',
			'goal'     => 'conversions',
			'duration' => 30,
			'budget'   => 1000,
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_campaign_create( $args, $context );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'campaign_id', $result['data'] );
		$this->assertEquals( 'active', $result['data']['status'] );
	}

	/**
	 * Test video-trim command validation.
	 */
	public function test_video_trim_validation() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Test missing required parameters.
		$args = array( 'video-id' => 123 );
		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_video_trim( $args, $context );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'required', strtolower( $result['message'] ) );
	}

	/**
	 * Test video-voiceover command execution.
	 */
	public function test_video_voiceover_execution() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Create dummy attachment.
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
			'script'   => 'This is a test voiceover script.',
			'voice'    => 'female',
			'language' => 'en',
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_video_voiceover( $args, $context );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'job_id', $result['data'] );
		$this->assertEquals( 'processing', $result['data']['status'] );
	}

	/**
	 * Test video-render command execution.
	 */
	public function test_video_render_execution() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$args = array(
			'project-id' => 'project_123',
			'quality'    => 'high',
			'format'     => 'mp4',
		);

		$context = array( 'user_id' => $user_id );

		$result = $this->toolkit_manager->handle_video_render( $args, $context );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'job_id', $result['data'] );
		$this->assertEquals( 'rendering', $result['data']['status'] );
	}

	/**
	 * Test all Phase 3 commands have documentation.
	 */
	public function test_phase3_commands_have_documentation() {
		$handler = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_registered_commands();

		$phase3_commands = array(
			'bundle-create',
			'shipping-optimize',
			'fraud-detect',
			'post-optimize',
			'influencer-find',
			'campaign-create',
			'video-trim',
			'video-voiceover',
			'video-render',
		);

		foreach ( $phase3_commands as $command_name ) {
			$this->assertArrayHasKey( $command_name, $commands );
			$command = $commands[ $command_name ];
			
			$this->assertArrayHasKey( 'usage', $command );
			$this->assertNotEmpty( $command['usage'] );
			
			$this->assertArrayHasKey( 'parameters', $command );
		}
	}
}
