<?php
/**
 * Test Toolkit Slash Commands
 *
 * PHPUnit tests for toolkit-specific slash commands.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @since 1.3.0
 */

/**
 * Test toolkit slash commands functionality
 */
class Test_Toolkit_Slash_Commands extends WP_UnitTestCase {

	/**
	 * Toolkit manager instance
	 *
	 * @var WP_MCP_AI_Slash_Command_Toolkit_Manager
	 */
	private $toolkit_manager;

	/**
	 * Slash command handler instance
	 *
	 * @var WP_MCP_AI_Slash_Command_Handler
	 */
	private $handler;

	/**
	 * Setup test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes.
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-parser.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-handler.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-toolkit-registry.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-toolkit-manager.php';

		// Create user with appropriate capabilities.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		wp_set_current_user( $this->user_id );

		// Initialize handler (global pattern used by plugin).
		global $wp_mcp_ai_slash_command_handler;
		$wp_mcp_ai_slash_command_handler = new WP_MCP_AI_Slash_Command_Handler();
		$this->handler                   = $wp_mcp_ai_slash_command_handler;

		// Initialize toolkit manager.
		$this->toolkit_manager = WP_MCP_AI_Slash_Command_Toolkit_Manager::get_instance();
	}

	/**
	 * Teardown test environment
	 */
	public function tearDown(): void {
		// Reset global handler.
		global $wp_mcp_ai_slash_command_handler;
		$wp_mcp_ai_slash_command_handler = null;

		parent::tearDown();
	}

	/**
	 * Test toolkit manager singleton
	 */
	public function test_toolkit_manager_singleton() {
		$instance1 = WP_MCP_AI_Slash_Command_Toolkit_Manager::get_instance();
		$instance2 = WP_MCP_AI_Slash_Command_Toolkit_Manager::get_instance();

		$this->assertSame( $instance1, $instance2 );
	}

	/**
	 * Test toolkit commands are registered
	 */
	public function test_toolkit_commands_registered() {
		// Trigger registration.
		do_action( 'init' );

		// Check that content-draft command exists.
		$this->assertTrue(
			$this->handler->command_exists( 'content-draft' ),
			'content-draft command should be registered'
		);

		// Check that seo-optimize command exists.
		$this->assertTrue(
			$this->handler->command_exists( 'seo-optimize' ),
			'seo-optimize command should be registered'
		);
	}

	/**
	 * Test content-draft command creates post
	 */
	public function test_content_draft_creates_post() {
		// Trigger registration.
		do_action( 'init' );

		// Execute command.
		$result = $this->handler->execute(
			'/content-draft --topic="Test Post" --type=post --tone=casual',
			array(
				'user_id' => $this->user_id,
			)
		);

		// Verify result.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'post_id', $result['data'] );

		$post_id = $result['data']['post_id'];

		// Verify post was created.
		$post = get_post( $post_id );
		$this->assertInstanceOf( 'WP_Post', $post );
		$this->assertEquals( 'Test Post', $post->post_title );
		$this->assertEquals( 'draft', $post->post_status );

		// Verify metadata.
		$this->assertEquals(
			'Test Post',
			get_post_meta( $post_id, '_wp_mcp_ai_draft_topic', true )
		);
		$this->assertEquals(
			'casual',
			get_post_meta( $post_id, '_wp_mcp_ai_draft_tone', true )
		);
	}

	/**
	 * Test content-draft requires topic parameter
	 */
	public function test_content_draft_requires_topic() {
		// Trigger registration.
		do_action( 'init' );

		// Execute command without topic.
		$result = $this->handler->execute(
			'/content-draft',
			array(
				'user_id' => $this->user_id,
			)
		);

		// Verify error.
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertEquals( 'missing_required_param', $result['error'] );
	}

	/**
	 * Test content-draft requires edit_posts capability
	 */
	public function test_content_draft_requires_capability() {
		// Create user without edit_posts capability.
		$subscriber_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
		wp_set_current_user( $subscriber_id );

		// Trigger registration.
		do_action( 'init' );

		// Execute command.
		$result = $this->handler->execute(
			'/content-draft --topic="Test"',
			array(
				'user_id' => $subscriber_id,
			)
		);

		// Verify error.
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertEquals( 'insufficient_permissions', $result['error'] );
	}

	/**
	 * Test toolkit availability checking
	 */
	public function test_toolkit_availability_filter() {
		// Test that filter can disable toolkit commands.
		add_filter(
			'wp_mcp_ai_toolkit_enabled',
			function( $enabled, $toolkit_slug ) {
				if ( 'content_publishing' === $toolkit_slug ) {
					return false;
				}
				return $enabled;
			},
			10,
			2
		);

		// Get fresh instance.
		$manager = WP_MCP_AI_Slash_Command_Toolkit_Manager::get_instance();

		// Content publishing commands should not be in the list.
		$commands = $manager->get_toolkit_commands( 'content_publishing' );

		// Filter affects registration, not retrieval.
		$this->assertNotEmpty( $commands );
	}

	/**
	 * Test command response format
	 */
	public function test_command_response_format() {
		// Trigger registration.
		do_action( 'init' );

		// Execute command.
		$result = $this->handler->execute(
			'/content-draft --topic="Test"',
			array(
				'user_id' => $this->user_id,
			)
		);

		// Verify response format.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'data', $result );

		// Verify data structure.
		$this->assertArrayHasKey( 'post_id', $result['data'] );
		$this->assertArrayHasKey( 'topic', $result['data'] );
		$this->assertArrayHasKey( 'type', $result['data'] );
		$this->assertArrayHasKey( 'tone', $result['data'] );
		$this->assertArrayHasKey( 'edit_url', $result['data'] );
	}

	/**
	 * Test placeholder commands return placeholder response
	 */
	public function test_placeholder_commands() {
		// Trigger registration.
		do_action( 'init' );

		// Test image-optimize (placeholder).
		$result = $this->handler->execute(
			'/image-optimize --attachment_id=123',
			array(
				'user_id' => $this->user_id,
			)
		);

		// Verify placeholder response.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'coming soon', $result['message'] );
	}

	/**
	 * Test all toolkits have commands defined
	 */
	public function test_all_toolkits_have_commands() {
		$registry = WP_MCP_AI_Toolkit_Registry::get_instance();
		$toolkits = $registry->get_toolkits();

		$manager = WP_MCP_AI_Slash_Command_Toolkit_Manager::get_instance();

		foreach ( array_keys( $toolkits ) as $toolkit_slug ) {
			$commands = $manager->get_toolkit_commands( $toolkit_slug );

			// Each toolkit should have at least one command.
			$this->assertNotEmpty(
				$commands,
				"Toolkit {$toolkit_slug} should have commands defined"
			);
		}
	}
}
