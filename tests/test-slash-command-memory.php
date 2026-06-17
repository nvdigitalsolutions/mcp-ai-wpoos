<?php
/**
 * Tests for WP_MCP_AI_Slash_Command_Memory.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// Test stub: a minimal tool that records the args/context passed to execute().
if ( ! class_exists( 'Test_Slash_Memory_Stub_Tool' ) ) {
	class Test_Slash_Memory_Stub_Tool implements WP_MCP_AI_Tool_Interface {
		use WP_MCP_AI_Tool_Default_Capability;

		public $slug;
		public $last_args    = null;
		public $last_context = null;
		public $return_value = array( 'ok' => true );

		public function __construct( $slug ) {
			$this->slug = $slug;
		}
		public function get_slug() {
			return $this->slug;
		}
		public function get_name() {
			return $this->slug; }
		public function get_description() {
			return 'stub'; }
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(),
			);
		}
		public function execute( array $arguments = array(), array $context = array() ) {
			$this->last_args    = $arguments;
			$this->last_context = $context;
			return $this->return_value;
		}
	}
}

/**
 * Test class for the /remember, /forget, /scope slash commands.
 */
class Test_Slash_Command_Memory extends WP_UnitTestCase {

	/**
	 * Command instance under test.
	 *
	 * @var WP_MCP_AI_Slash_Command_Memory
	 */
	private $command;

	/**
	 * Editor user with edit_posts capability.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Subscriber user (no edit_posts).
	 *
	 * @var int
	 */
	private $subscriber_id;

	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-memory.php';

		// Force chat-memory to be considered enabled regardless of site/user
		// preferences so we can exercise the dispatch path.
		add_filter( 'wp_mcp_ai_chat_memory_enabled', '__return_true', 999 );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$ref      = new ReflectionClass( $registry );
		$tools    = $ref->getProperty( 'tools' );
		$tools->setAccessible( true );
		$existing = $tools->getValue( $registry );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$existing['store_agent_context']      = new Test_Slash_Memory_Stub_Tool( 'store_agent_context' );
		$existing['manage_context_lifecycle'] = new Test_Slash_Memory_Stub_Tool( 'manage_context_lifecycle' );
		$tools->setValue( $registry, $existing );

