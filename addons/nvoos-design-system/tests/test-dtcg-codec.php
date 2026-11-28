<?php
/**
 * Test: DTCG 2025.10 codec — value conversion and document resolution.
 *
 * @package NV_oOS_Design_System
 */

/**
 * Tests for NV_oOS_Design_System_DTCG_Codec.
 *
 * @covers NV_oOS_Design_System_DTCG_Codec
 */
class Test_DTCG_Codec extends WP_UnitTestCase {

	/**
	 * Hex colours become srgb DTCG colour objects.
	 *
	 * @return void
	 */
	public function test_hex_colour_to_dtcg() {
		$value = NV_oOS_Design_System_DTCG_Codec::css_color_to_dtcg( '#ff0000' );

		$this->assertIsArray( $value );
		$this->assertSame( 'srgb', $value['colorSpace'] );
		$this->assertEquals( array( 1, 0, 0 ), $value['components'] );
		$this->assertSame( '#ff0000', $value['hex'] );
		$this->assertArrayNotHasKey( 'alpha', $value );
	}

	/**
	 * Shorthand hex is expanded.
	 *
	 * @return void
	 */
	public function test_shorthand_hex_to_dtcg() {
		$value = NV_oOS_Design_System_DTCG_Codec::css_color_to_dtcg( '#0f0' );

		$this->assertSame( '#00ff00', $value['hex'] );
	}

	/**
	 * Alpha survives the conversion.
	 *
	 * @return void
	 */
	public function test_rgba_preserves_alpha() {
		$value = NV_oOS_Design_System_DTCG_Codec::css_color_to_dtcg( 'rgba(0, 0, 0, 0.08)' );

		$this->assertIsArray( $value );
		$this->assertEqualsWithDelta( 0.08, $value['alpha'], 0.001 );
		$this->assertSame( '#000000', $value['hex'] );
	}

	/**
	 * Named colours are normalised.
	 *
	 * @return void
	 */
	public function test_named_colour_to_dtcg() {
		$value = NV_oOS_Design_System_DTCG_Codec::css_color_to_dtcg( 'white' );

		$this->assertSame( '#ffffff', $value['hex'] );
	}

	/**
	 * Values that cannot be represented return null rather than guessing.
	 *
	 * @return void
	 */
	public function test_exotic_colour_returns_null() {
		$this->assertNull( NV_oOS_Design_System_DTCG_Codec::css_color_to_dtcg( 'var(--brand)' ) );
		$this->assertNull( NV_oOS_Design_System_DTCG_Codec::css_color_to_dtcg( 'not-a-colour' ) );
	}

	/**
	 * A DTCG colour converts back to a CSS hex value.
	 *
	 * @return void
	 */
	public function test_dtcg_colour_to_css_round_trip() {
		$dtcg   = NV_oOS_Design_System_DTCG_Codec::css_color_to_dtcg( '#2563eb' );
		$result = NV_oOS_Design_System_DTCG_Codec::from_dtcg_value( 'color', $dtcg );

		$this->assertSame( '#2563eb', $result['value'] );
		$this->assertSame( array(), $result['errors'] );
	}

	/**
	 * Dimensions become value/unit objects.
	 *
	 * @return void
	 */
	public function test_dimension_to_dtcg() {
		$px  = NV_oOS_Design_System_DTCG_Codec::css_dimension_to_dtcg( '16px' );
		$rem = NV_oOS_Design_System_DTCG_Codec::css_dimension_to_dtcg( '1.5rem' );

		$this->assertSame(
			array(
				'value' => 16,
				'unit'  => 'px',
			),
			$px
		);
		$this->assertSame(
			array(
				'value' => 1.5,
				'unit'  => 'rem',
			),
			$rem
		);
	}

	/**
	 * Dimensions round-trip.
	 *
	 * @return void
	 */
	public function test_dimension_round_trip() {
		$result = NV_oOS_Design_System_DTCG_Codec::from_dtcg_value(
			'dimension',
			array(
				'value' => 24,
				'unit'  => 'px',
			)
		);

		$this->assertSame( '24px', $result['value'] );
		$this->assertSame( array(), $result['errors'] );
	}

