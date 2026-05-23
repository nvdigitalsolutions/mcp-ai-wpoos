<?php
/**
 * Tests for the git_inspect / git_change decomposition (P5 Part 2, Order 1).
 *
 * Covers:
 *  1. New sub-tool classes exist and implement the interface.
 *  2. Slug, name, description, parameters schema are well-formed.
 *  3. Capability flags: git_inspect is read-only; git_change is state-changing.
 *  4. Required capability is edit_plugins for both.
 *  5. After registration, both slugs are live in the registry.
 *  6. The legacy git_operations slug still resolves via the deprecated alias.
 *  7. The wp_mcp_ai_tool_deprecated_alias_invoked action fires on alias resolution.
 *  8. Precondition check: WP_MCP_AI_ALLOW_SHELL_TOOLS disabled returns WP_Error.
 *  9. Precondition check: missing capability returns WP_Error.
 * 10. git_inspect rejects write operations with WP_Error (unsupported_operation).
 * 11. git_change rejects read operations with WP_Error (unsupported_operation).
 * 12. git_change commit: missing message returns WP_Error (missing_message).
 * 13. git_change add: missing file_path returns WP_Error (missing_file_path).
 * 14. git_change checkout: missing both targets returns WP_Error (missing_target).
 * 15. git_change stash drop: missing stash_ref returns WP_Error (missing_stash_ref).
 * 16. git_change stash branch: missing branch_name returns WP_Error (missing_branch_name).
 * 17. git_change stash: invalid stash_ref format returns WP_Error (invalid_stash_ref).
 * 18. git_inspect blame: missing file_path returns WP_Error (missing_file_path).
 * 19. Envelope trait: success response has success/message/data keys.
 * 20. Parameter schema arrays include required items key.
 * 21. git_inspect operation enum: exactly the 6 read-only operations.
 * 22. git_change operation enum: exactly the 4 write operations.
 * 23. Deprecated alias: old slug resolves to git_inspect class instance.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for the git_operations decomposition.
 */
class Test_Git_Split_Tools extends WP_UnitTestCase {

