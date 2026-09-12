<?php
/**
 * Test Toolkit Slash Commands
 *
 * PHPUnit tests for toolkit-specific slash commands.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
	 * Editor user ID used as execution context.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Setup test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes.
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-parser.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-handler.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-tool-adapter.php';
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

		// The toolkit manager singleton captures the handler reference in its
		// constructor. If the singleton was already created (e.g. during
		// WordPress init bootstrap), it retains the old handler. Force it to
		// use the handler we just created so register_toolkit_commands()
		// registers into the correct instance.
		$reflection   = new ReflectionClass( WP_MCP_AI_Slash_Command_Toolkit_Manager::class );
		$handler_prop = $reflection->getProperty( 'handler' );
		$handler_prop->setAccessible( true );
		$handler_prop->setValue( $this->toolkit_manager, $this->handler );
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
		// Trigger registration directly to avoid re-running
		// wp_mcp_ai_init_slash_commands (which would overwrite
		// the global handler and re-register blocks).
		$this->toolkit_manager->register_toolkit_commands();

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
	 * Test content-draft command delegates to the create_post tool and
	 * creates a draft post.
	 */
	public function test_content_draft_creates_post() {
		$this->toolkit_manager->register_toolkit_commands();

		// Execute command. The command is a declarative wrapper over the
		// create_post tool: --topic maps to the post title and --content
		// supplies the required body. The adapter sets status=draft.
		$result = $this->handler->execute(
			'/content-draft --topic="Test Post" --content="Generated draft body."',
			array(
				'user_id' => $this->user_id,
			)
		);

		// Verify the canonical adapter envelope wraps the tool payload.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'ID', $result['data'] );

		$post_id = $result['data']['ID'];

		// Verify post was created.
		$post = get_post( $post_id );
		$this->assertInstanceOf( 'WP_Post', $post );
		$this->assertEquals( 'Test Post', $post->post_title );
		$this->assertEquals( 'draft', $post->post_status );
		$this->assertNotEmpty( $post->post_content );
	}

	/**
	 * Test content-draft rejects a call missing its required arguments.
	 */
	public function test_content_draft_requires_topic() {
		$this->toolkit_manager->register_toolkit_commands();

		// Execute command without topic/title or content. Validation is
		// delegated to the create_post tool, which rejects the call with the
		// canonical tool error envelope.
		$result = $this->handler->execute(
			'/content-draft',
			array(
				'user_id' => $this->user_id,
			)
		);

		// Verify error: the declarative adapter passes the tool's WP_Error
		// through unchanged.
		$this->assertTrue( is_wp_error( $result ), 'Expected WP_Error for missing required arguments' );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
		$this->assertEquals( 'Validation failed', $result->get_error_message() );
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

		$this->toolkit_manager->register_toolkit_commands();

		// Execute command.
		$result = $this->handler->execute(
			'/content-draft --topic="Test"',
			array(
				'user_id' => $subscriber_id,
			)
		);

		// The handler returns WP_Error for authorization failures
		// (checked before the command handler runs).
		$this->assertTrue( is_wp_error( $result ), 'Expected WP_Error for capability failure' );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * Test toolkit availability checking
	 */
	public function test_toolkit_availability_filter() {
		// Test that filter can disable toolkit commands.
		add_filter(
			'wp_mcp_ai_toolkit_enabled',
			function ( $enabled, $toolkit_slug ) {
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
	 *
	 * The declarative adapter normalises the tool payload into the canonical
	 * slash-command envelope: success, message and the raw tool data under
	 * the data key.
	 */
	public function test_command_response_format() {
		$this->toolkit_manager->register_toolkit_commands();

		// Execute command.
		$result = $this->handler->execute(
			'/content-draft --topic="Test" --content="Draft body."',
			array(
				'user_id' => $this->user_id,
			)
		);

		// Verify response format.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'data', $result );

		// Verify the create_post tool payload structure.
		$this->assertArrayHasKey( 'ID', $result['data'] );
		$this->assertArrayHasKey( 'title', $result['data'] );
		$this->assertArrayHasKey( 'status', $result['data'] );
		$this->assertArrayHasKey( 'post_type', $result['data'] );
		$this->assertEquals( 'Test', $result['data']['title'] );
		$this->assertEquals( 'draft', $result['data']['status'] );
		$this->assertEquals( 'post', $result['data']['post_type'] );
	}

	/**
	 * Test a command whose backing tool is not registered returns a clean
	 * tool_unavailable error instead of a fatal.
	 */
	public function test_command_with_unavailable_tool_returns_error() {
		$this->toolkit_manager->register_toolkit_commands();

		// Register a command whose backing tool is not registered anywhere.
		$this->handler->register(
			'no-backing-tool',
			array(
				'tool'        => 'no_such_tool_slug',
				'description' => __( 'Command backed by an unavailable tool.', 'mcp-ai-wpoos' ),
				'usage'       => '/no-backing-tool',
				'capability'  => 'edit_posts',
			)
		);

		// Execute command.
		$result = $this->handler->execute(
			'/no-backing-tool',
			array(
				'user_id' => $this->user_id,
			)
		);

		// Verify the adapter reports the missing tool.
		$this->assertTrue( is_wp_error( $result ), 'Expected WP_Error for a command with no backing tool' );
		$this->assertEquals( 'tool_unavailable', $result->get_error_code() );
	}

	/**
	 * Test toolkit command coverage.
	 *
	 * Since the declarative rework, slash commands only exist for toolkits
	 * with real backing tools — placeholder commands were purged. Toolkits
	 * whose tools are not wrapped by any command therefore have an empty
	 * command set, and every other toolkit has at least one command.
	 */
	public function test_all_toolkits_have_commands() {
		$registry = WP_MCP_AI_Toolkit_Registry::get_instance();
		$toolkits = $registry->get_toolkits();

		$manager = WP_MCP_AI_Slash_Command_Toolkit_Manager::get_instance();

		// Toolkits with no tool-backed commands after the declarative rework.
		$toolkits_without_commands = array(
			'ecommerce_business',
			'geospatial_location',
			'workflow_automation',
			'communication_outreach',
			'integration_external',
			'ai_model_management',
		);

		foreach ( array_keys( $toolkits ) as $toolkit_slug ) {
			$commands = $manager->get_toolkit_commands( $toolkit_slug );

			if ( in_array( $toolkit_slug, $toolkits_without_commands, true ) ) {
				// Purged toolkits have no commands.
				$this->assertEmpty(
					$commands,
					"Toolkit {$toolkit_slug} should have no commands defined"
				);
			} else {
				// Every remaining toolkit should have at least one command.
				$this->assertNotEmpty(
					$commands,
					"Toolkit {$toolkit_slug} should have commands defined"
				);
			}
		}
	}

	/**
	 * Test pro toolkit commands are registered when not in base version mode.
	 *
	 * Since the declarative rework, pro commands are tool-backed wrappers:
	 * placeholder commands without a backing tool were purged.
	 *
	 * @since 1.3.0
	 */
	public function test_pro_toolkit_commands_registered() {
		// Skip if in base version mode.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Test skipped in base version mode' );
		}

		$this->toolkit_manager->register_toolkit_commands();

		// Test the retained pro toolkit commands are registered.
		$pro_commands = array(
			'booking-create',
			'lead-add',
			'lead-qualify',
			'lead-assign',
			'deal-create',
			'deal-move',
			'pipeline-view',
			'doc-create',
			'abandoned-recover',
			'inventory-forecast',
			'create-discount-campaign',
			'budget-create',
			'image-edit',
			'content-translate',
			'translate-content',
			'social-post',
			'social-schedule',
			'social-analytics',
			'content-calendar',
			'social-publish',
			'video-compress',
			'video-trim',
			'video-merge',
			'video-thumbnail',
			'video-edit',
			'get-video-metadata',
		);

		foreach ( $pro_commands as $command ) {
			$this->assertTrue(
				$this->handler->command_exists( $command ),
				"Pro command '{$command}' should be registered"
			);
		}

		// The purged placeholder commands must not be registered.
		$purged_commands = array(
			'aitool-create',
			'analytics-dashboard',
			'architect-plan',
			'floor-plan',
			'channel-create',
			'track-add',
			'product-recommend',
			'media-organize',
			'business-register',
			'site-research',
		);

		foreach ( $purged_commands as $command ) {
			$this->assertFalse(
				$this->handler->command_exists( $command ),
				"Placeholder command '{$command}' should have been purged"
			);
		}
	}

	/**
	 * Test pro toolkit command count.
	 *
	 * @since 1.3.0
	 */
	public function test_pro_toolkit_command_count() {
		// Skip if in base version mode.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Test skipped in base version mode' );
		}

		$manager = WP_MCP_AI_Slash_Command_Toolkit_Manager::get_instance();

		// Expected command counts for the retained pro toolkits after the
		// declarative rework.
		$expected_counts = array(
			'calendar_booking'    => 1,
			'crm'                 => 6,
			'document_generation' => 1,
			'ecommerce_pro'       => 3,
			'financial_planner'   => 1,
			'image_production'    => 1,
			'multilingual'        => 2,
			'social_media'        => 5,
			'video_production'    => 6,
		);

		foreach ( $expected_counts as $toolkit_slug => $expected_count ) {
			$commands = $manager->get_toolkit_commands( $toolkit_slug );

			$this->assertCount(
				$expected_count,
				$commands,
				"Toolkit '{$toolkit_slug}' should have {$expected_count} commands"
			);
		}

		// The purged pro toolkits no longer define any commands.
		$purged_toolkits = array(
			'ai_tool_builder',
			'analytics_pro',
			'architect_agent',
			'architectural_design',
			'chat_channels',
			'dj_management',
			'media_pro',
			'regulatory_registration',
			'site_creator',
		);

		foreach ( $purged_toolkits as $toolkit_slug ) {
			$this->assertEmpty(
				$manager->get_toolkit_commands( $toolkit_slug ),
				"Purged toolkit '{$toolkit_slug}' should have no commands"
			);
		}
	}

	/**
	 * Test pro toolkit commands use the generic tool adapter handler.
	 *
	 * Since the declarative rework every pro command delegates through a
	 * WP_MCP_AI_Slash_Command_Tool_Adapter bound to its backing tool slug.
	 *
	 * @since 1.3.0
	 */
	public function test_pro_command_generic_handler() {
		// Skip if in base version mode.
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$this->markTestSkipped( 'Test skipped in base version mode' );
		}

		$this->toolkit_manager->register_toolkit_commands();

		// The generic handler is the shared tool adapter bound to the command's
		// backing tool slug.
		$config = $this->handler->get_command( 'lead-add' );
		$this->assertNotFalse( $config );
		$this->assertInstanceOf( 'WP_MCP_AI_Slash_Command_Tool_Adapter', $config['handler'] );
		$this->assertSame( 'create_lead', $config['handler']->get_tool_slug() );

		// Execution routes through the adapter — never a command-routing
		// failure. Depending on the bootstrap, the backing tool either runs
		// (returning its envelope) or reports tool_unavailable.
		$result = $this->handler->execute(
			'/lead-add --email=test@example.com',
			array(
				'user_id' => $this->user_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->assertNotEquals( 'command_not_found', $result->get_error_code() );
			$this->assertNotEquals( 'command_execution_error', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}
}
