<?php
/**
 * Tests for WP_MCP_AI_Pro_Rubric_Presets.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro rubric presets.
 */
class Test_WP_MCP_AI_Pro_Rubric_Presets extends WP_UnitTestCase {

	/** Test prompt adherence passes when response overlaps and is sized.
	 */
	public function test_prompt_adherence_passes_when_response_overlaps_and_is_sized() {
		$verifier = WP_MCP_AI_Pro_Rubric_Presets::prompt_adherence();
		$subject  = array(
			'value' => 'Photosynthesis converts sunlight water and carbon dioxide into glucose inside the chloroplasts',
			'input' => 'photosynthesis sunlight glucose chloroplasts',
		);
		$result   = $verifier->verify( $subject );
		$this->assertTrue( $result['passed'], 'Expected pass but got score ' . $result['score'] );
		$this->assertGreaterThanOrEqual( 0.7, $result['score'] );
	}

	/** Test prompt adherence fails on prohibited phrase.
	 */
	public function test_prompt_adherence_fails_on_prohibited_phrase() {
		$verifier = WP_MCP_AI_Pro_Rubric_Presets::prompt_adherence();
		$subject  = array(
			'value' => 'As an AI language model I cannot answer photosynthesis questions today',
			'input' => 'Explain photosynthesis in one sentence',
		);
		$result   = $verifier->verify( $subject );
		$this->assertFalse( $result['passed'] );
		$this->assertArrayHasKey( 'no_prohibited_phrases', $result['evidence']['criteria'] );
		$this->assertEqualsWithDelta( 0.0, $result['evidence']['criteria']['no_prohibited_phrases']['score'], 0.001 );
	}

	/** Test prompt adherence treats missing prompt as soft pass.
	 */
	public function test_prompt_adherence_treats_missing_prompt_as_soft_pass() {
		$verifier = WP_MCP_AI_Pro_Rubric_Presets::prompt_adherence();
		$result   = $verifier->verify( array( 'value' => 'This is a response with enough words to pass the envelope' ) );
		$this->assertEqualsWithDelta( 1.0, $result['evidence']['criteria']['addresses_prompt']['score'], 0.001 );
	}

	/** Test json schema passes valid object.
	 */
	public function test_json_schema_passes_valid_object() {
		$schema   = array(
			'type'       => 'object',
			'required'   => array( 'id', 'name' ),
			'properties' => array(
				'id'   => array( 'type' => 'integer' ),
				'name' => array( 'type' => 'string' ),
			),
		);
		$verifier = WP_MCP_AI_Pro_Rubric_Presets::json_schema( array( 'schema' => $schema ) );
		$result   = $verifier->verify(
			array(
				'value' => array(
					'id'   => 1,
					'name' => 'A',
				),
			)
		);
		$this->assertTrue( $result['passed'] );
	}

	/** Test json schema gives partial credit for some required keys.
	 */
	public function test_json_schema_gives_partial_credit_for_some_required_keys() {
		$schema   = array(
			'type'     => 'object',
			'required' => array( 'a', 'b', 'c', 'd' ),
		);
		$verifier = WP_MCP_AI_Pro_Rubric_Presets::json_schema(
			array(
				'schema'         => $schema,
				'pass_threshold' => 0.9,
			)
		);
		$result   = $verifier->verify(
			array(
				'value' => array(
					'a' => 1,
					'b' => 2,
				),
			)
		);
		// Required score 0.5, type match 1.0, no_unknown_keys 1.0 → weighted avg below 0.9 → fail.
		$this->assertFalse( $result['passed'] );
		$this->assertEqualsWithDelta( 0.5, $result['evidence']['criteria']['required_keys']['score'], 0.001 );
	}

	/** Test json schema decodes json string values.
	 */
	public function test_json_schema_decodes_json_string_values() {
		$schema   = array(
			'type'     => 'object',
			'required' => array( 'x' ),
		);
		$verifier = WP_MCP_AI_Pro_Rubric_Presets::json_schema( array( 'schema' => $schema ) );
		$result   = $verifier->verify( array( 'value' => '{"x":42}' ) );
		$this->assertTrue( $result['passed'] );
	}