		$this->command       = new WP_MCP_AI_Slash_Command_Memory();
		$this->editor_id     = $this->factory->user->create( array( 'role' => 'editor' ) );
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
	}

	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_chat_memory_enabled', '__return_true', 999 );
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->unregister_tool( 'store_agent_context' );
		$registry->unregister_tool( 'manage_context_lifecycle' );
		parent::tearDown();
	}

	/**
	 * Subscribers (no edit_posts) → insufficient_capability on /remember.
	 */
	public function test_remember_blocks_subscriber() {
		wp_set_current_user( $this->subscriber_id );

		$result = $this->command->remember(
			array( 'remember', 'this' ),
			array(),
			array( 'user_id' => $this->subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * /remember with no text → missing_text WP_Error.
	 */
	public function test_remember_requires_text() {
		wp_set_current_user( $this->editor_id );

		$result = $this->command->remember(
			array(),
			array(),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_text', $result->get_error_code() );
	}

	/**
	 * Happy path: text + tags + importance forwarded to store_agent_context.
	 */
	public function test_remember_dispatches_to_store_agent_context_tool() {
		wp_set_current_user( $this->editor_id );

		/** @var Test_Slash_Memory_Stub_Tool $tool */
		$tool = WP_MCP_AI_Tool_Registry::get_instance()->get_tool( 'store_agent_context' );
		$this->assertInstanceOf( 'Test_Slash_Memory_Stub_Tool', $tool );

		$result = $this->command->remember(
			array( 'remember', 'this', 'fact' ),
			array(
				'tag'        => 'foo,bar',
				'importance' => 'high',
				'wing'       => 'project-a',
				'room'       => 'planning',
			),
			array(
				'user_id'      => $this->editor_id,
				'assistant_id' => 0,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'memory.stored', $result['type'] );

		$this->assertNotNull( $tool->last_args );
		$this->assertSame( 'user_note', $tool->last_args['context_type'] );
		$this->assertSame( 'remember this fact', $tool->last_args['context_data']['content'] );
		$this->assertSame( 'high', $tool->last_args['context_data']['importance'] );
		$this->assertSame( array( 'foo', 'bar' ), $tool->last_args['context_data']['tags'] );
		$this->assertSame( 'project-a', $tool->last_args['wing'] );
		$this->assertSame( 'planning', $tool->last_args['room'] );
		$this->assertTrue( $tool->last_args['verbatim'] );
		$this->assertSame( 'slash:remember', $tool->last_context['source'] );
	}

	/**
	 * Invalid importance falls back to "medium" via the allowlist.
	 */
	public function test_remember_normalises_invalid_importance() {
		wp_set_current_user( $this->editor_id );

		$tool = WP_MCP_AI_Tool_Registry::get_instance()->get_tool( 'store_agent_context' );
		$this->command->remember(
			array( 'note' ),
			array( 'importance' => 'galactic' ),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertSame( 'medium', $tool->last_args['context_data']['importance'] );
	}

	/**
	 * --summary flag flips verbatim → false.
	 */
	public function test_remember_summary_flag_disables_verbatim() {
		wp_set_current_user( $this->editor_id );

		$tool = WP_MCP_AI_Tool_Registry::get_instance()->get_tool( 'store_agent_context' );
		$this->command->remember(
			array( 'note' ),
			array( 'summary' => true ),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertFalse( $tool->last_args['verbatim'] );
	}

	/**
	 * /forget with no id → missing_id WP_Error.
	 */
	public function test_forget_requires_context_id() {
		wp_set_current_user( $this->editor_id );

		$result = $this->command->forget(
			array(),
			array(),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_id', $result->get_error_code() );
	}

	/**
	 * /forget sanitises the id and dispatches to manage_context_lifecycle.
	 */
	public function test_forget_dispatches_delete_action() {
		wp_set_current_user( $this->editor_id );

		$tool = WP_MCP_AI_Tool_Registry::get_instance()->get_tool( 'manage_context_lifecycle' );

		$result = $this->command->forget(
			array( 'ctx-123!@#abc' ),
			array(),
			array(
				'user_id'      => $this->editor_id,
				'assistant_id' => 42,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'memory.deleted', $result['type'] );
		$this->assertNotNull( $tool->last_args );
		$this->assertSame( 'delete', $tool->last_args['action'] );
		// Non-alnum/_- characters should be stripped from the id.
		$this->assertSame( 'ctx-123abc', $tool->last_args['context_id'] );
		$this->assertSame( 42, $tool->last_args['agent_id'] );
		$this->assertSame( 'slash:forget', $tool->last_context['source'] );
	}

	/**
	 * /scope with no args → memory.scope.cleared.
	 */
	public function test_scope_with_no_args_clears_scope() {
		wp_set_current_user( $this->editor_id );

		$result = $this->command->scope(
			array(),
			array(),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertSame( 'memory.scope.cleared', $result['type'] );
		$this->assertSame( '', $result['data']['wing'] );
		$this->assertSame( '', $result['data']['room'] );
	}

	/**
	 * /scope wing room → memory.scope.set with sanitised wing/room.
	 */
	public function test_scope_with_wing_and_room_returns_set_payload() {
		wp_set_current_user( $this->editor_id );

		$result = $this->command->scope(
			array( 'project-x', 'kickoff' ),
			array(),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertSame( 'memory.scope.set', $result['type'] );
		$this->assertSame( 'project-x', $result['data']['wing'] );
		$this->assertSame( 'kickoff', $result['data']['room'] );
	}
}
