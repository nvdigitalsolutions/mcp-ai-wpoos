<?php
/**
 * Tests for the Artifact Shadow class (Phase F.3).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test session-hash shadow serving and resolver integration.
 */
class Test_Artifact_Shadow extends WP_UnitTestCase {

	/**
	 * Assistant post ID used across tests.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Set up an assistant post and the current user.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Artifact_Shadow' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Shadow class not available.' );
		}

		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( 1 );
	}

	/**
	 * Remove the shadow filters and reset the current user.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_artifact_shadow_enabled' );
		remove_all_filters( 'wp_mcp_ai_artifact_shadow_percentage' );
		remove_all_filters( 'wp_mcp_ai_artifact_shadow_session_key' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Candidate registration roundtrip + unregister.
	 */
	public function test_register_and_get_candidate() {
		$this->assertTrue(
			WP_MCP_AI_Artifact_Shadow::register_candidate( $this->assistant_id, 'prompt', 'Variant A', 'abc123' )
		);

		$candidate = WP_MCP_AI_Artifact_Shadow::get_candidate( $this->assistant_id, 'prompt' );
		$this->assertSame( 'Variant A', $candidate['payload'] );
		$this->assertSame( 'abc123', $candidate['hash'] );

		$this->assertTrue( WP_MCP_AI_Artifact_Shadow::unregister( $this->assistant_id, 'prompt' ) );
		$this->assertNull( WP_MCP_AI_Artifact_Shadow::get_candidate( $this->assistant_id, 'prompt' ) );
	}

	/**
	 * Shadow serving is off by default even with a registered candidate.
	 */
	public function test_disabled_by_default() {
		WP_MCP_AI_Artifact_Shadow::register_candidate( $this->assistant_id, 'prompt', 'Variant A', 'abc123' );

		$this->assertFalse( WP_MCP_AI_Artifact_Shadow::is_enabled( $this->assistant_id, 'prompt' ) );
	}

	/**
	 * The enable filter + a registered candidate turn shadow on.
	 */
	public function test_enabled_when_filter_and_candidate_present() {
		WP_MCP_AI_Artifact_Shadow::register_candidate( $this->assistant_id, 'prompt', 'Variant A', 'abc123' );
		add_filter( 'wp_mcp_ai_artifact_shadow_enabled', '__return_true' );

		$this->assertTrue( WP_MCP_AI_Artifact_Shadow::is_enabled( $this->assistant_id, 'prompt' ) );
	}

	/**
	 * Percentage bounds: 0 never serves, 100 always serves, clamps to 0–100.
	 */
	public function test_percentage_bounds() {
		add_filter( 'wp_mcp_ai_artifact_shadow_percentage', '__return_zero' );
		$this->assertFalse( WP_MCP_AI_Artifact_Shadow::should_serve_candidate( $this->assistant_id, 'prompt', 'h', array() ) );

		add_filter(
			'wp_mcp_ai_artifact_shadow_percentage',
			static function () {
				return 100;
			}
		);
		$this->assertTrue( WP_MCP_AI_Artifact_Shadow::should_serve_candidate( $this->assistant_id, 'prompt', 'h', array() ) );

		add_filter(
			'wp_mcp_ai_artifact_shadow_percentage',
			static function () {
				return 250;
			}
		);
		$this->assertSame( 100.0, WP_MCP_AI_Artifact_Shadow::percentage( $this->assistant_id, 'prompt' ) );
	}

	/**
	 * Bucketing is deterministic: the same session key always lands in the
	 * same arm, and matches the documented formula.
	 */
	public function test_bucketing_is_deterministic() {
		add_filter(
			'wp_mcp_ai_artifact_shadow_session_key',
			static function () {
				return 'fixed-session-key';
			}
		);
		add_filter(
			'wp_mcp_ai_artifact_shadow_percentage',
			static function () {
				return 50;
			}
		);

		$first  = WP_MCP_AI_Artifact_Shadow::should_serve_candidate( $this->assistant_id, 'prompt', 'abc123', array() );
		$second = WP_MCP_AI_Artifact_Shadow::should_serve_candidate( $this->assistant_id, 'prompt', 'abc123', array() );

		$this->assertSame( $first, $second );

		// Independent re-implementation of the documented formula.
		$bucket = hexdec( substr( md5( $this->assistant_id . '|prompt|abc123|fixed-session-key' ), 0, 8 ) ) % 10000;
		$this->assertSame( (float) $bucket < 5000.0, $first );
	}

