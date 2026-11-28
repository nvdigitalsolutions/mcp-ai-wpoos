<?php
/**
 * Test: WCAG 2.2 contrast auditor.
 *
 * @package NV_oOS_Design_System
 */

/**
 * Tests for NV_oOS_Design_System_Contrast_Auditor.
 *
 * @covers NV_oOS_Design_System_Contrast_Auditor
 */
class Test_Contrast_Auditor extends WP_UnitTestCase {

	/**
	 * Hex colours parse to RGB triplets.
	 *
	 * @return void
	 */
	public function test_parse_hex_colour() {
		$this->assertSame( array( 255, 255, 255 ), NV_oOS_Design_System_Contrast_Auditor::parse_color( '#ffffff' ) );
		$this->assertSame( array( 0, 0, 0 ), NV_oOS_Design_System_Contrast_Auditor::parse_color( '#000' ) );
	}

	/**
	 * Parses rgb(), rgba() and named colours too.
	 *
	 * @return void
	 */
	public function test_parse_other_colour_notations() {
		$this->assertSame( array( 255, 0, 0 ), NV_oOS_Design_System_Contrast_Auditor::parse_color( 'rgb(255, 0, 0)' ) );
		$this->assertSame( array( 0, 0, 128 ), NV_oOS_Design_System_Contrast_Auditor::parse_color( 'navy' ) );
	}

	/**
	 * Custom properties are unresolved rather than guessed.
	 *
	 * @return void
	 */
	public function test_parse_unresolved_value() {
		$this->assertNull( NV_oOS_Design_System_Contrast_Auditor::parse_color( 'var(--nds-color-accent)' ) );
	}

	/**
	 * Black on white is the maximum 21:1.
	 *
	 * @return void
	 */
	public function test_black_on_white_ratio() {
		$this->assertEqualsWithDelta( 21.0, NV_oOS_Design_System_Contrast_Auditor::ratio( '#000000', '#ffffff' ), 0.01 );
	}

	/**
	 * Identical colours are 1:1.
	 *
	 * @return void
	 */
	public function test_same_colour_ratio() {
		$this->assertEqualsWithDelta( 1.0, NV_oOS_Design_System_Contrast_Auditor::ratio( '#336699', '#336699' ), 0.01 );
	}

	/**
	 * An unresolvable pair returns null.
	 *
	 * @return void
	 */
	public function test_unresolved_ratio_is_null() {
		$this->assertNull( NV_oOS_Design_System_Contrast_Auditor::ratio( 'var(--a)', '#ffffff' ) );
	}

	/**
	 * WCAG level thresholds for normal text.
	 *
	 * @return void
	 */
	public function test_level_normal_text() {
		$this->assertSame( 'AAA', NV_oOS_Design_System_Contrast_Auditor::level( 7.5 ) );
		$this->assertSame( 'AA', NV_oOS_Design_System_Contrast_Auditor::level( 4.6 ) );
		$this->assertSame( 'fail', NV_oOS_Design_System_Contrast_Auditor::level( 4.4 ) );
		$this->assertSame( 'unresolved', NV_oOS_Design_System_Contrast_Auditor::level( null ) );
	}

	/**
	 * WCAG level thresholds for large text (3:1).
	 *
	 * @return void
	 */
	public function test_level_large_text() {
		$this->assertSame( 'AAA', NV_oOS_Design_System_Contrast_Auditor::level( 4.6, true ) );
		$this->assertSame( 'AA', NV_oOS_Design_System_Contrast_Auditor::level( 3.2, true ) );
		$this->assertSame( 'fail', NV_oOS_Design_System_Contrast_Auditor::level( 2.9, true ) );
	}

	/**
	 * Pairs are evaluated against the correct threshold.
	 *
	 * @return void
	 */
	public function test_audit_pairs_thresholds() {
		$results = NV_oOS_Design_System_Contrast_Auditor::audit_pairs(
			array(
				array(
					'label' => 'Body text',
					'fg'    => '#000000',
					'bg'    => '#ffffff',
				),
				array(
					'label' => 'Too light',
					'fg'    => '#cccccc',
					'bg'    => '#ffffff',
				),
				array(
					'label'    => 'Border',
					'fg'       => '#949494',
					'bg'       => '#ffffff',
					'non_text' => true,
				),
			)
		);

		$this->assertCount( 3, $results );

		$this->assertTrue( $results[0]['pass'] );
		$this->assertEqualsWithDelta( 4.5, $results[0]['required'], 0.01 );

		$this->assertFalse( $results[1]['pass'] );
		$this->assertSame( 'fail', $results[1]['level'] );

		$this->assertEqualsWithDelta( 3.0, $results[2]['required'], 0.01 );
		$this->assertTrue( $results[2]['pass'] );
	}

	/**
	 * The standard pair set covers text, buttons, borders and focus rings.
	 *
	 * @return void
	 */
	public function test_derive_pairs_returns_standard_set() {
		$tokens = NV_oOS_Design_System_Plugin::token_registry()->get_all();
		$pairs  = NV_oOS_Design_System_Contrast_Auditor::derive_pairs( $tokens );

		$this->assertGreaterThanOrEqual( 6, count( $pairs ) );

		$labels = wp_list_pluck( $pairs, 'label' );
		$joined = strtolower( implode( ' | ', $labels ) );

		$this->assertStringContainsString( 'primary text', $joined );
		$this->assertStringContainsString( 'button', $joined );
		$this->assertStringContainsString( 'border', $joined );
		$this->assertStringContainsString( 'focus ring', $joined );
	}

	/**
	 * Every pair carries fg/bg values.
	 *
	 * @return void
	 */
	public function test_derive_pairs_fields() {
		$tokens = NV_oOS_Design_System_Plugin::token_registry()->get_all();

		foreach ( NV_oOS_Design_System_Contrast_Auditor::derive_pairs( $tokens ) as $pair ) {
			$this->assertArrayHasKey( 'label', $pair );
			$this->assertArrayHasKey( 'fg', $pair );
			$this->assertArrayHasKey( 'bg', $pair );
			$this->assertNotSame( '', $pair['fg'] );
			$this->assertNotSame( '', $pair['bg'] );
		}
	}

	/**
	 * Suggest returns a colour that meets the requested ratio.
	 *
	 * @return void
	 */
	public function test_suggest_meets_target() {
		$suggested = NV_oOS_Design_System_Contrast_Auditor::suggest( '#bbbbbb', '#ffffff', 4.5 );

		$this->assertNotNull( $suggested );
		$this->assertGreaterThanOrEqual( 4.5, NV_oOS_Design_System_Contrast_Auditor::ratio( $suggested, '#ffffff' ) );
	}

	/**
	 * HSL and RGB helpers.
	 *
	 * @return void
	 */
	public function test_colour_helpers() {
		$this->assertSame( array( 255, 0, 0 ), NV_oOS_Design_System_Contrast_Auditor::hsl_to_rgb( 0, 100, 50 ) );
		$this->assertSame( '#ff0000', NV_oOS_Design_System_Contrast_Auditor::rgb_to_hex( array( 255, 0, 0 ) ) );
	}
}
