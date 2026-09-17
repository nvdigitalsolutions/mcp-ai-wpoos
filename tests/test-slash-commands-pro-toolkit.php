<?php
/**
 * Tests for Pro Toolkit Slash Commands (declarative tool-backed registry)
 *
 * Verifies the reworked toolkit command registry: commands are declarative
 * wrappers over the MCP tool registry, placeholder commands are gone, and
 * documentation/capability metadata survives registration.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-tool-adapter.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-toolkit-manager.php';

		// Initialize slash commands.
		wp_mcp_ai_init_slash_commands();

		// Get toolkit manager instance.
		$this->toolkit_manager = WP_MCP_AI_Slash_Command_Toolkit_Manager::get_instance();

		// The manager registers toolkit commands on the init hook (priority 25),
		// which the test harness does not re-fire; register them manually
		// against the freshly created handler.
		$this->toolkit_manager->register_toolkit_commands();
	}

	/**
	 * Test toolkit manager is initialized.
	 */
	public function test_toolkit_manager_initialized() {
		$this->assertNotNull( $this->toolkit_manager );
		$this->assertInstanceOf( 'WP_MCP_AI_Slash_Command_Toolkit_Manager', $this->toolkit_manager );
	}

	/**
	 * Test tool-backed commands are registered.
	 */
	public function test_tool_backed_commands_registered() {
		$handler  = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_commands();

		foreach ( array( 'abandoned-recover', 'social-post', 'video-compress', 'lead-add', 'booking-create', 'chart-create' ) as $name ) {
			$this->assertArrayHasKey( $name, $commands, "Expected /{$name} to be registered." );
		}
	}

	/**
	 * Test placeholder commands were purged.
	 */
	public function test_placeholder_commands_purged() {
		$handler  = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_commands();

		foreach ( array(
			'upsell-suggest',
			'crosssell-suggest',
			'hashtag-suggest',
			'video-subtitle',
			'video-template',
			'video-analytics',
			'social-calendar',
			'wholesale-pricing',
			'funnel-analyze',
			'aitool-create',
			'model-deploy',
		) as $name ) {
			$this->assertArrayNotHasKey( $name, $commands, "Placeholder /{$name} should have been removed." );
		}
	}

	/**
	 * Test every registered toolkit command is backed by a tool slug.
	 */
	public function test_all_toolkit_commands_are_tool_backed() {
		$handler      = wp_mcp_ai_get_slash_command_handler();
		$all_commands = $handler->get_commands();
		$toolkit_defs = $this->toolkit_manager->get_all_commands_by_toolkit();

		foreach ( $toolkit_defs as $toolkit ) {
			foreach ( $toolkit['commands'] as $def ) {
				$name = $def['name'];
				$this->assertArrayHasKey( $name, $all_commands, "Registered toolkit command /{$name} missing from handler." );
				$this->assertNotEmpty( $def['config']['tool'], "Command /{$name} must declare a tool slug." );
				$this->assertArrayHasKey( 'usage', $def['config'], "Command /{$name} must declare usage." );
				$this->assertArrayHasKey( 'parameters', $def['config'], "Command /{$name} must declare parameters." );
			}
		}
	}

	/**
	 * Test command parameter documentation.
	 */
	public function test_command_parameter_documentation() {
		$handler  = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_commands();

		// social-post has proper documentation.
		$this->assertArrayHasKey( 'social-post', $commands );
		$command = $commands['social-post'];
		$this->assertArrayHasKey( 'usage', $command );
		$this->assertArrayHasKey( 'parameters', $command );
		$this->assertNotEmpty( $command['parameters'] );

		// video-compress has proper documentation.
		$this->assertArrayHasKey( 'video-compress', $commands );
		$command = $commands['video-compress'];
		$this->assertArrayHasKey( 'usage', $command );
		$this->assertArrayHasKey( 'parameters', $command );
		$this->assertNotEmpty( $command['parameters'] );
	}

	/**
	 * Test command capability requirements.
	 */
	public function test_command_capability_requirements() {
		$handler  = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_commands();

		// E-commerce commands should require manage_woocommerce.
		$this->assertArrayHasKey( 'abandoned-recover', $commands );
		$this->assertEquals( 'manage_woocommerce', $commands['abandoned-recover']['capability'] );

		// Social media commands should require edit_posts.
		$this->assertArrayHasKey( 'social-post', $commands );
		$this->assertEquals( 'edit_posts', $commands['social-post']['capability'] );

		// Video commands should require upload_files.
		$this->assertArrayHasKey( 'video-compress', $commands );
		$this->assertEquals( 'upload_files', $commands['video-compress']['capability'] );
	}

	/**
	 * Test handler wraps tool-backed commands in the declarative adapter.
	 */
	public function test_registered_handlers_are_tool_adapters() {
		$handler  = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_commands();

		$this->assertInstanceOf( 'WP_MCP_AI_Slash_Command_Tool_Adapter', $commands['lead-add']['handler'] );
		$this->assertSame( 'create_lead', $commands['lead-add']['handler']->get_tool_slug() );
	}
}