	/**
	 * An invalid dimension reports an error instead of emitting CSS.
	 *
	 * @return void
	 */
	public function test_invalid_dimension_reports_error() {
		$result = NV_oOS_Design_System_DTCG_Codec::from_dtcg_value(
			'dimension',
			array(
				'value' => 'wide',
				'unit'  => 'px',
			)
		);

		$this->assertSame( '', $result['value'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Unitless zero is legal in CSS shadows and must not be dropped.
	 *
	 * @return void
	 */
	public function test_shadow_unitless_zero_is_accepted() {
		$shadow = NV_oOS_Design_System_DTCG_Codec::css_shadow_to_dtcg( '0 0 0 rgba(0, 0, 0, 0.5)' );

		$this->assertIsArray( $shadow );
		$this->assertEquals( 0, $shadow['offsetX']['value'] );
		$this->assertEquals( 0, $shadow['offsetY']['value'] );
		$this->assertEquals( 0, $shadow['blur']['value'] );
	}

	/**
	 * Shadows round-trip to a canonical CSS string.
	 *
	 * @return void
	 */
	public function test_shadow_round_trip() {
		$dtcg   = NV_oOS_Design_System_DTCG_Codec::css_shadow_to_dtcg( '0 1px 3px rgba(0, 0, 0, 0.08)' );
		$result = NV_oOS_Design_System_DTCG_Codec::from_dtcg_value( 'shadow', array( $dtcg ) );

		$this->assertSame( array(), $result['errors'] );
		$this->assertStringContainsString( '1px', $result['value'] );
		$this->assertStringContainsString( '3px', $result['value'] );
	}

	/**
	 * Font weights accept numbers and keywords.
	 *
	 * @return void
	 */
	public function test_font_weight_keywords() {
		$this->assertSame( 700, NV_oOS_Design_System_DTCG_Codec::css_font_weight_to_dtcg( 'bold' )['value'] );
		$this->assertSame( 600, NV_oOS_Design_System_DTCG_Codec::css_font_weight_to_dtcg( 'semi-bold' )['value'] );
		$this->assertSame( 400, NV_oOS_Design_System_DTCG_Codec::css_font_weight_to_dtcg( '400' )['value'] );
	}

	/**
	 * An out-of-range font weight reports an error.
	 *
	 * @return void
	 */
	public function test_font_weight_out_of_range() {
		$result = NV_oOS_Design_System_DTCG_Codec::from_dtcg_value( 'fontWeight', 1200 );

		$this->assertSame( '', $result['value'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * Durations become value/unit objects.
	 *
	 * @return void
	 */
	public function test_duration_to_dtcg() {
		$value = NV_oOS_Design_System_DTCG_Codec::css_duration_to_dtcg( '150ms' );

		$this->assertSame(
			array(
				'value' => 150,
				'unit'  => 'ms',
			),
			$value
		);
	}

	/**
	 * A multi-font stack becomes an ordered list.
	 *
	 * @return void
	 */
	public function test_font_family_stack() {
		$value = NV_oOS_Design_System_DTCG_Codec::css_font_family_to_dtcg( 'Georgia, "Times New Roman", serif' );

		$this->assertIsArray( $value );
		$this->assertCount( 3, $value );
		$this->assertSame( 'Georgia', $value[0] );
		$this->assertSame( '"Times New Roman"', $value[1] );
		$this->assertSame( 'serif', $value[2] );
	}

	/**
	 * Unrepresentable values fall back to a string token plus an extension.
	 *
	 * @return void
	 */
	public function test_to_dtcg_falls_back_to_string() {
		$token = new NV_oOS_Design_System_Data_Token( 'color_accent', 'Accent', 'colors', 'color', 'var(--brand)' );

		$entry = NV_oOS_Design_System_DTCG_Codec::to_dtcg( $token );

		$this->assertSame( 'string', $entry['$type'] );
		$this->assertSame( 'var(--brand)', $entry['$value'] );
		$this->assertArrayHasKey( 'expectedType', $entry['$extensions'][ NV_oOS_Design_System_DTCG_Codec::EXTENSION_KEY ] );
	}

	/**
	 * Aliases resolve to their target value and record `$aliasOf`.
	 *
	 * @return void
	 */
	public function test_alias_resolution() {
		$doc = array(
			'colors' => array(
				'base'   => array(
					'$type'  => 'color',
					'$value' => '#112233',
				),
				'accent' => array(
					'$type'  => 'color',
					'$value' => '{colors.base}',
				),
			),
		);

		$resolved = NV_oOS_Design_System_DTCG_Codec::resolve_document( $doc );

		$this->assertSame( array(), $resolved['errors'] );
		$this->assertSame( '#112233', $resolved['groups']['colors']['accent']['$value'] );
		$this->assertSame( 'colors.base', $resolved['groups']['colors']['accent']['$aliasOf'] );
	}

	/**
	 * A circular alias chain is reported, never looped.
	 *
	 * @return void
	 */
	public function test_circular_alias_is_reported() {
		$doc = array(
			'colors' => array(
				'a' => array(
					'$type'  => 'color',
					'$value' => '{colors.b}',
				),
				'b' => array(
					'$type'  => 'color',
					'$value' => '{colors.a}',
				),
			),
		);

		$resolved = NV_oOS_Design_System_DTCG_Codec::resolve_document( $doc );

		$this->assertNotEmpty( $resolved['errors'] );
		$this->assertStringContainsString( 'circular', strtolower( implode( ' ', $resolved['errors'] ) ) );
	}

	/**
	 * Group `$extends` deep-merges the target, local wins.
	 *
	 * @return void
	 */
	public function test_group_extends_merges() {
		$doc = array(
			'base'   => array(
				'space' => array(
					'sm' => array(
						'$type'  => 'dimension',
						'$value' => array(
							'value' => 8,
							'unit'  => 'px',
						),
					),
				),
			),
			'custom' => array(
				'$extends' => '{base}',
				'space'    => array(
					'sm' => array(
						'$type'  => 'dimension',
						'$value' => array(
							'value' => 10,
							'unit'  => 'px',
						),
					),
					'md' => array(
						'$type'  => 'dimension',
						'$value' => array(
							'value' => 16,
							'unit'  => 'px',
						),
					),
				),
			),
		);

		$resolved = NV_oOS_Design_System_DTCG_Codec::resolve_document( $doc );

		$this->assertSame( array(), $resolved['errors'] );
		$this->assertSame( 10, $resolved['groups']['custom']['space_sm']['$value']['value'] );
		$this->assertSame( 16, $resolved['groups']['custom']['space_md']['$value']['value'] );
	}

	/**
	 * Non-numeric colour channels report an error instead of a wrong colour.
	 *
	 * @return void
	 */
	public function test_array_component_reports_error() {
		$result = NV_oOS_Design_System_DTCG_Codec::from_dtcg_value(
			'color',
			array(
				'colorSpace' => 'srgb',
				'components' => array( array( 1 ), 0, 0 ),
			)
		);

		$this->assertSame( '', $result['value'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'numeric', implode( ' ', $result['errors'] ) );
	}

	/**
	 * A non-numeric alpha is an error, not a silent 1.0.
	 *
	 * @return void
	 */
	public function test_non_numeric_alpha_reports_error() {
		$result = NV_oOS_Design_System_DTCG_Codec::from_dtcg_value(
			'color',
			array(
				'colorSpace' => 'srgb',
				'components' => array( 1, 0, 0 ),
				'alpha'      => array( 0.5 ),
			)
		);

		$this->assertSame( '', $result['value'] );
		$this->assertStringContainsString( 'alpha', implode( ' ', $result['errors'] ) );
	}

	/**
	 * A DTCG document resolves into a group => token map.
	 *
	 * @return void
	 */
	public function test_resolve_document_returns_group_map() {
		$resolved = NV_oOS_Design_System_DTCG_Codec::resolve_document(
			array(
				'colors' => array(
					'accent' => array(
						'$type'  => 'color',
						'$value' => '#123456',
					),
				),
			)
		);

		$this->assertSame( array(), $resolved['errors'] );
		$this->assertArrayHasKey( 'accent', $resolved['groups']['colors'] );
	}

	/**
	 * A missing `$extends` target is reported.
	 *
	 * @return void
	 */
	public function test_group_extends_missing_target_is_reported() {
		$doc = array(
			'custom' => array(
				'$extends' => '{does.not.exist}',
				'x'        => array(
					'$type'  => 'string',
					'$value' => 'y',
				),
			),
		);

		$resolved = NV_oOS_Design_System_DTCG_Codec::resolve_document( $doc );

		$this->assertNotEmpty( $resolved['errors'] );
		$this->assertStringContainsString( 'not found', implode( ' ', $resolved['errors'] ) );
	}
}
