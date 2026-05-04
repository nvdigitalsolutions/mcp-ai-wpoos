<?php
/**
 * Tests for the LLM harness Prompt Cue Library and Harness Profile.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Prompt Cue Library + Harness Profile tests.
 *
 * @since 1.4.0
 */
class Test_Harness_Prompt_Cue_Library extends WP_UnitTestCase {

	/**
	 * Reset the cue library before each test so default seeding is deterministic.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Prompt_Cue_Library::get_instance()->reset();
	}

	public function test_default_cues_are_seeded() {
		$lib  = WP_MCP_AI_Prompt_Cue_Library::get_instance();
		$cues = $lib->all();
		$this->assertNotEmpty( $cues );
		$this->assertArrayHasKey( 'chain_of_thought', $cues );
		$this->assertArrayHasKey( 'failure_modes_first', $cues );
		$this->assertArrayHasKey( 'cite_or_abstain', $cues );
	}

	public function test_register_then_get_round_trip() {
		$lib = WP_MCP_AI_Prompt_Cue_Library::get_instance();
		$lib->register(
			array(
				'slug'         => 'unit_test_cue',
				'label'        => 'Unit Test',
				'description'  => 'Test cue',
				'template'     => 'Always say hello first.',
				'task_classes' => array( 'general', 'qa' ),
			)
		);
		$cue = $lib->get( 'unit_test_cue' );
		$this->assertIsArray( $cue );
		$this->assertSame( 'unit_test_cue', $cue['slug'] );
		$this->assertContains( 'qa', $cue['task_classes'] );
	}

	public function test_register_rejects_empty_template() {
		$lib    = WP_MCP_AI_Prompt_Cue_Library::get_instance();
		$result = $lib->register(
			array(
				'slug'     => 'no_template',
				'label'    => 'X',
				'template' => '   ',
			)
		);
		$this->assertFalse( $result );
		$this->assertNull( $lib->get( 'no_template' ) );
	}

	public function test_apply_prepends_cue_and_preserves_original() {
		$lib            = WP_MCP_AI_Prompt_Cue_Library::get_instance();
		$original       = 'You are a helpful assistant. Always be polite.';
		$augmented      = $lib->apply( $original, 'chain_of_thought' );
		$this->assertStringContainsString( 'Chain of Thought', $augmented );
		$this->assertStringContainsString( $original, $augmented );
		$this->assertStringEndsWith( $original, $augmented );
	}

	public function test_apply_with_unknown_cue_returns_original() {
		$lib       = WP_MCP_AI_Prompt_Cue_Library::get_instance();
		$original  = 'You are a helpful assistant.';
		$augmented = $lib->apply( $original, 'no_such_cue' );
		$this->assertSame( $original, $augmented );
	}

	public function test_apply_handles_empty_system_prompt() {
		$lib       = WP_MCP_AI_Prompt_Cue_Library::get_instance();
		$augmented = $lib->apply( '', array( 'chain_of_thought' ) );
		$this->assertNotEmpty( $augmented );
		$this->assertStringContainsString( 'step by step', strtolower( $augmented ) );
	}

	public function test_select_returns_a_cue_for_known_task_class() {
		$lib = WP_MCP_AI_Prompt_Cue_Library::get_instance();
		$cue = $lib->select( 'math' );
		$this->assertIsArray( $cue );
		$this->assertContains( 'math', $cue['task_classes'] );
	}

	public function test_select_filter_can_force_cue() {
		$called = array();
		add_filter(
			'wp_mcp_ai_select_prompt_cue',
			function ( $default, $task_class, $assistant_id, $model ) use ( &$called ) {
				$called[] = compact( 'default', 'task_class', 'assistant_id', 'model' );
				return 'failure_modes_first';
			},
			10,
			4
		);
		$cue = WP_MCP_AI_Prompt_Cue_Library::get_instance()->select( 'qa', 0, 'gpt-4o' );
		$this->assertSame( 'failure_modes_first', $cue['slug'] );
		$this->assertNotEmpty( $called );
		remove_all_filters( 'wp_mcp_ai_select_prompt_cue' );
	}

	public function test_list_cues_filters_by_task_class() {
		$lib    = WP_MCP_AI_Prompt_Cue_Library::get_instance();
		$math   = $lib->list_cues( 'math' );
		$this->assertNotEmpty( $math );
		foreach ( $math as $cue ) {
			$this->assertContains( 'math', $cue['task_classes'], 'cue ' . $cue['slug'] . ' should declare "math" task class' );
		}
	}
}

/**
 * Tests for the harness profile sanitizer / clamper.
 *
 * @since 1.4.0
 */
class Test_Harness_Profile extends WP_UnitTestCase {