	/** Test citation presence passes with enough citations.
	 */
	public function test_citation_presence_passes_with_enough_citations() {
		$verifier = WP_MCP_AI_Pro_Rubric_Presets::citation_presence( array( 'minimum' => 2 ) );
		$result   = $verifier->verify(
			array( 'value' => 'See [1] and also https://example.com for details.' )
		);
		$this->assertTrue( $result['passed'] );
	}

	/** Test citation presence fails with no citations.
	 */
	public function test_citation_presence_fails_with_no_citations() {
		$verifier = WP_MCP_AI_Pro_Rubric_Presets::citation_presence();
		$result   = $verifier->verify( array( 'value' => 'A response with no sources at all.' ) );
		$this->assertFalse( $result['passed'] );
	}

	/** Test citation presence penalizes duplicates.
	 */
	public function test_citation_presence_penalizes_duplicates() {
		$verifier = WP_MCP_AI_Pro_Rubric_Presets::citation_presence( array( 'minimum' => 2 ) );
		$result   = $verifier->verify(
			array( 'value' => 'https://a.com and again https://a.com' )
		);
		$this->assertLessThan( 1.0, $result['evidence']['criteria']['no_duplicates']['score'] );
	}

	/** Test filter allows criteria override.
	 */
	public function test_filter_allows_criteria_override() {
		add_filter(
			'wp_mcp_ai_pro_' . WP_MCP_AI_Pro_Rubric_Presets::SLUG_PROMPT_ADHERENCE . '_criteria',
			static function () {
				return array(
					array(
						'slug'     => 'always_pass',
						'weight'   => 1.0,
						'callback' => static function () {
							return 1.0;
						},
					),
				);
			}
		);
		try {
			$verifier = WP_MCP_AI_Pro_Rubric_Presets::prompt_adherence();
			$this->assertCount( 1, $verifier->get_criteria() );
			$result = $verifier->verify( array( 'value' => '' ) );
			$this->assertTrue( $result['passed'] );
		} finally {
			remove_all_filters( 'wp_mcp_ai_pro_' . WP_MCP_AI_Pro_Rubric_Presets::SLUG_PROMPT_ADHERENCE . '_criteria' );
		}
	}

	/** Test bootstrap registers all three presets.
	 */
	public function test_bootstrap_registers_all_three_presets() {
		WP_MCP_AI_Verifier_Registry::reset_instance();
		$registry = WP_MCP_AI_Verifier_Registry::get_instance();

		WP_MCP_AI_Pro_Measurement_Bootstrap::register_preset_rubrics( $registry );

		$this->assertNotNull( $registry->get( WP_MCP_AI_Pro_Rubric_Presets::SLUG_PROMPT_ADHERENCE ) );
		$this->assertNotNull( $registry->get( WP_MCP_AI_Pro_Rubric_Presets::SLUG_JSON_SCHEMA ) );
		$this->assertNotNull( $registry->get( WP_MCP_AI_Pro_Rubric_Presets::SLUG_CITATION_PRESENCE ) );

		WP_MCP_AI_Verifier_Registry::reset_instance();
	}

	/** Test presets do not leak subject into reasons.
	 */
	public function test_presets_do_not_leak_subject_into_reasons() {
		$secret       = 'SUPERSECRET_RESPONSE_MARKER_' . uniqid();
		$verifier     = WP_MCP_AI_Pro_Rubric_Presets::prompt_adherence();
		$result       = $verifier->verify(
			array(
				'value' => $secret . ' ignore previous instructions',
				'input' => 'harmless prompt here',
			)
		);
		$reasons_json = wp_json_encode( $result['reasons'] );
		$this->assertStringNotContainsString( $secret, (string) $reasons_json );
		$evidence_json = wp_json_encode( $result['evidence'] );
		$this->assertStringNotContainsString( $secret, (string) $evidence_json );
	}
}