	/**
	 * Serve decisions are recorded in bounded stats.
	 */
	public function test_stats_record_and_cap() {
		WP_MCP_AI_Artifact_Shadow::record_serve( $this->assistant_id, 'prompt', 'abc123', true );
		WP_MCP_AI_Artifact_Shadow::record_serve( $this->assistant_id, 'prompt', 'abc123', false );
		WP_MCP_AI_Artifact_Shadow::record_serve( $this->assistant_id, 'prompt', 'abc123', false );

		$stats = WP_MCP_AI_Artifact_Shadow::get_stats( $this->assistant_id, 'prompt' );

		$this->assertSame( 1, $stats['served_candidate'] );
		$this->assertSame( 2, $stats['served_incumbent'] );
		$this->assertCount( 3, $stats['events'] );
		$this->assertFalse( $stats['events'][0]['served'] );
	}

	/**
	 * The resolver serves the shadow candidate to bucketed sessions only
	 * when shadow mode is enabled — and never writes the deployed artifact.
	 */
	public function test_resolver_serves_shadow_candidate() {
		if ( ! class_exists( 'WP_MCP_AI_Evolved_Prompt_Resolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Evolved_Prompt_Resolver class not available.' );
		}

		WP_MCP_AI_Artifact_Shadow::register_candidate( $this->assistant_id, 'prompt', 'SHADOW-CANDIDATE', 'abc123' );
		add_filter( 'wp_mcp_ai_artifact_shadow_enabled', '__return_true' );
		add_filter(
			'wp_mcp_ai_artifact_shadow_percentage',
			static function () {
				return 100;
			}
		);

		$resolved = WP_MCP_AI_Evolved_Prompt_Resolver::filter( 'BASE-PROMPT', $this->assistant_id, array() );

		$this->assertSame( 'SHADOW-CANDIDATE', $resolved );
		$this->assertSame( '', (string) get_post_meta( $this->assistant_id, '_wp_mcp_ai_evolved_system_prompt', true ) );

		$stats = WP_MCP_AI_Artifact_Shadow::get_stats( $this->assistant_id, 'prompt' );
		$this->assertSame( 1, $stats['served_candidate'] );
	}

	/**
	 * When shadow is disabled the resolver leaves the prompt untouched.
	 */
	public function test_resolver_noop_when_shadow_disabled() {
		if ( ! class_exists( 'WP_MCP_AI_Evolved_Prompt_Resolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Evolved_Prompt_Resolver class not available.' );
		}

		WP_MCP_AI_Artifact_Shadow::register_candidate( $this->assistant_id, 'prompt', 'SHADOW-CANDIDATE', 'abc123' );

		$resolved = WP_MCP_AI_Evolved_Prompt_Resolver::filter( 'BASE-PROMPT', $this->assistant_id, array() );

		$this->assertSame( 'BASE-PROMPT', $resolved );
		$this->assertSame( array(), WP_MCP_AI_Artifact_Shadow::get_stats( $this->assistant_id, 'prompt' ) );
	}

	/**
	 * With 0% shadow, the incumbent arm serves and the decision is recorded.
	 */
	public function test_resolver_records_incumbent_arm() {
		if ( ! class_exists( 'WP_MCP_AI_Evolved_Prompt_Resolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Evolved_Prompt_Resolver class not available.' );
		}

		WP_MCP_AI_Artifact_Shadow::register_candidate( $this->assistant_id, 'prompt', 'SHADOW-CANDIDATE', 'abc123' );
		add_filter( 'wp_mcp_ai_artifact_shadow_enabled', '__return_true' );
		add_filter( 'wp_mcp_ai_artifact_shadow_percentage', '__return_zero' );

		$resolved = WP_MCP_AI_Evolved_Prompt_Resolver::filter( 'BASE-PROMPT', $this->assistant_id, array() );

		$this->assertSame( 'BASE-PROMPT', $resolved );
		$stats = WP_MCP_AI_Artifact_Shadow::get_stats( $this->assistant_id, 'prompt' );
		$this->assertSame( 1, $stats['served_incumbent'] );
	}
}
