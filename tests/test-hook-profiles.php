<?php
/**
 * Tests for WP_MCP_AI_Hook_Profiles — profile resolution and gating.
 *
 * @package WP_MCP_AI
 * @since   1.1.97
 */

/**
 * Hook-profiles test suite.
 */
class Test_Hook_Profiles extends WP_UnitTestCase {

	/**
	 * Set up fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/hooks/class-wp-mcp-ai-hook-profiles.php';

		delete_option( WP_MCP_AI_Hook_Profiles::OPTION_DEFAULT_PROFILE );
	}

	/**
	 * Tear down fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_hook_profile' );
		remove_all_filters( 'wp_mcp_ai_hook_profiles_enabled' );
		remove_all_filters( 'wp_mcp_ai_disabled_hooks' );

		delete_option( WP_MCP_AI_Hook_Profiles::OPTION_DEFAULT_PROFILE );

		parent::tearDown();
	}

	/**
	 * Profile ranks must be ordered minimal < standard < strict.
	 */
	public function test_profile_ranks_ordered() {
		$ranks = WP_MCP_AI_Hook_Profiles::get_profile_ranks();

		$this->assertSame( 0, $ranks['minimal'] );
		$this->assertSame( 1, $ranks['standard'] );
		$this->assertSame( 2, $ranks['strict'] );
	}

	/**
	 * Normalization accepts exact slugs, case-insensitively, and falls back
	 * to standard for garbage.
	 */
	public function test_normalize_profile() {
		$this->assertSame( 'minimal', WP_MCP_AI_Hook_Profiles::normalize_profile( 'minimal' ) );
		$this->assertSame( 'standard', WP_MCP_AI_Hook_Profiles::normalize_profile( 'Standard' ) );
		$this->assertSame( 'strict', WP_MCP_AI_Hook_Profiles::normalize_profile( ' STRICT ' ) );
		$this->assertSame( 'standard', WP_MCP_AI_Hook_Profiles::normalize_profile( 'bogus' ) );
		$this->assertSame( 'standard', WP_MCP_AI_Hook_Profiles::normalize_profile( 42 ) );
		$this->assertSame( 'standard', WP_MCP_AI_Hook_Profiles::normalize_profile( null ) );
	}

	/**
	 * Without any override the profile resolves to standard.
	 */
	public function test_get_profile_defaults_to_standard() {
		$this->assertSame( 'standard', WP_MCP_AI_Hook_Profiles::get_profile( 0 ) );
	}

