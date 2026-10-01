<?php
/**
 * Tests for the agent identity resolver.
 *
 * Covers the store/recall agent-key bridging added in the memory-layer
 * identity fix: virtual agent keys resolve to the canonical assistant post
 * ID (via execution context or the persisted alias table) and the reverse
 * lookup powers the drawer's alias-bucket merge.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

/**
 * Test case for WP_MCP_AI_Agent_Identity_Resolver.
 */
class Test_Agent_Identity_Resolver extends WP_UnitTestCase {

	/**
	 * Wipe the alias table before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( WP_MCP_AI_Agent_Identity_Resolver::OPTION_KEY );
	}

	/**
	 * Wipe the alias table after each test.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Agent_Identity_Resolver::OPTION_KEY );
		parent::tearDown();
	}

	/**
	 * Numeric post IDs are already canonical and pass through untouched.
	 */
	public function test_numeric_agent_id_passes_through() {
		$resolved = WP_MCP_AI_Agent_Identity_Resolver::resolve( 953, array() );

		$this->assertSame( 953, $resolved['agent_id'] );
		$this->assertTrue( $resolved['canonical'] );
		$this->assertFalse( $resolved['resolved'] );
	}

	/**
	 * A virtual key resolves to the execution-context assistant_id and the
	 * alias mapping is persisted for future lookups.
	 */
	public function test_virtual_key_resolves_to_context_assistant_id() {
		$resolved = WP_MCP_AI_Agent_Identity_Resolver::resolve(
			'nvoos-pro-spa-memory-drawer',
			array( 'assistant_id' => 953 )
		);

		$this->assertSame( 953, $resolved['agent_id'] );
		$this->assertTrue( $resolved['resolved'] );
		$this->assertSame( 'nvoos-pro-spa-memory-drawer', $resolved['original'] );

		// The alias map stores canonical IDs as strings.
		$this->assertSame( '953', WP_MCP_AI_Agent_Identity_Resolver::get_canonical( 'nvoos-pro-spa-memory-drawer' ) );
	}

	/**
	 * Once recorded, the alias table resolves without execution context.
	 */
	public function test_alias_table_resolves_without_context() {
		WP_MCP_AI_Agent_Identity_Resolver::register_alias( 'virtual_planner_1', 953 );

		$resolved = WP_MCP_AI_Agent_Identity_Resolver::resolve( 'virtual_planner_1', array() );

		$this->assertSame( 953, $resolved['agent_id'] );
		$this->assertTrue( $resolved['resolved'] );
	}

	/**
	 * The reverse lookup returns every alias mapped to a canonical ID.
	 */
	public function test_get_aliases_reverse_lookup() {
		WP_MCP_AI_Agent_Identity_Resolver::register_alias( 'alias-a', 953 );
		WP_MCP_AI_Agent_Identity_Resolver::register_alias( 'alias-b', 953 );
		WP_MCP_AI_Agent_Identity_Resolver::register_alias( 'other-agent', 954 );

		$aliases = WP_MCP_AI_Agent_Identity_Resolver::get_aliases( 953 );

		$this->assertContains( 'alias-a', $aliases );
		$this->assertContains( 'alias-b', $aliases );
		$this->assertNotContains( 'other-agent', $aliases );
	}

	/**
	 * An unmapped virtual key passes through unchanged so no data is lost.
	 */
	public function test_unresolvable_virtual_key_passes_through() {
		$resolved = WP_MCP_AI_Agent_Identity_Resolver::resolve( 'mystery-key', array() );

		$this->assertSame( 'mystery-key', $resolved['agent_id'] );
		$this->assertFalse( $resolved['resolved'] );
		$this->assertFalse( $resolved['canonical'] );
	}

	/**
	 * A self-mapping (alias === canonical) is never recorded.
	 */
	public function test_self_mapping_is_ignored() {
		$this->assertFalse( WP_MCP_AI_Agent_Identity_Resolver::register_alias( '953', 953 ) );
		$this->assertSame( array(), WP_MCP_AI_Agent_Identity_Resolver::get_aliases( 953 ) );
	}

	/**
	 * An explicit argument is preferred over the context identity and is
	 * reported as the parameter resolution source.
	 */
	public function test_resolve_for_execution_prefers_explicit_argument() {
		$resolved = WP_MCP_AI_Agent_Identity_Resolver::resolve_for_execution(
			8859,
			array( 'assistant_id' => 953 )
		);

		$this->assertSame( 8859, $resolved['agent_id'] );
		$this->assertSame( 'parameter', $resolved['resolution_source'] );
	}

	/**
	 * An omitted argument resolves to the context assistant id — the runtime
	 * identity the model must not be asked to guess.
	 */
	public function test_resolve_for_execution_falls_back_to_context() {
		$resolved = WP_MCP_AI_Agent_Identity_Resolver::resolve_for_execution(
			null,
			array( 'assistant_id' => 953 )
		);

		$this->assertSame( 953, $resolved['agent_id'] );
		$this->assertSame( 'context', $resolved['resolution_source'] );
		$this->assertTrue( $resolved['canonical'] );
	}

	/**
	 * Empty strings, zero and null are all treated as "not supplied".
	 */
	public function test_resolve_for_execution_treats_falsy_values_as_omitted() {
		foreach ( array( null, '', 0, '0' ) as $supplied ) {
			$resolved = WP_MCP_AI_Agent_Identity_Resolver::resolve_for_execution(
				$supplied,
				array( 'assistant_id' => 953 )
			);

			$this->assertSame( 953, $resolved['agent_id'], 'Falsy argument must take the context path.' );
			$this->assertSame( 'context', $resolved['resolution_source'] );
		}
	}

	/**
	 * With neither an argument nor a context identity the result is empty —
	 * callers must fail loudly, never guess.
	 */
	public function test_resolve_for_execution_returns_empty_without_identity() {
		$resolved = WP_MCP_AI_Agent_Identity_Resolver::resolve_for_execution( null, array() );

		$this->assertSame( '', $resolved['agent_id'] );
		$this->assertSame( '', $resolved['resolution_source'] );
	}

	/**
	 * Own-memory access is always allowed.
	 */
	public function test_check_scope_allows_own_memory() {
		$error = WP_MCP_AI_Agent_Identity_Resolver::check_scope(
			953,
			array( 'assistant_id' => 953 ),
			1
		);

		$this->assertNull( $error );
	}

	/**
	 * Cross-agent access without manage_options is denied with a 403.
	 */
	public function test_check_scope_denies_cross_agent_without_manage_options() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$error = WP_MCP_AI_Agent_Identity_Resolver::check_scope(
			8859,
			array( 'assistant_id' => 953 ),
			$subscriber
		);

		$this->assertWPError( $error );
		$this->assertSame( 'mcp_ai_memory_scope_denied', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
	}

	/**
	 * Cross-agent access with manage_options is allowed.
	 */
	public function test_check_scope_allows_cross_agent_for_administrator() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$error = WP_MCP_AI_Agent_Identity_Resolver::check_scope(
			8859,
			array( 'assistant_id' => 953 ),
			$admin
		);

		$this->assertNull( $error );
	}

	/**
	 * When the runtime identity is unknown (no assistant_id in context) the
	 * check is a no-op so legacy direct callers keep working.
	 */
	public function test_check_scope_noops_without_runtime_identity() {
		$error = WP_MCP_AI_Agent_Identity_Resolver::check_scope( 8859, array(), 1 );

		$this->assertNull( $error );
	}
}
