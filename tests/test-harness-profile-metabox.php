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

	/**
	 * Set up the assistant scratch post, an admin user, and cue defaults.
	 */
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

	/**
	 * Tear down: reset the cue library and clear superglobals.
	 */
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

	/**
	 * Saving without a nonce must be a no-op.
	 */
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

	/**
	 * Saving with a valid nonce persists the sanitized form payload.
	 */
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

	/**
	 * Unchecking the enabled box disables the profile.
	 */
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

	/**
	 * Saving preserves fields the metabox does not surface.
	 */
	public function test_save_preserves_non_ui_fields() {
		// Seed a profile that carries verifiers, a field the metabox does not
		// surface. evals_enabled IS surfaced by the eval-suite UI, so it
		// legitimately resets when its checkboxes are absent from the form.
		WP_MCP_AI_Harness_Profile::save(
			$this->assistant_id,
			array(
				'enabled'   => true,
				'cues'      => array( 'chain_of_thought' ),
				'verifiers' => array( 'citation_verifier' ),
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
		$this->assertSame( array( 'citation_verifier' ), $profile['verifiers'] );
	}

	/**
	 * Saving persists the layer B through F settings from the form.
	 */
	public function test_save_persists_layer_b_through_f_settings() {
		$metabox = $this->build_metabox();
		$post    = get_post( $this->assistant_id );

		$_POST = array(
			WP_MCP_AI_Metabox_Harness_Profile::NONCE_FIELD => wp_create_nonce( WP_MCP_AI_Metabox_Harness_Profile::NONCE_ACTION ),
			'wp_mcp_ai_harness_profile'                    => array(
				'enabled'   => '1',
				'cues'      => array( 'chain_of_thought' ),
				'reasoning' => array(
					'enabled'   => '1',
					'n_samples' => '5',
				),
				'tools'     => array(
					'router' => 'scored',
				),
				'retrieval' => array(
					'enabled'           => '1',
					'k'                 => '8',
					'require_citations' => '1',
				),
				'refine'    => array(
					'enabled'   => '1',
					'max_iters' => '3',
				),
				'memory'    => array(
					'scoped'     => '1',
					'task_class' => 'support',
					'pii_filter' => '1',
				),
			),
		);

		$metabox->save( $this->assistant_id, $post );

		$profile = WP_MCP_AI_Harness_Profile::get( $this->assistant_id );

		$this->assertTrue( $profile['reasoning']['enabled'] );
		$this->assertSame( 5, $profile['reasoning']['n_samples'] );

		$this->assertSame( 'scored', $profile['tools']['router'] );

		$this->assertTrue( $profile['retrieval']['enabled'] );
		$this->assertSame( 8, $profile['retrieval']['k'] );
		$this->assertTrue( $profile['retrieval']['require_citations'] );

		$this->assertTrue( $profile['refine']['enabled'] );
		$this->assertSame( 3, $profile['refine']['max_iters'] );

		$this->assertTrue( $profile['memory']['scoped'] );
		$this->assertSame( 'support', $profile['memory']['task_class'] );
		$this->assertTrue( $profile['memory']['pii_filter'] );
	}

	/**
	 * Saving clamps out-of-range layer values to their hard caps.
	 */
	public function test_save_clamps_layer_values_to_hard_caps() {
		$metabox = $this->build_metabox();
		$post    = get_post( $this->assistant_id );

		$_POST = array(
			WP_MCP_AI_Metabox_Harness_Profile::NONCE_FIELD => wp_create_nonce( WP_MCP_AI_Metabox_Harness_Profile::NONCE_ACTION ),
			'wp_mcp_ai_harness_profile'                    => array(
				'enabled'   => '1',
				'reasoning' => array(
					'enabled'   => '1',
					'n_samples' => '999', // Should clamp to MAX_REASONING_SAMPLES.
				),
				'retrieval' => array(
					'enabled' => '1',
					'k'       => '500', // Should clamp to 50.
				),
				'refine'    => array(
					'enabled'   => '1',
					'max_iters' => '999', // Should clamp to MAX_REFINE_ITERATIONS.
				),
			),
		);

		$metabox->save( $this->assistant_id, $post );

		$profile = WP_MCP_AI_Harness_Profile::get( $this->assistant_id );

		$this->assertSame( WP_MCP_AI_Harness_Profile::MAX_REASONING_SAMPLES, $profile['reasoning']['n_samples'] );
		$this->assertSame( 50, $profile['retrieval']['k'] );
		$this->assertSame( WP_MCP_AI_Harness_Profile::MAX_REFINE_ITERATIONS, $profile['refine']['max_iters'] );
	}

	/**
	 * Unchecking a layer checkbox disables that layer while preserving numerics.
	 */
	public function test_save_unchecking_layer_checkbox_disables_layer() {
		// Seed: every layer enabled.
		WP_MCP_AI_Harness_Profile::save(
			$this->assistant_id,
			array(
				'enabled'   => true,
				'reasoning' => array(
					'enabled'   => true,
					'n_samples' => 3,
				),
				'retrieval' => array(
					'enabled'           => true,
					'k'                 => 7,
					'require_citations' => true,
				),
				'refine'    => array(
					'enabled'   => true,
					'max_iters' => 2,
				),
				'memory'    => array(
					'scoped'     => true,
					'task_class' => 'support',
					'pii_filter' => true,
				),
			)
		);

		$metabox = $this->build_metabox();
		$post    = get_post( $this->assistant_id );

		// All layer-enabled checkboxes intentionally absent (unchecked).
		// Numeric inputs still posted because they always submit.
		$_POST = array(
			WP_MCP_AI_Metabox_Harness_Profile::NONCE_FIELD => wp_create_nonce( WP_MCP_AI_Metabox_Harness_Profile::NONCE_ACTION ),
			'wp_mcp_ai_harness_profile'                    => array(
				'enabled'   => '1',
				'reasoning' => array( 'n_samples' => '3' ),
				'retrieval' => array( 'k' => '7' ),
				'refine'    => array( 'max_iters' => '2' ),
				'memory'    => array( 'task_class' => 'support' ),
			),
		);

		$metabox->save( $this->assistant_id, $post );

		$profile = WP_MCP_AI_Harness_Profile::get( $this->assistant_id );

		$this->assertFalse( $profile['reasoning']['enabled'] );
		$this->assertFalse( $profile['retrieval']['enabled'] );
		$this->assertFalse( $profile['retrieval']['require_citations'] );
		$this->assertFalse( $profile['refine']['enabled'] );
		$this->assertFalse( $profile['memory']['scoped'] );
		$this->assertFalse( $profile['memory']['pii_filter'] );

		// Numeric values that were posted are preserved.
		$this->assertSame( 3, $profile['reasoning']['n_samples'] );
		$this->assertSame( 7, $profile['retrieval']['k'] );
		$this->assertSame( 2, $profile['refine']['max_iters'] );
		$this->assertSame( 'support', $profile['memory']['task_class'] );
	}

	/**
	 * An invalid router value falls back to the fixed router.
	 */
	public function test_save_invalid_router_value_falls_back_to_fixed() {
		$metabox = $this->build_metabox();
		$post    = get_post( $this->assistant_id );

		$_POST = array(
			WP_MCP_AI_Metabox_Harness_Profile::NONCE_FIELD => wp_create_nonce( WP_MCP_AI_Metabox_Harness_Profile::NONCE_ACTION ),
			'wp_mcp_ai_harness_profile'                    => array(
				'enabled' => '1',
				'tools'   => array( 'router' => 'malicious-value' ),
			),
		);

		$metabox->save( $this->assistant_id, $post );

		$profile = WP_MCP_AI_Harness_Profile::get( $this->assistant_id );
		$this->assertSame( 'fixed', $profile['tools']['router'] );
	}

	/**
	 * Saving is rejected when the current user lacks edit capability.
	 */
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
