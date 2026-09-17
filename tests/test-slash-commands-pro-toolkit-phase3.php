<?php
/**
 * Test Slash Command Handler Tool Wiring (Phase 3)
 *
 * End-to-end coverage of the tool-backed slash-command pipeline: handler
 * registration via register_tool_command(), parsing, capability gating,
 * delegation to the tool registry, and audit-safe error shapes.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test stub tool ships alongside its test case.
 */

if ( ! class_exists( 'WP_MCP_AI_Stub_Echo_Tool' ) ) {
	/**
	 * Minimal tool stub that echoes its received arguments back.
	 */
	class WP_MCP_AI_Stub_Echo_Tool implements WP_MCP_AI_Tool_Interface {

		/**
		 * Last executed arguments (test inspection hook).
		 *
		 * @var array
		 */
		public static $last_arguments = array();

		/**
		 * Result to return from execute().
		 *
		 * @var array|WP_Error|null
		 */
		public static $result = null;

		/**
		 * {@inheritdoc}
		 */
		public function get_slug() {
			return 'stub_echo_tool';
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_name() {
			return 'Stub Echo Tool';
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_description() {
			return 'Test stub that echoes arguments.';
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'first'  => array( 'type' => 'string' ),
					'second' => array( 'type' => 'string' ),
				),
			);
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_required_capability() {
			return 'edit_posts';
		}

		/**
		 * Execute the stub tool.
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 * @return array|WP_Error Echoed arguments or canned result.
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			self::$last_arguments = $arguments;

			if ( null !== self::$result ) {
				return self::$result;
			}

			return array(
				'success' => true,
				'message' => 'Echoed.',
				'data'    => $arguments,
			);
		}
	}
}

/**
 * Handler Tool Wiring Test Case
 */
class Test_Slash_Commands_Pro_Toolkit_Phase3 extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/slash-commands/slash-commands-init.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-handler.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-tool-adapter.php';

		wp_mcp_ai_init_slash_commands();

		WP_MCP_AI_Stub_Echo_Tool::$last_arguments = array();
		WP_MCP_AI_Stub_Echo_Tool::$result         = null;

		WP_MCP_AI_Tool_Registry::get_instance()->register_tool( new WP_MCP_AI_Stub_Echo_Tool() );

		// Register a tool-backed command through the public handler API.
		$handler = wp_mcp_ai_get_slash_command_handler();
		$handler->register_tool_command(
			'echo-test',
			array(
				'tool'        => 'stub_echo_tool',
				'tool_config' => array(
					'arg_map' => array( 'first-name' => 'first' ),
				),
				'description' => __( 'Test tool-backed command.', 'mcp-ai-wpoos' ),
				'usage'       => '/echo-test --first-name=Ada',
				'capability'  => 'edit_posts',
				'parameters'  => array(),
			)
		);
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Tool_Registry::get_instance()->unregister_tool( 'stub_echo_tool' );
		WP_MCP_AI_Stub_Echo_Tool::$result = null;
		parent::tearDown();
	}

	/**
	 * Test register_tool_command wires a tool adapter as the handler.
	 */
	public function test_register_tool_command_wires_adapter() {
		$handler = wp_mcp_ai_get_slash_command_handler();
		$command = $handler->get_command( 'echo-test' );

		$this->assertNotFalse( $command );
		$this->assertInstanceOf( 'WP_MCP_AI_Slash_Command_Tool_Adapter', $command['handler'] );
		$this->assertSame( 'stub_echo_tool', $command['handler']->get_tool_slug() );
	}

	/**
	 * Test end-to-end execution parses input and delegates to the tool.
	 */
	public function test_end_to_end_execution() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = wp_mcp_ai_execute_slash_command(
			'/echo-test --first-name=Ada',
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Ada', WP_MCP_AI_Stub_Echo_Tool::$last_arguments['first'] );
	}

	/**
	 * Test capability gating refuses execution before the tool runs.
	 */
	public function test_capability_refusal() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = wp_mcp_ai_execute_slash_command(
			'/echo-test --first-name=Ada',
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
		$this->assertEmpty( WP_MCP_AI_Stub_Echo_Tool::$last_arguments );
	}

	/**
	 * Test tool failures surface as WP_Error from the command.
	 */
	public function test_tool_failure_surfaces_as_wp_error() {
		WP_MCP_AI_Stub_Echo_Tool::$result = new WP_Error( 'stub_failure', 'Stub exploded.' );

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = wp_mcp_ai_execute_slash_command(
			'/echo-test --first-name=Ada',
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'stub_failure', $result->get_error_code() );
	}

	/**
	 * Test unknown commands still return the standard not-found error.
	 */
	public function test_unknown_command_error() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = wp_mcp_ai_execute_slash_command(
			'/definitely-not-a-command',
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'command_not_found', $result->get_error_code() );
	}
}
