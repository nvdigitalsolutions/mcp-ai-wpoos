<?php
/**
 * Tests for the Evolution Settings Bridge (Artifact Evolution UI wiring).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test that saved Orchestration settings drive the evolution filters and
 * that unsaved settings leave every code-level default untouched.
 */
class Test_Evolution_Settings_Bridge extends WP_UnitTestCase {

	/**
	 * Register the bridge and reset saved settings.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Evolution_Settings_Bridge' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Evolution_Settings_Bridge class not available.' );
		}

		WP_MCP_AI_Evolution_Settings_Bridge::register();
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Remove bridge filters and reset saved settings.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_harness_evolution_enabled' );
		remove_all_filters( 'wp_mcp_ai_harness_use_evolved_prompt' );
		remove_all_filters( 'wp_mcp_ai_skill_registry_include_evolved' );
		remove_all_filters( 'wp_mcp_ai_harness_evolution_budget_usd' );
		remove_all_filters( 'wp_mcp_ai_evolution_governor_rate_limit' );
		remove_all_filters( 'wp_mcp_ai_harness_evolution_warmup' );
		remove_all_filters( 'wp_mcp_ai_harness_verification_enabled' );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Save a settings option array.
	 *
	 * @param array $settings Settings to save.
	 */
	private function save_settings( $settings ) {
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * With nothing saved, every enable switch keeps its default: off.
	 */
	public function test_defaults_off_when_nothing_saved() {
		$this->assertFalse( apply_filters( 'wp_mcp_ai_harness_evolution_enabled', false ) );
		$this->assertFalse( apply_filters( 'wp_mcp_ai_harness_use_evolved_prompt', false, 1, array() ) );
		$this->assertFalse( apply_filters( 'wp_mcp_ai_skill_registry_include_evolved', false ) );
		$this->assertFalse( apply_filters( 'wp_mcp_ai_harness_verification_enabled', false, 1, 'prompt' ) );
	}

	/**
	 * With nothing saved, numeric defaults pass through untouched.
	 */
	public function test_numeric_defaults_pass_through_when_nothing_saved() {
		$this->assertSame( 5.0, apply_filters( 'wp_mcp_ai_harness_evolution_budget_usd', 5.0, 1 ) );
		$this->assertSame( 60, apply_filters( 'wp_mcp_ai_evolution_governor_rate_limit', 60, 1, 'evolver' ) );
		$this->assertSame( 5, apply_filters( 'wp_mcp_ai_harness_evolution_warmup', 5 ) );
	}

	/**
	 * Saved opt-in values drive the enable switches.
	 */
	public function test_saved_opt_ins_enable_the_switches() {
		$this->save_settings(
			array(
				'enable_harness_evolution'     => true,
				'use_evolved_system_prompt'    => true,
				'include_evolved_skills'       => true,
				'harness_verification_enabled' => true,
			)
		);

		$this->assertTrue( apply_filters( 'wp_mcp_ai_harness_evolution_enabled', false ) );
		$this->assertTrue( apply_filters( 'wp_mcp_ai_harness_use_evolved_prompt', false, 1, array() ) );
		$this->assertTrue( apply_filters( 'wp_mcp_ai_skill_registry_include_evolved', false ) );
		$this->assertTrue( apply_filters( 'wp_mcp_ai_harness_verification_enabled', false, 1, 'prompt' ) );
	}

	/**
	 * Explicitly saving "off" keeps the switch off.
	 */
	public function test_saved_false_stays_off() {
		$this->save_settings( array( 'enable_harness_evolution' => false ) );

		$this->assertFalse( apply_filters( 'wp_mcp_ai_harness_evolution_enabled', false ) );
	}

	/**
	 * Saved numeric values override the filter defaults.
	 */
	public function test_saved_numeric_values_apply() {
		$this->save_settings(
			array(
				'harness_evolution_budget_usd'  => 25,
				'evolution_governor_rate_limit' => 120,
				'harness_evolution_warmup'      => 10,
			)
		);

		$this->assertSame( 25.0, apply_filters( 'wp_mcp_ai_harness_evolution_budget_usd', 5.0, 1 ) );
		$this->assertSame( 120, apply_filters( 'wp_mcp_ai_evolution_governor_rate_limit', 60, 1, 'search' ) );
		$this->assertSame( 10, apply_filters( 'wp_mcp_ai_harness_evolution_warmup', 5 ) );
	}

	/**
	 * A developer filter at priority 10 overrides the saved UI value.
	 */
	public function test_developer_filter_overrides_saved_setting() {
		$this->save_settings( array( 'enable_harness_evolution' => true ) );

		add_filter(
			'wp_mcp_ai_harness_evolution_enabled',
			static function () {
				return false;
			},
			10
		);

		$this->assertFalse( apply_filters( 'wp_mcp_ai_harness_evolution_enabled', false ) );
	}

	/**
	 * An empty budget setting blocks spend (0 is a valid saved value).
	 */
	public function test_saved_zero_budget_applies() {
		$this->save_settings( array( 'harness_evolution_budget_usd' => 0 ) );

		$this->assertSame( 0.0, apply_filters( 'wp_mcp_ai_harness_evolution_budget_usd', 5.0, 1 ) );
	}
}
