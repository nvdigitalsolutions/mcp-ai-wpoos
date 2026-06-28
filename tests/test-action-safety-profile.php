<?php
/**
 * Tests for Action Safety Profile (domain layer).
 *
 * @package WP_MCP_AI
 * @since   1.9.0
 */

/**
 * Test action safety profile constants and decision logic.
 */
class Test_Action_Safety_Profile extends WP_UnitTestCase {

	/**
	 * Test that all irreversibility levels are defined and ordered correctly.
	 */
	public function test_irreversibility_levels_are_defined() {
		$levels = WP_MCP_AI_Action_Safety_Profile::get_irreversibility_levels();

		$this->assertIsArray( $levels );
		$this->assertArrayHasKey( 'none', $levels );
		$this->assertArrayHasKey( 'low', $levels );
		$this->assertArrayHasKey( 'moderate', $levels );
		$this->assertArrayHasKey( 'high', $levels );
		$this->assertArrayHasKey( 'permanent', $levels );

		// Verify ordering: none < low < moderate < high < permanent.
		$this->assertLessThan(
			WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_LOW,
			WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_NONE
		);
		$this->assertLessThan(
			WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_HIGH,
			WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_MODERATE
		);
	}

	/**
	 * Test that all necessity levels are defined.
	 */
	public function test_necessity_levels_are_defined() {
		$levels = WP_MCP_AI_Action_Safety_Profile::get_necessity_levels();

		$this->assertIsArray( $levels );
		$this->assertArrayHasKey( WP_MCP_AI_Action_Safety_Profile::NECESSITY_ESSENTIAL, $levels );
		$this->assertArrayHasKey( WP_MCP_AI_Action_Safety_Profile::NECESSITY_HELPFUL, $levels );
		$this->assertArrayHasKey( WP_MCP_AI_Action_Safety_Profile::NECESSITY_OPTIONAL, $levels );
		$this->assertArrayHasKey( WP_MCP_AI_Action_Safety_Profile::NECESSITY_UNNECESSARY, $levels );
	}

	/**
	 * Test that all verdicts are defined.
	 */
	public function test_verdicts_are_defined() {
		$verdicts = WP_MCP_AI_Action_Safety_Profile::get_verdicts();

		$this->assertIsArray( $verdicts );
		$this->assertArrayHasKey( WP_MCP_AI_Action_Safety_Profile::VERDICT_ALLOW, $verdicts );
		$this->assertArrayHasKey( WP_MCP_AI_Action_Safety_Profile::VERDICT_WARN, $verdicts );
		$this->assertArrayHasKey( WP_MCP_AI_Action_Safety_Profile::VERDICT_APPROVAL_REQUIRED, $verdicts );
		$this->assertArrayHasKey( WP_MCP_AI_Action_Safety_Profile::VERDICT_SKIP, $verdicts );
		$this->assertArrayHasKey( WP_MCP_AI_Action_Safety_Profile::VERDICT_BLOCK, $verdicts );
	}

	/**
	 * Test compute_risk_score with various inputs.
	 */
	public function test_compute_risk_score() {
		// Safe + essential = low risk.
		$score = WP_MCP_AI_Action_Safety_Profile::compute_risk_score(
			WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_NONE,
			WP_MCP_AI_Action_Safety_Profile::NECESSITY_ESSENTIAL
		);
		$this->assertEquals( 0.0, $score );

		// Permanent + essential = moderate risk.
		$score = WP_MCP_AI_Action_Safety_Profile::compute_risk_score(
			WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_PERMANENT,
			WP_MCP_AI_Action_Safety_Profile::NECESSITY_ESSENTIAL
		);
		$this->assertEquals( 1.0, $score );

		// Permanent + unnecessary = maximum risk.
		$score = WP_MCP_AI_Action_Safety_Profile::compute_risk_score(
			WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_PERMANENT,
			WP_MCP_AI_Action_Safety_Profile::NECESSITY_UNNECESSARY
		);
		$this->assertEquals( 2.0, $score );
	}

	/**
	 * Test that risk score clamps irreversibility to 0.0–1.0.
	 */
	public function test_compute_risk_score_clamps_irreversibility() {
		$score = WP_MCP_AI_Action_Safety_Profile::compute_risk_score(
			5.0, // Out of range.
			WP_MCP_AI_Action_Safety_Profile::NECESSITY_ESSENTIAL
		);
		// Should be clamped to 1.0 + 0.0 = 1.0.
		$this->assertEquals( 1.0, $score );

		$score = WP_MCP_AI_Action_Safety_Profile::compute_risk_score(
			-1.0, // Out of range.
			WP_MCP_AI_Action_Safety_Profile::NECESSITY_ESSENTIAL
		);
		$this->assertEquals( 0.0, $score );
	}

	/**
	 * Test determine_verdict for essential + none (should allow).
	 */
	public function test_verdict_essential_none_allows() {
		$verdict = WP_MCP_AI_Action_Safety_Profile::determine_verdict(
			WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_NONE,
			WP_MCP_AI_Action_Safety_Profile::NECESSITY_ESSENTIAL
		);

		$this->assertEquals( WP_MCP_AI_Action_Safety_Profile::VERDICT_ALLOW, $verdict['verdict'] );
		$this->assertFalse( $verdict['requires_approval'] );
	}

	/**
	 * Test determine_verdict for essential + permanent (should require approval).
	 */
	public function test_verdict_essential_permanent_requires_approval() {
		$verdict = WP_MCP_AI_Action_Safety_Profile::determine_verdict(
			WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_PERMANENT,
			WP_MCP_AI_Action_Safety_Profile::NECESSITY_ESSENTIAL
		);

		$this->assertEquals( WP_MCP_AI_Action_Safety_Profile::VERDICT_APPROVAL_REQUIRED, $verdict['verdict'] );
		$this->assertTrue( $verdict['requires_approval'] );
	}