	public function test_defaults_are_off() {
		$d = WP_MCP_AI_Harness_Profile::defaults();
		$this->assertFalse( $d['enabled'] );
		$this->assertSame( array(), $d['cues'] );
		$this->assertFalse( $d['reasoning']['enabled'] );
		$this->assertFalse( $d['retrieval']['enabled'] );
		$this->assertFalse( $d['refine']['enabled'] );
		$this->assertSame( 'fixed', $d['tools']['router'] );
	}

	public function test_sanitize_clamps_n_samples_to_max() {
		$p = WP_MCP_AI_Harness_Profile::sanitize(
			array(
				'enabled'   => true,
				'reasoning' => array(
					'enabled'   => true,
					'n_samples' => 999,
				),
			)
		);
		$this->assertTrue( $p['reasoning']['enabled'] );
		$this->assertSame( WP_MCP_AI_Harness_Profile::MAX_REASONING_SAMPLES, $p['reasoning']['n_samples'] );
	}

	public function test_sanitize_rejects_unknown_router() {
		$p = WP_MCP_AI_Harness_Profile::sanitize( array( 'tools' => array( 'router' => 'magic' ) ) );
		$this->assertSame( 'fixed', $p['tools']['router'] );
	}

	public function test_sanitize_accepts_scored_router() {
		$p = WP_MCP_AI_Harness_Profile::sanitize( array( 'tools' => array( 'router' => 'scored' ) ) );
		$this->assertSame( 'scored', $p['tools']['router'] );
	}

	public function test_sanitize_clamps_cost_ceiling_negatives() {
		$p = WP_MCP_AI_Harness_Profile::sanitize( array( 'cost_ceiling_usd' => -5.0 ) );
		$this->assertSame( 0.0, $p['cost_ceiling_usd'] );
	}

	public function test_sanitize_caps_cost_ceiling_high() {
		$p = WP_MCP_AI_Harness_Profile::sanitize( array( 'cost_ceiling_usd' => 99999 ) );
		$this->assertSame( 1000.0, $p['cost_ceiling_usd'] );
	}

	public function test_sanitize_decodes_json_string() {
		$p = WP_MCP_AI_Harness_Profile::sanitize( '{"enabled":true,"cues":["chain_of_thought"]}' );
		$this->assertTrue( $p['enabled'] );
		$this->assertSame( array( 'chain_of_thought' ), $p['cues'] );
	}

	public function test_save_and_get_round_trip_for_assistant() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$assistant_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$ok = WP_MCP_AI_Harness_Profile::save(
			$assistant_id,
			array(
				'enabled' => true,
				'cues'    => array( 'chain_of_thought', 'cite_or_abstain' ),
			)
		);
		$this->assertTrue( $ok );

		$loaded = WP_MCP_AI_Harness_Profile::get( $assistant_id );
		$this->assertTrue( $loaded['enabled'] );
		$this->assertSame( array( 'chain_of_thought', 'cite_or_abstain' ), $loaded['cues'] );
	}

	public function test_save_rejects_when_user_lacks_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$assistant_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$ok = WP_MCP_AI_Harness_Profile::save( $assistant_id, array( 'enabled' => true ) );
		$this->assertFalse( $ok );
	}

	public function test_is_layer_enabled_respects_master_switch() {
		$p_off = WP_MCP_AI_Harness_Profile::sanitize(
			array(
				'enabled'   => false,
				'cues'      => array( 'chain_of_thought' ),
				'reasoning' => array( 'enabled' => true ),
			)
		);
		// When master switch is off, no layer is enabled.
		add_filter(
			'wp_mcp_ai_harness_profile',
			function () use ( $p_off ) {
				return $p_off;
			},
			999
		);
		$this->assertFalse( WP_MCP_AI_Harness_Profile::is_layer_enabled( 0, 'reasoning' ) );
		$this->assertFalse( WP_MCP_AI_Harness_Profile::is_layer_enabled( 0, 'prompt' ) );
		remove_all_filters( 'wp_mcp_ai_harness_profile' );
	}

	public function test_is_layer_enabled_when_master_switch_on() {
		$p_on = WP_MCP_AI_Harness_Profile::sanitize(
			array(
				'enabled'   => true,
				'cues'      => array( 'chain_of_thought' ),
				'reasoning' => array( 'enabled' => true ),
			)
		);
		add_filter(
			'wp_mcp_ai_harness_profile',
			function () use ( $p_on ) {
				return $p_on;
			},
			999
		);
		$this->assertTrue( WP_MCP_AI_Harness_Profile::is_layer_enabled( 0, 'prompt' ) );
		$this->assertTrue( WP_MCP_AI_Harness_Profile::is_layer_enabled( 0, 'reasoning' ) );
		$this->assertFalse( WP_MCP_AI_Harness_Profile::is_layer_enabled( 0, 'retrieval' ) );
		remove_all_filters( 'wp_mcp_ai_harness_profile' );
	}
}