	/**
	 * Assistant meta wins over every site-level source.
	 */
	public function test_get_profile_reads_assistant_meta() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, WP_MCP_AI_Hook_Profiles::META_KEY, 'strict' );
		update_option( WP_MCP_AI_Hook_Profiles::OPTION_DEFAULT_PROFILE, 'minimal' );

		$this->assertSame( 'strict', WP_MCP_AI_Hook_Profiles::get_profile( $post_id ) );
	}

	/**
	 * The filter applies when no assistant meta is set.
	 */
	public function test_get_profile_filter_override() {
		$post_id = self::factory()->post->create();

		add_filter(
			'wp_mcp_ai_hook_profile',
			function ( $profile, $assistant_id ) use ( $post_id ) {
				return (int) $assistant_id === (int) $post_id ? 'minimal' : $profile;
			},
			10,
			2
		);

		$this->assertSame( 'minimal', WP_MCP_AI_Hook_Profiles::get_profile( $post_id ) );
	}

	/**
	 * The site option supplies the default profile.
	 */
	public function test_get_profile_reads_site_option() {
		update_option( WP_MCP_AI_Hook_Profiles::OPTION_DEFAULT_PROFILE, 'strict' );

		$this->assertSame( 'strict', WP_MCP_AI_Hook_Profiles::get_profile( 0 ) );
	}

	/**
	 * Rank gating: a subscriber requiring standard is blocked under minimal
	 * and allowed under standard and strict.
	 */
	public function test_allows_rank_gating() {
		$post_id = self::factory()->post->create();

		// Default (standard) profile.
		$this->assertTrue( WP_MCP_AI_Hook_Profiles::allows( 'wp_mcp_ai_after_tool_execution', $post_id, 'standard' ) );
		$this->assertTrue( WP_MCP_AI_Hook_Profiles::allows( 'wp_mcp_ai_after_tool_execution', $post_id, 'minimal' ) );
		$this->assertFalse( WP_MCP_AI_Hook_Profiles::allows( 'wp_mcp_ai_after_tool_execution', $post_id, 'strict' ) );

		// Minimal profile blocks standard-and-up subscribers.
		update_post_meta( $post_id, WP_MCP_AI_Hook_Profiles::META_KEY, 'minimal' );
		$this->assertFalse( WP_MCP_AI_Hook_Profiles::allows( 'wp_mcp_ai_after_tool_execution', $post_id, 'standard' ) );
		$this->assertTrue( WP_MCP_AI_Hook_Profiles::allows( 'wp_mcp_ai_after_tool_execution', $post_id, 'minimal' ) );

		// Strict profile allows everything.
		update_post_meta( $post_id, WP_MCP_AI_Hook_Profiles::META_KEY, 'strict' );
		$this->assertTrue( WP_MCP_AI_Hook_Profiles::allows( 'wp_mcp_ai_after_tool_execution', $post_id, 'strict' ) );
	}

	/**
	 * An unknown required profile normalizes to standard instead of breaking
	 * the rank lookup.
	 */
	public function test_allows_normalizes_unknown_required_profile() {
		$post_id = self::factory()->post->create();

		$this->assertTrue( WP_MCP_AI_Hook_Profiles::allows( 'wp_mcp_ai_after_tool_execution', $post_id, 'not-a-profile' ) );
	}

	/**
	 * The master-switch filter disables gating entirely: allows() returns
	 * true even for a strict requirement and a killed hook. Note that
	 * is_disabled() remains a raw kill-list lookup — subscribers must go
	 * through allows() for the master switch to apply.
	 */
	public function test_master_switch_off_bypasses_all_gates() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, WP_MCP_AI_Hook_Profiles::META_KEY, 'minimal' );

		add_filter(
			'wp_mcp_ai_disabled_hooks',
			function ( $disabled ) {
				$disabled[] = 'wp_mcp_ai_after_tool_execution';
				return $disabled;
			}
		);
		add_filter( 'wp_mcp_ai_hook_profiles_enabled', '__return_false' );

		$this->assertTrue( WP_MCP_AI_Hook_Profiles::allows( 'wp_mcp_ai_after_tool_execution', $post_id, 'strict' ) );
		$this->assertFalse( WP_MCP_AI_Hook_Profiles::is_enabled() );
		// Raw lookup still reports the kill-list entry.
		$this->assertTrue( WP_MCP_AI_Hook_Profiles::is_disabled( 'wp_mcp_ai_after_tool_execution', $post_id ) );
	}

	/**
	 * The per-hook disable list blocks a hook under any profile.
	 */
	public function test_disabled_hook_filter_blocks_hook() {
		$post_id = self::factory()->post->create();

		add_filter(
			'wp_mcp_ai_disabled_hooks',
			function ( $disabled ) {
				$disabled[] = 'wp_mcp_ai_before_chat_request';
				return $disabled;
			}
		);

		$this->assertTrue( WP_MCP_AI_Hook_Profiles::is_disabled( 'wp_mcp_ai_before_chat_request', $post_id ) );
		$this->assertFalse( WP_MCP_AI_Hook_Profiles::is_disabled( 'wp_mcp_ai_after_chat_response', $post_id ) );
		$this->assertFalse( WP_MCP_AI_Hook_Profiles::allows( 'wp_mcp_ai_before_chat_request', $post_id, 'minimal' ) );
	}

	/**
	 * An empty hook name is never disabled.
	 */
	public function test_is_disabled_ignores_empty_hook() {
		$this->assertFalse( WP_MCP_AI_Hook_Profiles::is_disabled( '', 0 ) );
	}
}
