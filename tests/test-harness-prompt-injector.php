<?php
/**
 * Tests for the Layer A chat-client cue injector.
 *
 * Verifies that the `wp_mcp_ai_resolved_system_prompt` filter is a no-op
 * when the harness profile is disabled, and prepends the configured cues
 * when the profile is enabled.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Tests for WP_MCP_AI_Harness_Prompt_Injector.
 */
class Test_Harness_Prompt_Injector extends WP_UnitTestCase {

	/**
	 * Assistant CPT post id used as scratch space.
	 *
	 * @var int
	 */
	private $assistant_id = 0;

	public function setUp(): void {
		parent::setUp();

		// Register the post type if it isn't already so tests can use it as
		// a generic post container — the profile only requires post meta.
		if ( ! post_type_exists( 'mcp_ai_assistant' ) ) {
			register_post_type( 'mcp_ai_assistant', array( 'public' => false ) );
		}

		$this->assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		// Ensure the cue library is fresh and the injector is registered.
		WP_MCP_AI_Prompt_Cue_Library::get_instance()->reset();
		WP_MCP_AI_Prompt_Cue_Library::get_instance()->all(); // triggers seed.
	}

	public function tearDown(): void {
		WP_MCP_AI_Prompt_Cue_Library::get_instance()->reset();
		parent::tearDown();
	}

	public function test_filter_is_noop_when_profile_disabled() {
		$prompt = 'You are a helpful assistant.';
		$out    = WP_MCP_AI_Harness_Prompt_Injector::filter( $prompt, $this->assistant_id );
		$this->assertSame( $prompt, $out );
	}

	public function test_filter_is_noop_when_enabled_but_no_cues() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user->ID );

		WP_MCP_AI_Harness_Profile::save(
			$this->assistant_id,
			array(
				'enabled' => true,
				'cues'    => array(),
			)
		);

		$out = WP_MCP_AI_Harness_Prompt_Injector::filter( 'base prompt', $this->assistant_id );
		$this->assertSame( 'base prompt', $out );
	}

	public function test_filter_prepends_cues_when_profile_enabled() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user->ID );

		WP_MCP_AI_Harness_Profile::save(
			$this->assistant_id,
			array(
				'enabled' => true,
				'cues'    => array( 'chain_of_thought' ),
			)
		);

		$out = WP_MCP_AI_Harness_Prompt_Injector::filter( 'base prompt', $this->assistant_id );
		$this->assertNotSame( 'base prompt', $out );
		$this->assertStringContainsString( 'Chain of Thought', $out );
		$this->assertStringContainsString( 'base prompt', $out );
		// Cue must precede the original prompt (augment, not replace).
		$cue_pos    = strpos( $out, 'Chain of Thought' );
		$prompt_pos = strpos( $out, 'base prompt' );
		$this->assertLessThan( $prompt_pos, $cue_pos );
	}

	public function test_filter_skips_unknown_cue_slugs() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user->ID );

		WP_MCP_AI_Harness_Profile::save(
			$this->assistant_id,
			array(
				'enabled' => true,
				'cues'    => array( 'definitely_not_a_cue' ),
			)
		);

		$out = WP_MCP_AI_Harness_Prompt_Injector::filter( 'base prompt', $this->assistant_id );
		$this->assertSame( 'base prompt', $out );
	}

	public function test_inject_cue_slugs_filter_can_substitute() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user->ID );

		WP_MCP_AI_Harness_Profile::save(
			$this->assistant_id,
			array(
				'enabled' => true,
				'cues'    => array( 'chain_of_thought' ),
			)
		);

		$replaced = false;
		add_filter(
			'wp_mcp_ai_harness_inject_cue_slugs',
			function ( $slugs ) use ( &$replaced ) {
				$replaced = true;
				return array( 'state_uncertainty' );
			}
		);

		$out = WP_MCP_AI_Harness_Prompt_Injector::filter( 'base prompt', $this->assistant_id );
		$this->assertTrue( $replaced );
		$this->assertStringContainsString( 'State Uncertainty', $out );
		$this->assertStringNotContainsString( 'Chain of Thought', $out );

		remove_all_filters( 'wp_mcp_ai_harness_inject_cue_slugs' );
	}

	public function test_filter_is_registered_via_init() {
		// The init file calls register(); this verifies the filter handler
		// is reachable through the WordPress filter system.
		$this->assertTrue(
			has_filter( 'wp_mcp_ai_resolved_system_prompt', array( 'WP_MCP_AI_Harness_Prompt_Injector', 'filter' ) ) !== false
		);
	}
}