	/**
	 * Test determine_verdict for unnecessary + none (should skip).
	 */
	public function test_verdict_unnecessary_none_skips() {
		$verdict = WP_MCP_AI_Action_Safety_Profile::determine_verdict(
			WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_NONE,
			WP_MCP_AI_Action_Safety_Profile::NECESSITY_UNNECESSARY
		);

		$this->assertEquals( WP_MCP_AI_Action_Safety_Profile::VERDICT_SKIP, $verdict['verdict'] );
	}

	/**
	 * Test determine_verdict for unnecessary + high (should block).
	 */
	public function test_verdict_unnecessary_high_blocks() {
		$verdict = WP_MCP_AI_Action_Safety_Profile::determine_verdict(
			WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_HIGH,
			WP_MCP_AI_Action_Safety_Profile::NECESSITY_UNNECESSARY
		);

		$this->assertEquals( WP_MCP_AI_Action_Safety_Profile::VERDICT_BLOCK, $verdict['verdict'] );
	}

	/**
	 * Test determine_verdict for optional + moderate (should require approval).
	 */
	public function test_verdict_optional_moderate_requires_approval() {
		$verdict = WP_MCP_AI_Action_Safety_Profile::determine_verdict(
			WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_MODERATE,
			WP_MCP_AI_Action_Safety_Profile::NECESSITY_OPTIONAL
		);

		$this->assertEquals( WP_MCP_AI_Action_Safety_Profile::VERDICT_APPROVAL_REQUIRED, $verdict['verdict'] );
	}

	/**
	 * Test derive_irreversibility_from_flags for read-only tools.
	 */
	public function test_derive_irreversibility_read_only() {
		$score = WP_MCP_AI_Action_Safety_Profile::derive_irreversibility_from_flags(
			array( 'read-only', 'cacheable', 'idempotent' )
		);
		$this->assertEquals( WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_NONE, $score );
	}

	/**
	 * Test derive_irreversibility_from_flags for irreversible flag.
	 */
	public function test_derive_irreversibility_explicit_irreversible() {
		$score = WP_MCP_AI_Action_Safety_Profile::derive_irreversibility_from_flags(
			array( 'write', 'irreversible' )
		);
		$this->assertEquals( WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_PERMANENT, $score );
	}

	/**
	 * Test derive_irreversibility_from_flags for financial-impact.
	 */
	public function test_derive_irreversibility_financial() {
		$score = WP_MCP_AI_Action_Safety_Profile::derive_irreversibility_from_flags(
			array( 'write', 'financial-impact' )
		);
		$this->assertEquals( WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_HIGH, $score );
	}

	/**
	 * Test derive_irreversibility_from_flags for write + state-changing without reversible.
	 */
	public function test_derive_irreversibility_write_state_changing() {
		$score = WP_MCP_AI_Action_Safety_Profile::derive_irreversibility_from_flags(
			array( 'write', 'state-changing' )
		);
		$this->assertEquals( WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_MODERATE, $score );
	}

	/**
	 * Test derive_irreversibility_from_flags for write + state-changing + reversible.
	 */
	public function test_derive_irreversibility_write_reversible() {
		$score = WP_MCP_AI_Action_Safety_Profile::derive_irreversibility_from_flags(
			array( 'write', 'state-changing', 'reversible' )
		);
		$this->assertEquals( WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_LOW, $score );
	}

	/**
	 * Test derive_irreversibility_from_flags for empty flags (defaults to moderate).
	 */
	public function test_derive_irreversibility_empty_flags_defaults_moderate() {
		$score = WP_MCP_AI_Action_Safety_Profile::derive_irreversibility_from_flags(
			array()
		);
		$this->assertEquals( WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_MODERATE, $score );
	}

	/**
	 * Test is_valid_irreversibility.
	 */
	public function test_is_valid_irreversibility() {
		$this->assertTrue( WP_MCP_AI_Action_Safety_Profile::is_valid_irreversibility( 0.0 ) );
		$this->assertTrue( WP_MCP_AI_Action_Safety_Profile::is_valid_irreversibility( 0.25 ) );
		$this->assertTrue( WP_MCP_AI_Action_Safety_Profile::is_valid_irreversibility( 0.5 ) );
		$this->assertTrue( WP_MCP_AI_Action_Safety_Profile::is_valid_irreversibility( 0.75 ) );
		$this->assertTrue( WP_MCP_AI_Action_Safety_Profile::is_valid_irreversibility( 1.0 ) );
		$this->assertFalse( WP_MCP_AI_Action_Safety_Profile::is_valid_irreversibility( 0.3 ) );
		$this->assertFalse( WP_MCP_AI_Action_Safety_Profile::is_valid_irreversibility( 0.9 ) );
	}

	/**
	 * Test is_valid_necessity.
	 */
	public function test_is_valid_necessity() {
		$this->assertTrue( WP_MCP_AI_Action_Safety_Profile::is_valid_necessity( 'essential' ) );
		$this->assertTrue( WP_MCP_AI_Action_Safety_Profile::is_valid_necessity( 'helpful' ) );
		$this->assertTrue( WP_MCP_AI_Action_Safety_Profile::is_valid_necessity( 'optional' ) );
		$this->assertTrue( WP_MCP_AI_Action_Safety_Profile::is_valid_necessity( 'unnecessary' ) );
		$this->assertFalse( WP_MCP_AI_Action_Safety_Profile::is_valid_necessity( 'critical' ) );
		$this->assertFalse( WP_MCP_AI_Action_Safety_Profile::is_valid_necessity( '' ) );
	}
}