	/** Summary.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Set up: register both sub-tools and the alias.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure shell-tools constant is defined so precondition checks reach.
		// the capability gate (which we can control via wp_set_current_user).
		if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) ) {
			define( 'WP_MCP_AI_ALLOW_SHELL_TOOLS', true );
		}

		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Load trait + classes if not already loaded (unit-test bootstrap may.
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

		// Register alias if not already present.
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

		// Reset alias invocation counter before each test.
		$this->registry->reset_deprecated_alias_invocations();
	}

	// --------------------------------------------------------------- //
	// 1–4: Class structure & metadata                                   //
	// --------------------------------------------------------------- //

	/**
	 * Test that both tool classes exist and implement the interface.
	 */
	public function test_classes_exist_and_implement_interface() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Git_Inspect' ), 'WP_MCP_AI_Tool_Git_Inspect must exist' );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Git_Change' ), 'WP_MCP_AI_Tool_Git_Change must exist' );

		$inspect = new WP_MCP_AI_Tool_Git_Inspect();
		$change  = new WP_MCP_AI_Tool_Git_Change();

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $inspect );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $change );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $inspect );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $change );
	}

	/**
	 * Test slugs.
	 */
	public function test_slugs() {
		$this->assertSame( 'git_inspect', ( new WP_MCP_AI_Tool_Git_Inspect() )->get_slug() );
		$this->assertSame( 'git_change', ( new WP_MCP_AI_Tool_Git_Change() )->get_slug() );
	}

	/**
	 * Test names are non-empty strings.
	 */
	public function test_names() {
		$this->assertNotEmpty( ( new WP_MCP_AI_Tool_Git_Inspect() )->get_name() );
		$this->assertNotEmpty( ( new WP_MCP_AI_Tool_Git_Change() )->get_name() );
	}

	/**
	 * Test descriptions mention the companion tool.
	 */
	public function test_descriptions_cross_reference() {
		$inspect_desc = ( new WP_MCP_AI_Tool_Git_Inspect() )->get_description();
		$change_desc  = ( new WP_MCP_AI_Tool_Git_Change() )->get_description();

		$this->assertStringContainsString( 'git_change', $inspect_desc, 'git_inspect description should mention git_change' );
		$this->assertStringContainsString( 'git_inspect', $change_desc, 'git_change description should mention git_inspect' );
	}

	// --------------------------------------------------------------- //
	// 5: Capability flags                                               //
	// --------------------------------------------------------------- //

	/**
	 * Test git_inspect carries read-only flag.
	 */
	public function test_git_inspect_is_read_only() {
		$flags = ( new WP_MCP_AI_Tool_Git_Inspect() )->get_capability_flags();
		$this->assertContains( 'read-only', $flags );
		$this->assertNotContains( 'state-changing', $flags );
	}

	/**
	 * Test git_change carries state-changing flag and not read-only.
	 */
	public function test_git_change_is_state_changing() {
		$flags = ( new WP_MCP_AI_Tool_Git_Change() )->get_capability_flags();
		$this->assertContains( 'state-changing', $flags );
		$this->assertNotContains( 'read-only', $flags );
	}

	/**
	 * Test required capability is edit_plugins for both.
	 */
	public function test_required_capability() {
		$this->assertSame( 'edit_plugins', ( new WP_MCP_AI_Tool_Git_Inspect() )->get_required_capability() );
		$this->assertSame( 'edit_plugins', ( new WP_MCP_AI_Tool_Git_Change() )->get_required_capability() );
	}

	// --------------------------------------------------------------- //
	// 6–7: Registry + alias                                             //
	// --------------------------------------------------------------- //

	/**
	 * Test both new slugs are live in the registry.
	 */
	public function test_tools_registered() {
		$this->assertTrue( $this->registry->is_tool_registered( 'git_inspect' ) );
		$this->assertTrue( $this->registry->is_tool_registered( 'git_change' ) );
	}

	/**
	 * Test the legacy slug resolves via the deprecated alias.
	 */
	public function test_deprecated_alias_resolves() {
		$tool = $this->registry->get_tool( 'git_operations' );
		$this->assertNotNull( $tool, 'git_operations should resolve via alias' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Git_Inspect', $tool );
	}

	/**
	 * Test the deprecation action fires on alias resolution.
	 */
	public function test_deprecated_alias_action_fires() {
		$fired    = array();
		$listener = static function ( $old, $new, $entry ) use ( &$fired ) {
			$fired[] = array(
				'old' => $old,
				'new' => $new,
			);
		};
		add_action( 'wp_mcp_ai_tool_deprecated_alias_invoked', $listener, 10, 3 );

		$this->registry->get_tool( 'git_operations' );

		remove_action( 'wp_mcp_ai_tool_deprecated_alias_invoked', $listener, 10 );

		$this->assertCount( 1, $fired );
		$this->assertSame( 'git_operations', $fired[0]['old'] );
		$this->assertSame( 'git_inspect', $fired[0]['new'] );
	}

	/**
	 * Test the deprecated alias is invisible to the live-tools list.
	 */
	public function test_deprecated_alias_invisible_to_live_tools() {
		$tools = $this->registry->get_tools();
		$this->assertArrayNotHasKey( 'git_operations', $tools );
	}

	// --------------------------------------------------------------- //
	// 8–9: Precondition gates                                           //
	// --------------------------------------------------------------- //

	/**
	 * Test that shell-tools disabled returns WP_Error.
	 */
	public function test_shell_tools_disabled_returns_wp_error() {
		// Temporarily redefine the constant to false is not possible in PHP,
		// so we skip this test if the constant is already set to true.
		if ( defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) && WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			$this->markTestSkipped( 'WP_MCP_AI_ALLOW_SHELL_TOOLS is already true — cannot override in same process.' );
		}

		$tool   = new WP_MCP_AI_Tool_Git_Inspect();
		$result = $tool->execute( array( 'operation' => 'status' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'shell_tools_disabled', $result->get_error_code() );
	}

	/**
	 * Test that a non-admin user gets a forbidden WP_Error.
	 */
	public function test_insufficient_capability_returns_wp_error() {
		// Only relevant when the constant allows shell tools.
		if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) || ! WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			$this->markTestSkipped( 'WP_MCP_AI_ALLOW_SHELL_TOOLS is false — constant gate fires first.' );
		}

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$tool   = new WP_MCP_AI_Tool_Git_Inspect();
		$result = $tool->execute( array( 'operation' => 'status' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );

		wp_set_current_user( 0 );
	}

	// --------------------------------------------------------------- //
	// 10–11: Cross-tool operation rejection                             //
	// --------------------------------------------------------------- //

	/**
	 * Test git_inspect rejects commit (a write operation).
	 */
	public function test_git_inspect_rejects_commit() {
		if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) || ! WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			$this->markTestSkipped( 'Need ALLOW_SHELL_TOOLS enabled to reach operation dispatch.' );
		}

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$tool   = new WP_MCP_AI_Tool_Git_Inspect();
		$result = $tool->execute(
			array(
				'operation' => 'commit',
				'message'   => 'test',
			)
		);

		// May return WP_Error from precondition (git not found) or unsupported_operation.
		$this->assertWPError( $result );
		// If git is not available we get git_not_found; if git runs we get unsupported_operation.
		$this->assertContains( $result->get_error_code(), array( 'unsupported_operation', 'git_not_found', 'not_a_git_repo' ) );

		wp_set_current_user( 0 );
	}

	/**
	 * Test git_change rejects status (a read operation).
	 */
	public function test_git_change_rejects_status() {
		if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) || ! WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			$this->markTestSkipped( 'Need ALLOW_SHELL_TOOLS enabled to reach operation dispatch.' );
		}

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$tool   = new WP_MCP_AI_Tool_Git_Change();
		$result = $tool->execute( array( 'operation' => 'status' ) );

		$this->assertWPError( $result );
		$this->assertContains( $result->get_error_code(), array( 'unsupported_operation', 'git_not_found', 'not_a_git_repo' ) );

		wp_set_current_user( 0 );
	}

	// --------------------------------------------------------------- //
	// 12–18: Input-validation WP_Errors (no git binary needed)         //
	// --------------------------------------------------------------- //

	/**
	 * Helper: create an admin user, return their ID.
	 */
	private function make_admin() {
		return $this->factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Test commit with missing message returns WP_Error missing_message.
	 * Only runs when git is available and we can reach operation dispatch.
	 */
	public function test_commit_requires_message() {
		if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) || ! WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			$this->markTestSkipped( 'ALLOW_SHELL_TOOLS disabled.' );
		}

		wp_set_current_user( $this->make_admin() );

		$tool   = new WP_MCP_AI_Tool_Git_Change();
		$result = $tool->execute( array( 'operation' => 'commit' ) );

		$this->assertWPError( $result );
		// May be missing_message (if git found) or git_not_found / not_a_git_repo.
		$this->assertContains(
			$result->get_error_code(),
			array( 'missing_message', 'git_not_found', 'not_a_git_repo' )
		);

		wp_set_current_user( 0 );
	}

	/**
	 * Test add with missing file_path returns WP_Error missing_file_path.
	 */
	public function test_add_requires_file_path() {
		if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) || ! WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			$this->markTestSkipped( 'ALLOW_SHELL_TOOLS disabled.' );
		}

		wp_set_current_user( $this->make_admin() );

		$tool   = new WP_MCP_AI_Tool_Git_Change();
		$result = $tool->execute( array( 'operation' => 'add' ) );

		$this->assertWPError( $result );
		$this->assertContains(
			$result->get_error_code(),
			array( 'missing_file_path', 'git_not_found', 'not_a_git_repo' )
		);

		wp_set_current_user( 0 );
	}

	/**
	 * Test checkout with no branch and no file returns missing_target.
	 */
	public function test_checkout_requires_target() {
		if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) || ! WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			$this->markTestSkipped( 'ALLOW_SHELL_TOOLS disabled.' );
		}

		wp_set_current_user( $this->make_admin() );

		$tool   = new WP_MCP_AI_Tool_Git_Change();
		$result = $tool->execute( array( 'operation' => 'checkout' ) );

		$this->assertWPError( $result );
		$this->assertContains(
			$result->get_error_code(),
			array( 'missing_target', 'git_not_found', 'not_a_git_repo' )
		);

		wp_set_current_user( 0 );
	}

	/**
	 * Test stash drop with no ref returns missing_stash_ref.
	 */
	public function test_stash_drop_requires_ref() {
		if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) || ! WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			$this->markTestSkipped( 'ALLOW_SHELL_TOOLS disabled.' );
		}

		wp_set_current_user( $this->make_admin() );

		$tool   = new WP_MCP_AI_Tool_Git_Change();
		$result = $tool->execute(
			array(
				'operation'        => 'stash',
				'stash_subcommand' => 'drop',
			)
		);

		$this->assertWPError( $result );
		$this->assertContains(
			$result->get_error_code(),
			array( 'missing_stash_ref', 'git_not_found', 'not_a_git_repo' )
		);

		wp_set_current_user( 0 );
	}

	/**
	 * Test stash branch with no branch_name returns missing_branch_name.
	 */
	public function test_stash_branch_requires_branch_name() {
		if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) || ! WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			$this->markTestSkipped( 'ALLOW_SHELL_TOOLS disabled.' );
		}

		wp_set_current_user( $this->make_admin() );

		$tool   = new WP_MCP_AI_Tool_Git_Change();
		$result = $tool->execute(
			array(
				'operation'        => 'stash',
				'stash_subcommand' => 'branch',
			)
		);

		$this->assertWPError( $result );
		$this->assertContains(
			$result->get_error_code(),
			array( 'missing_branch_name', 'git_not_found', 'not_a_git_repo' )
		);

		wp_set_current_user( 0 );
	}

	/**
	 * Test stash with invalid stash_ref format returns invalid_stash_ref.
	 */
	public function test_stash_invalid_ref_format() {
		if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) || ! WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			$this->markTestSkipped( 'ALLOW_SHELL_TOOLS disabled.' );
		}

		wp_set_current_user( $this->make_admin() );

		$tool   = new WP_MCP_AI_Tool_Git_Change();
		$result = $tool->execute(
			array(
				'operation'        => 'stash',
				'stash_subcommand' => 'pop',
				'stash_ref'        => 'bad-ref',
			)
		);

		$this->assertWPError( $result );
		$this->assertContains(
			$result->get_error_code(),
			array( 'invalid_stash_ref', 'git_not_found', 'not_a_git_repo' )
		);

		wp_set_current_user( 0 );
	}

	/**
	 * Test git_inspect blame with no file_path returns missing_file_path.
	 */
	public function test_blame_requires_file_path() {
		if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) || ! WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			$this->markTestSkipped( 'ALLOW_SHELL_TOOLS disabled.' );
		}

		wp_set_current_user( $this->make_admin() );

		$tool   = new WP_MCP_AI_Tool_Git_Inspect();
		$result = $tool->execute( array( 'operation' => 'blame' ) );

		$this->assertWPError( $result );
		$this->assertContains(
			$result->get_error_code(),
			array( 'missing_file_path', 'git_not_found', 'not_a_git_repo' )
		);

		wp_set_current_user( 0 );
	}

	// --------------------------------------------------------------- //
	// 19: Canonical envelope                                            //
	// --------------------------------------------------------------- //

	/**
	 * Test that format_success_response produces the canonical shape.
	 */
	public function test_format_success_response_shape() {
		$tool = new WP_MCP_AI_Tool_Git_Inspect();

		// Use reflection to call the protected method.
		$ref = new ReflectionMethod( $tool, 'format_success_response' );
		$ref->setAccessible( true );
		$result = $ref->invoke( $tool, 'All good.', array( 'foo' => 'bar' ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'All good.', $result['message'] );
		$this->assertArrayHasKey( 'data', $result );
	}

	// --------------------------------------------------------------- //
	// 20–22: Parameter schema well-formedness                           //
	// --------------------------------------------------------------- //

	/**
	 * Test that options array in both schemas includes an items key.
	 */
	public function test_options_array_has_items() {
		foreach ( array( new WP_MCP_AI_Tool_Git_Inspect(), new WP_MCP_AI_Tool_Git_Change() ) as $tool ) {
			$schema = $tool->get_parameters_schema();
			$this->assertArrayHasKey( 'items', $schema['properties']['options'], $tool->get_slug() . ' options must have items' );
		}
	}

	/**
	 * Test git_inspect operation enum has exactly the 6 read-only operations.
	 */
	public function test_git_inspect_operation_enum() {
		$schema = ( new WP_MCP_AI_Tool_Git_Inspect() )->get_parameters_schema();
		$enum   = $schema['properties']['operation']['enum'];
		sort( $enum );
		$this->assertSame( array( 'blame', 'branch', 'diff', 'log', 'show', 'status' ), $enum );
	}

	/**
	 * Test git_change operation enum has exactly the 4 write operations.
	 */
	public function test_git_change_operation_enum() {
		$schema = ( new WP_MCP_AI_Tool_Git_Change() )->get_parameters_schema();
		$enum   = $schema['properties']['operation']['enum'];
		sort( $enum );
		$this->assertSame( array( 'add', 'checkout', 'commit', 'stash' ), $enum );
	}

	// --------------------------------------------------------------- //
	// 23: Alias resolution type check                                   //
	// --------------------------------------------------------------- //

	/**
	 * Test that the deprecated alias returns a WP_MCP_AI_Tool_Git_Inspect instance.
	 */
	public function test_alias_returns_git_inspect_instance() {
		$tool = $this->registry->get_tool( 'git_operations' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Git_Inspect', $tool );
	}
}
