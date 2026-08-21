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

		$this->assertSame( 953, WP_MCP_AI_Agent_Identity_Resolver::get_canonical( 'nvoos-pro-spa-memory-drawer' ) );
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
}
