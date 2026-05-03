<?php
/**
 * Tests for the LLM Harness profile metabox.
 *
 * Verifies the form-save round-trip and the off-by-default invariant.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Tests for WP_MCP_AI_Metabox_Harness_Profile.
 */
class Test_Harness_Profile_Metabox extends WP_UnitTestCase {

	/**
	 * Assistant post id used as scratch.
	 *
	 * @var int
	 */
	private $assistant_id = 0;

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( 'mcp_ai_assistant' ) ) {
			register_post_type( 'mcp_ai_assistant', array( 'public' => false ) );
		}

		$this->assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		$user = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user->ID );

		WP_MCP_AI_Prompt_Cue_Library::get_instance()->reset();
		WP_MCP_AI_Prompt_Cue_Library::get_instance()->all(); // Triggers default seeding.
	}

	public function tearDown(): void {
		WP_MCP_AI_Prompt_Cue_Library::get_instance()->reset();
		// Clean up superglobals between tests.
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Build a metabox instance using a stub CPT (the metabox doesn't call into
	 * the CPT itself; it only stores the reference).
	 */
	private function build_metabox() {
		$cpt = new stdClass();
		return new WP_MCP_AI_Metabox_Harness_Profile( $cpt );
	}

	public function test_save_is_noop_without_nonce() {
		$metabox = $this->build_metabox();
		$post    = get_post( $this->assistant_id );

		// Pretend a form submission arrived but with no nonce.
		$_POST = array(
			'wp_mcp_ai_harness_profile' => array(
				'enabled' => '1',
				'cues'    => array( 'chain_of_thought' ),
			),
		);

		$metabox->save( $this->assistant_id, $post );

		// Profile should still be defaults (off, empty cues).
		$profile = WP_MCP_AI_Harness_Profile::get( $this->assistant_id );
		$this->assertFalse( $profile['enabled'] );
		$this->assertSame( array(), $profile['cues'] );
	}

	public function test_save_persists_form_payload_when_nonce_valid() {
		$metabox = $this->build_metabox();
		$post    = get_post( $this->assistant_id );

		$_POST = array(
			WP_MCP_AI_Metabox_Harness_Profile::NONCE_FIELD => wp_create_nonce( WP_MCP_AI_Metabox_Harness_Profile::NONCE_ACTION ),
			'wp_mcp_ai_harness_profile'                    => array(
				'enabled'          => '1',
				'cues'             => array( 'chain_of_thought', 'cite_or_abstain' ),
				'cost_ceiling_usd' => '0.50',
			),
		);

		$metabox->save( $this->assistant_id, $post );

		$profile = WP_MCP_AI_Harness_Profile::get( $this->assistant_id );
		$this->assertTrue( $profile['enabled'] );
		$this->assertSame( array( 'chain_of_thought', 'cite_or_abstain' ), $profile['cues'] );
		$this->assertEqualsWithDelta( 0.5, (float) $profile['cost_ceiling_usd'], 0.0001 );
	}

	public function test_unchecking_enabled_disables_profile() {
		// First: enable.
		WP_MCP_AI_Harness_Profile::save(
			$this->assistant_id,
			array(
				'enabled' => true,
				'cues'    => array( 'chain_of_thought' ),
			)
		);

		// Then: simulate an admin un-ticking the enabled box and saving.
		$metabox = $this->build_metabox();
		$post    = get_post( $this->assistant_id );

		$_POST = array(
			WP_MCP_AI_Metabox_Harness_Profile::NONCE_FIELD => wp_create_nonce( WP_MCP_AI_Metabox_Harness_Profile::NONCE_ACTION ),
			'wp_mcp_ai_harness_profile'                    => array(
				// 'enabled' is intentionally absent (unchecked).
				'cues' => array( 'chain_of_thought' ),
			),
		);

		$metabox->save( $this->assistant_id, $post );

		$profile = WP_MCP_AI_Harness_Profile::get( $this->assistant_id );
		$this->assertFalse( $profile['enabled'] );
	}

	public function test_save_preserves_non_ui_fields() {
		// Seed a profile that carries reasoning + retrieval config the
		// metabox does not surface.
		WP_MCP_AI_Harness_Profile::save(
			$this->assistant_id,
			array(
				'enabled'   => true,
				'cues'      => array( 'chain_of_thought' ),
				'reasoning' => array(
					'enabled'   => true,
					'n_samples' => 3,
					'max_iters' => 2,
				),
				'retrieval' => array(
					'enabled'           => true,
					'k'                 => 7,
					'require_citations' => true,
				),
			)
		);

		$metabox = $this->build_metabox();
		$post    = get_post( $this->assistant_id );

		$_POST = array(
			WP_MCP_AI_Metabox_Harness_Profile::NONCE_FIELD => wp_create_nonce( WP_MCP_AI_Metabox_Harness_Profile::NONCE_ACTION ),
			'wp_mcp_ai_harness_profile'                    => array(
				'enabled' => '1',
				'cues'    => array( 'cite_or_abstain' ),
			),
		);

		$metabox->save( $this->assistant_id, $post );

		$profile = WP_MCP_AI_Harness_Profile::get( $this->assistant_id );

		// UI fields updated.
		$this->assertSame( array( 'cite_or_abstain' ), $profile['cues'] );

		// Non-UI fields preserved.
		$this->assertTrue( $profile['reasoning']['enabled'] );
		$this->assertSame( 3, $profile['reasoning']['n_samples'] );
		$this->assertTrue( $profile['retrieval']['enabled'] );
		$this->assertSame( 7, $profile['retrieval']['k'] );
		$this->assertTrue( $profile['retrieval']['require_citations'] );
	}

	public function test_save_rejects_when_user_lacks_capability() {
		// Drop down to a subscriber.
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user->ID );

		$metabox = $this->build_metabox();
		$post    = get_post( $this->assistant_id );

		$_POST = array(
			WP_MCP_AI_Metabox_Harness_Profile::NONCE_FIELD => wp_create_nonce( WP_MCP_AI_Metabox_Harness_Profile::NONCE_ACTION ),
			'wp_mcp_ai_harness_profile'                    => array(
				'enabled' => '1',
				'cues'    => array( 'chain_of_thought' ),
			),
		);

		$metabox->save( $this->assistant_id, $post );

		$profile = WP_MCP_AI_Harness_Profile::get( $this->assistant_id );
		$this->assertFalse( $profile['enabled'] );
	}
}
