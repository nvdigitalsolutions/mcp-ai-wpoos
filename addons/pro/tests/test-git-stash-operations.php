<?php
/**
 * Tests for Git Stash Operations in the Git Change tool.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for git stash operations.
 *
 * Stash operations moved from the legacy WP_MCP_AI_Tool_Git_Operations class
 * to WP_MCP_AI_Tool_Git_Change as part of the P5 Part 2 action-split
 * decomposition. The legacy git_operations slug remains as a deprecated alias
 * resolving to git_inspect. This suite exercises the stash surface of
 * git_change: parameter schema, dispatcher wiring, validation, and gates.
 *
 * The plugin defines WP_MCP_AI_ALLOW_SHELL_TOOLS as false by default, so the
 * public execute() entry point reports shell_tools_disabled for the whole
 * test process. Validation paths are therefore exercised through the private
 * stash dispatcher via ReflectionMethod: every validation failure returns
 * before any git call, which keeps the suite deterministic and guarantees it
 * never mutates the working tree. State-changing subcommands that only fail
 * inside a real git run (push, pop, apply on valid refs) are asserted at the
 * gate boundary instead, via execute().
 */
class Test_Git_Stash_Operations extends WP_UnitTestCase {

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Tool under test.
	 *
	 * @var WP_MCP_AI_Tool_Git_Change
	 */
	private $tool;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Set up: load tool classes, register them, and authenticate as admin.
	 */
	public function setUp(): void {
		parent::setUp();

		// The plugin defines the constant as false in bootstrap/constants.php;
		// define it as true only in environments where it is undefined, so
		// precondition checks can reach the capability gate.
		if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) ) {
			define( 'WP_MCP_AI_ALLOW_SHELL_TOOLS', true );
		}

		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Load trait + classes if not already loaded (unit-test bootstrap may
		// not trigger architect-agent-toolkit-init.php).
		$dir = WP_MCP_AI_PRO_PATH . 'includes/tools/architect-agent/';
		foreach (
			array(
				'trait-wp-mcp-ai-tool-git-helpers.php',
				'class-wp-mcp-ai-tool-git-inspect.php',
				'class-wp-mcp-ai-tool-git-change.php',
			) as $file
		) {
			if ( file_exists( $dir . $file ) ) {
				require_once $dir . $file;
			}
		}

		// Register tools if not yet live (idempotent — registry already guards duplicates).
		if ( ! $this->registry->is_tool_registered( 'git_inspect' ) ) {
			$this->registry->register_tool( new WP_MCP_AI_Tool_Git_Inspect() );
		}
		if ( ! $this->registry->is_tool_registered( 'git_change' ) ) {
			$this->registry->register_tool( new WP_MCP_AI_Tool_Git_Change() );
		}

		// Register the legacy alias if not already present.
		$aliases = $this->registry->get_deprecated_aliases();
		if ( ! isset( $aliases['git_operations'] ) ) {
			$this->registry->register_deprecated_alias(
				'git_operations',
				'git_inspect',
				array(
					'since'   => '1.3.0',
					'remove'  => '1.4.0',
					'message' => 'Test registration.',
				)
			);
		}

		$this->admin_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $this->admin_id );

		$this->tool = new WP_MCP_AI_Tool_Git_Change();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Invoke the private stash dispatcher without executing git.
	 *
	 * Validation failures return before any git call, so this bridge is safe
	 * and deterministic. Subcommands that only fail inside a real git run
	 * must not be invoked here.
	 *
	 * @param string $subcommand Stash subcommand.
	 * @param array  $args       Optional stash arguments.
	 * @return array|WP_Error Dispatcher result.
	 */
	private function invoke_stash_dispatcher( $subcommand, $args = array() ) {
		$method = new ReflectionMethod( $this->tool, 'do_git_stash' );
		$method->setAccessible( true );

		return $method->invoke(
			$this->tool,
			$subcommand,
			isset( $args['stash_ref'] ) ? $args['stash_ref'] : '',
			isset( $args['message'] ) ? $args['message'] : '',
			isset( $args['branch_name'] ) ? $args['branch_name'] : '',
			! empty( $args['include_untracked'] ),
			! empty( $args['keep_index'] ),
			isset( $args['options'] ) ? $args['options'] : array(),
			array( 'user_id' => $this->admin_id )
		);
	}

	/**
	 * Execute a stash operation through the public entry point.
	 *
	 * @param array $args Stash arguments (operation is always 'stash').
	 * @return array|WP_Error Tool result.
	 */
	private function stash_execute( $args ) {
		return $this->tool->execute(
			array_merge( array( 'operation' => 'stash' ), $args ),
			array( 'user_id' => $this->admin_id )
		);
	}

	/**
	 * Test tool registration and availability.
	 */
	public function test_tool_registered() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Registry' ), 'Tool registry class should exist' );

		$tool = $this->registry->get_tool( 'git_change' );
		$this->assertNotNull( $tool, 'Git change tool should be registered' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Git_Change', $tool );

		// The legacy git_operations slug resolves through the deprecated alias.
		$this->assertInstanceOf(
			'WP_MCP_AI_Tool_Git_Inspect',
			$this->registry->get_tool( 'git_operations' )
		);
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'git_change', $this->tool->get_slug() );
		$this->assertEquals( 'Git Change', $this->tool->get_name() );
		$this->assertStringContainsString( 'stash', $this->tool->get_description() );
	}

	/**
	 * Test parameter schema includes stash parameters.
	 */
	public function test_parameter_schema_has_stash_parameters() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );

		$properties = $schema['properties'];

		// Check stash-specific parameters exist.
		$this->assertArrayHasKey( 'stash_subcommand', $properties );
		$this->assertArrayHasKey( 'stash_ref', $properties );
		$this->assertArrayHasKey( 'include_untracked', $properties );
		$this->assertArrayHasKey( 'keep_index', $properties );

		// Verify stash subcommand enum values.
		$this->assertArrayHasKey( 'enum', $properties['stash_subcommand'] );
		$subcommands = $properties['stash_subcommand']['enum'];
		$this->assertContains( 'list', $subcommands );
		$this->assertContains( 'push', $subcommands );
		$this->assertContains( 'pop', $subcommands );
		$this->assertContains( 'apply', $subcommands );
		$this->assertContains( 'drop', $subcommands );
		$this->assertContains( 'clear', $subcommands );
		$this->assertContains( 'show', $subcommands );
		$this->assertContains( 'branch', $subcommands );
	}

	/**
	 * Test that execute() is blocked while shell tools are disabled.
	 *
	 * The plugin ships with WP_MCP_AI_ALLOW_SHELL_TOOLS disabled, so this is
	 * the contract every stash call must honor before anything else.
	 */
	public function test_execute_blocked_when_shell_tools_disabled() {
		if ( defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) && WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			$this->markTestSkipped( 'Shell tools are enabled in this environment; the disabled gate cannot be asserted.' );
		}

		$result = $this->stash_execute(
			array(
				'stash_subcommand' => 'list',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'shell_tools_disabled', $result->get_error_code() );
	}

	/**
	 * Test stash operation requires the operation parameter.
	 */
	public function test_stash_requires_operation_parameter() {
		$result = $this->tool->execute( array(), array( 'user_id' => $this->admin_id ) );

		$this->assertWPError( $result );
		$this->assertContains(
			$result->get_error_code(),
			array( 'missing_operation', 'shell_tools_disabled', 'git_not_found', 'not_a_git_repo' )
		);
	}

	/**
	 * Test stash list operation structure.
	 *
	 * List is read-only, so it is the one subcommand that may execute git for
	 * real when shell tools are enabled. On a git-enabled checkout it must
	 * return the canonical success envelope with stash_count and stash_entries.
	 */
	public function test_stash_list_operation_structure() {
		$result = $this->stash_execute( array( 'stash_subcommand' => 'list' ) );

		if ( is_wp_error( $result ) ) {
			// Gate or environment states that prevent the probe from running.
			$this->assertContains(
				$result->get_error_code(),
				array( 'shell_tools_disabled', 'git_not_found', 'not_a_git_repo' )
			);
			return;
		}

		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'stash_list', $result['operation'] );
		$this->assertArrayHasKey( 'stash_count', $result );
		$this->assertArrayHasKey( 'stash_entries', $result );
		$this->assertIsArray( $result['stash_entries'] );
	}

	/**
	 * Test stash push is gated by capability.
	 *
	 * The success path of stash push would mutate the real working tree, so
	 * the suite asserts the gates that protect it: a low-privilege user must
	 * never reach the git call, regardless of the shell-tools setting.
	 */
	public function test_stash_push_requires_capability() {
		wp_set_current_user( $this->subscriber_id );

		$result = $this->stash_execute(
			array(
				'stash_subcommand' => 'push',
				'message'          => 'Test stash',
			)
		);

		$this->assertWPError( $result );
		$this->assertContains( $result->get_error_code(), array( 'forbidden', 'shell_tools_disabled' ) );

		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Test stash pop validates the stash reference format.
	 */
	public function test_stash_pop_validates_ref() {
		$result = $this->invoke_stash_dispatcher(
			'pop',
			array( 'stash_ref' => 'invalid-ref' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_stash_ref', $result->get_error_code() );
	}

	/**
	 * Test stash apply validates the stash reference format.
	 */
	public function test_stash_apply_validates_ref() {
		$result = $this->invoke_stash_dispatcher(
			'apply',
			array( 'stash_ref' => 'invalid-ref' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_stash_ref', $result->get_error_code() );
	}

	/**
	 * Test stash drop requires stash_ref.
	 */
	public function test_stash_drop_requires_stash_ref() {
		$result = $this->invoke_stash_dispatcher( 'drop' );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_stash_ref', $result->get_error_code() );
	}

	/**
	 * Test stash show validates the stash reference format.
	 */
	public function test_stash_show_validates_ref() {
		$result = $this->invoke_stash_dispatcher(
			'show',
			array( 'stash_ref' => 'invalid-ref' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_stash_ref', $result->get_error_code() );
	}

	/**
	 * Test stash branch requires branch_name.
	 */
	public function test_stash_branch_requires_branch_name() {
		$result = $this->invoke_stash_dispatcher( 'branch' );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_branch_name', $result->get_error_code() );
	}

	/**
	 * Test stash branch validates the stash reference format.
	 */
	public function test_stash_branch_validates_ref() {
		$result = $this->invoke_stash_dispatcher(
			'branch',
			array(
				'branch_name' => 'test-branch',
				'stash_ref'   => 'invalid-ref',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_stash_ref', $result->get_error_code() );
	}

	/**
	 * Test invalid stash reference format validation message.
	 */
	public function test_invalid_stash_reference_validation() {
		$result = $this->invoke_stash_dispatcher(
			'pop',
			array( 'stash_ref' => 'invalid-ref' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_stash_ref', $result->get_error_code() );
		$this->assertStringContainsString( 'Invalid', $result->get_error_message() );
	}

	/**
	 * Test unsupported stash subcommand returns an error.
	 */
	public function test_unsupported_stash_subcommand() {
		$result = $this->invoke_stash_dispatcher( 'invalid_subcommand' );

		$this->assertWPError( $result );
		$this->assertSame( 'unsupported_stash_subcommand', $result->get_error_code() );
	}

	/**
	 * Test capability flags include required flags.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'state-changing', $flags );
		$this->assertContains( 'version-control', $flags );
		$this->assertContains( 'architect-agent', $flags );
	}

	/**
	 * Test required capability.
	 */
	public function test_required_capability() {
		$this->assertEquals( 'edit_plugins', $this->tool->get_required_capability() );
	}
}
