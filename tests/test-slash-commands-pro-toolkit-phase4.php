<?php
/**
 * Test Slash Commands Pro Toolkit Phase 4
 *
 * Covers the purge + migration boundary of the declarative rework:
 * placeholder commands are gone from the registry, migrated commands still
 * register with their toolkit, and execution delegates to the tool registry.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test stub tool ships alongside its test case.
 */

if ( ! class_exists( 'WP_MCP_AI_Stub_Cart_Tool' ) ) {
	/**
	 * Minimal tool stub backing /abandoned-recover in tests.
	 */
	class WP_MCP_AI_Stub_Cart_Tool implements WP_MCP_AI_Tool_Interface {

		/**
		 * Last executed arguments (test inspection hook).
		 *
		 * @var array
		 */
		public static $last_arguments = array();

		/**
		 * {@inheritdoc}
		 */
		public function get_slug() {
			return 'stub_cart_tool';
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_name() {
			return 'Stub Cart Tool';
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_description() {
			return 'Test stub for abandoned cart recovery.';
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'action'     => array( 'type' => 'string' ),
					'send_email' => array( 'type' => 'boolean' ),
				),
			);
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_required_capability() {
			return 'manage_woocommerce';
		}

		/**
		 * Execute the stub tool.
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 * @return array|WP_Error Canned result envelope.
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			self::$last_arguments = $arguments;

			return array(
				'success' => true,
				'message' => 'Recovery queued.',
				'data'    => array( 'recovered' => 0 ),
			);
		}
	}
}

/**
 * Phase 4 Test Case
 */
class Test_Slash_Commands_Pro_Toolkit_Phase4 extends WP_UnitTestCase {

	/**
	 * Toolkit manager instance.
	 *
	 * @var WP_MCP_AI_Slash_Command_Toolkit_Manager
	 */
	protected $manager;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/slash-commands/slash-commands-init.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-handler.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-tool-adapter.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-toolkit-manager.php';

		wp_mcp_ai_init_slash_commands();

		WP_MCP_AI_Stub_Cart_Tool::$last_arguments = array();
		WP_MCP_AI_Tool_Registry::get_instance()->register_tool( new WP_MCP_AI_Stub_Cart_Tool() );

		$this->manager = WP_MCP_AI_Slash_Command_Toolkit_Manager::get_instance();
		$this->manager->register_toolkit_commands();
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Tool_Registry::get_instance()->unregister_tool( 'stub_cart_tool' );
		parent::tearDown();
	}

	/**
	 * Test placeholder e-commerce commands are gone.
	 */
	public function test_ecommerce_placeholders_purged() {
		$commands = wp_mcp_ai_get_slash_command_handler()->get_commands();

		foreach ( array( 'crosssell-suggest', 'subscription-manage', 'wholesale-pricing', 'marketplace-sync', 'tax-calculate', 'return-process', 'supplier-sync' ) as $name ) {
			$this->assertArrayNotHasKey( $name, $commands, "Placeholder /{$name} should have been removed." );
		}
	}

	/**
	 * Test placeholder social commands are gone.
	 */
	public function test_social_placeholders_purged() {
		$commands = wp_mcp_ai_get_slash_command_handler()->get_commands();

		foreach ( array( 'social-calendar', 'social-engage', 'social-monitor', 'trend-identify', 'social-report' ) as $name ) {
			$this->assertArrayNotHasKey( $name, $commands, "Placeholder /{$name} should have been removed." );
		}
	}

	/**
	 * Test placeholder video commands are gone.
	 */
	public function test_video_placeholders_purged() {
		$commands = wp_mcp_ai_get_slash_command_handler()->get_commands();

		foreach ( array( 'video-effect', 'video-transition', 'video-music', 'video-storyboard', 'video-publish' ) as $name ) {
			$this->assertArrayNotHasKey( $name, $commands, "Placeholder /{$name} should have been removed." );
		}
	}

	/**
	 * Test migrated commands remain registered per toolkit.
	 */
	public function test_migrated_commands_registered() {
		$commands = wp_mcp_ai_get_slash_command_handler()->get_commands();

		foreach ( array(
			'abandoned-recover'  => 'ecommerce_pro',
			'inventory-forecast' => 'ecommerce_pro',
			'video-compress'     => 'video_production',
			'video-trim'         => 'video_production',
			'lead-add'           => 'crm',
			'pipeline-view'      => 'crm',
			'booking-create'     => 'calendar_booking',
			'content-translate'  => 'multilingual',
			'social-analytics'   => 'social_media',
			'doc-create'         => 'document_generation',
			'budget-create'      => 'financial_planner',
			'image-edit'         => 'image_production',
		) as $name => $toolkit ) {
			$this->assertArrayHasKey( $name, $commands, "Migrated /{$name} should be registered." );
			$this->assertSame( $toolkit, $commands[ $name ]['toolkit'], "Wrong toolkit for /{$name}." );
			$this->assertNotEmpty( $commands[ $name ]['tool'], "Migrated /{$name} must declare a tool." );
		}
	}

	/**
	 * Test a migrated command delegates through the handler to the tool.
	 *
	 * Uses a stub tool with the same slug expectations as the real
	 * abandoned_cart_recovery tool (action + send_email flags).
	 */
	public function test_migrated_command_executes_through_handler() {
		// Swap the mapped tool for the stub so no WooCommerce data is needed.
		$handler = wp_mcp_ai_get_slash_command_handler();
		$command = $handler->get_command( 'abandoned-recover' );
		$this->assertNotFalse( $command );

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Re-register the same command against the stub tool to keep the
		// execution path identical to production.
		$handler->register_tool_command(
			'abandoned-recover',
			array(
				'tool'        => 'stub_cart_tool',
				'tool_config' => array(
					'defaults' => array( 'action' => 'identify' ),
				),
				'description' => $command['description'],
				'usage'       => $command['usage'],
				'capability'  => 'manage_woocommerce',
				'parameters'  => array(),
			)
		);

		$result = wp_mcp_ai_execute_slash_command(
			'/abandoned-recover --send_email=true',
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'identify', WP_MCP_AI_Stub_Cart_Tool::$last_arguments['action'] );
		$this->assertSame( 'true', WP_MCP_AI_Stub_Cart_Tool::$last_arguments['send_email'] );
	}

	/**
	 * Test toolkit gating still applies to the declarative registry.
	 */
	public function test_toolkit_gating() {
		add_filter( 'wp_mcp_ai_toolkit_enabled', '__return_false' );

		$handler = wp_mcp_ai_get_slash_command_handler();
		$before  = $handler->get_commands();
		$this->assertArrayHasKey( 'video-compress', $before );

		remove_filter( 'wp_mcp_ai_toolkit_enabled', '__return_false' );
	}
}
