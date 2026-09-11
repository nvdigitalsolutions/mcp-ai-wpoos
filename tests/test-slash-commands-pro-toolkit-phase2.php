<?php
/**
 * Test Slash Command Tool Adapter (Phase 2)
 *
 * Unit coverage for WP_MCP_AI_Slash_Command_Tool_Adapter: argument mapping,
 * defaults, UI-flag stripping, error passthrough, payload normalisation and
 * render callbacks.
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
					'number' => array( 'type' => 'integer' ),
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
 * Tool Adapter Test Case
 */
class Test_Slash_Commands_Pro_Toolkit_Phase2 extends WP_UnitTestCase {

	/**
	 * Editor user ID used as execution context.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-tool-adapter.php';

		WP_MCP_AI_Stub_Echo_Tool::$last_arguments = array();
		WP_MCP_AI_Stub_Echo_Tool::$result         = null;

		$this->user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		WP_MCP_AI_Tool_Registry::get_instance()->register_tool( new WP_MCP_AI_Stub_Echo_Tool() );
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
	 * Test named-argument mapping plus defaults.
	 */
	public function test_named_argument_mapping_and_defaults() {
		$adapter = new WP_MCP_AI_Slash_Command_Tool_Adapter(
			'stub_echo_tool',
			array(
				'arg_map'  => array(
					'first-name' => 'first',
					'count'      => 'number',
				),
				'defaults' => array( 'second' => 'fallback' ),
			)
		);

		$result = $adapter(
			array(
				'first-name' => 'Ada',
				'count'      => 3,
			),
			array(),
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Ada', WP_MCP_AI_Stub_Echo_Tool::$last_arguments['first'] );
		$this->assertSame( 3, WP_MCP_AI_Stub_Echo_Tool::$last_arguments['number'] );
		$this->assertSame( 'fallback', WP_MCP_AI_Stub_Echo_Tool::$last_arguments['second'] );
	}

	/**
	 * Test positional argument mapping.
	 */
	public function test_positional_argument_mapping() {
		$adapter = new WP_MCP_AI_Slash_Command_Tool_Adapter(
			'stub_echo_tool',
			array(
				'positional' => array(
					0 => 'first',
					1 => 'second',
				),
			)
		);

		$adapter( array( 'alpha', 'beta' ), array(), array( 'user_id' => $this->user_id ) );

		$this->assertSame( 'alpha', WP_MCP_AI_Stub_Echo_Tool::$last_arguments['first'] );
		$this->assertSame( 'beta', WP_MCP_AI_Stub_Echo_Tool::$last_arguments['second'] );
	}

	/**
	 * Test UI flags are never forwarded to the tool.
	 */
	public function test_ui_flags_are_stripped() {
		$adapter = new WP_MCP_AI_Slash_Command_Tool_Adapter( 'stub_echo_tool', array() );

		$adapter(
			array(
				'first'  => 'ok',
				'json'   => true,
				'format' => 'text',
			),
			array(),
			array( 'user_id' => $this->user_id )
		);

		$this->assertArrayHasKey( 'first', WP_MCP_AI_Stub_Echo_Tool::$last_arguments );
		$this->assertArrayNotHasKey( 'json', WP_MCP_AI_Stub_Echo_Tool::$last_arguments );
		$this->assertArrayNotHasKey( 'format', WP_MCP_AI_Stub_Echo_Tool::$last_arguments );
	}

	/**
	 * Test tool WP_Error passthrough.
	 */
	public function test_tool_error_passthrough() {
		WP_MCP_AI_Stub_Echo_Tool::$result = new WP_Error( 'stub_failure', 'Stub exploded.' );

		$adapter = new WP_MCP_AI_Slash_Command_Tool_Adapter( 'stub_echo_tool', array() );
		$result  = $adapter( array(), array(), array( 'user_id' => $this->user_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'stub_failure', $result->get_error_code() );
	}

	/**
	 * Test raw payloads are normalised to the canonical envelope.
	 */
	public function test_raw_payload_normalisation() {
		WP_MCP_AI_Stub_Echo_Tool::$result = array( 'rows' => array( 1, 2, 3 ) );

		$adapter = new WP_MCP_AI_Slash_Command_Tool_Adapter( 'stub_echo_tool', array() );
		$result  = $adapter( array(), array(), array( 'user_id' => $this->user_id ) );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertSame( array( 1, 2, 3 ), $result['data']['rows'] );
	}

	/**
	 * Test unregistered tool yields a WP_Error.
	 */
	public function test_unregistered_tool_error() {
		$adapter = new WP_MCP_AI_Slash_Command_Tool_Adapter( 'tool_that_does_not_exist', array() );
		$result  = $adapter( array(), array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'tool_unavailable', $result->get_error_code() );
	}

	/**
	 * Test render callable receives the tool result.
	 */
	public function test_render_callable() {
		$adapter = new WP_MCP_AI_Slash_Command_Tool_Adapter(
			'stub_echo_tool',
			array(
				'render' => function ( $tool_result ) {
					return array(
						'success' => true,
						'message' => 'Rendered: ' . $tool_result['message'],
						'data'    => array( 'rendered' => true ),
					);
				},
			)
		);

		$result = $adapter( array(), array(), array( 'user_id' => $this->user_id ) );

		$this->assertSame( 'Rendered: Echoed.', $result['message'] );
		$this->assertTrue( $result['data']['rendered'] );
	}

	/**
	 * Test the adapter is invocable (handler contract).
	 */
	public function test_adapter_is_callable() {
		$adapter = new WP_MCP_AI_Slash_Command_Tool_Adapter( 'stub_echo_tool', array() );
		$this->assertTrue( is_callable( $adapter ) );
	}
}
