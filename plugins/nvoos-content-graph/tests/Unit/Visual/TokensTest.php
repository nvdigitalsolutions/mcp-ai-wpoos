<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Unit\Visual;

use NvoosContentGraph\Schema;
use NvoosContentGraph\Visual\Tokens;
use WP_UnitTestCase;

/**
 * Unit tests for the visual token registry and WCAG contrast math.
 *
 * The contrast gate asserts that every curated type color, after the
 * deterministic per-theme lightness correction (ensure_contrast), meets
 * WCAG 2.2 SC 1.4.11 (>= 3:1) against BOTH canvases.
 *
 * @since 1.0.4
 */
class TokensTest extends WP_UnitTestCase {

	/**
	 * Tear down test fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		remove_all_filters( Schema::FILTER_TYPE_PALETTE );
		remove_all_filters( Schema::FILTER_TYPE_ICONS );
		remove_all_filters( Schema::FILTER_VISUAL_CONFIG );
		parent::tearDown();
	}

	/**
	 * Relative luminance: white is 1.0, black is 0.0.
	 *
	 * @return void
	 */
	public function test_relative_luminance_extremes(): void {
		$this->assertEqualsWithDelta( 1.0, Tokens::relative_luminance( '#ffffff' ), 0.0001 );
		$this->assertEqualsWithDelta( 0.0, Tokens::relative_luminance( '#000000' ), 0.0001 );
		$this->assertEqualsWithDelta( 0.0, Tokens::relative_luminance( 'not-a-color' ), 0.0001 );
	}

	/**
	 * WCAG contrast ratio: white on black is 21:1.
	 *
	 * @return void
	 */
	public function test_contrast_ratio_white_black(): void {
		$this->assertEqualsWithDelta( 21.0, Tokens::contrast_ratio( '#ffffff', '#000000' ), 0.01 );
		$this->assertEqualsWithDelta( Tokens::contrast_ratio( '#fff', '#000' ), Tokens::contrast_ratio( '#ffffff', '#000000' ), 0.01 );
	}

	/**
	 * ensure_contrast returns a valid hex that meets the requested ratio.
	 *
	 * @return void
	 */
	public function test_ensure_contrast_meets_minimum(): void {
		$canvas = Tokens::themes()['dark']['canvas'];
		$fixed  = Tokens::ensure_contrast( '#f1c40f', $canvas, 3.0 );

		$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $fixed );
		$this->assertGreaterThanOrEqual( 3.0, Tokens::contrast_ratio( $fixed, $canvas ) );
	}

	/**
	 * Every curated palette color must reach 3:1 on both canvases after
	 * per-theme correction.
	 *
	 * @return void
	 */
	public function test_default_palette_passes_contrast_gate_on_both_themes(): void {
		$themes = Tokens::themes();

		foreach ( Tokens::type_palette() as $type => $color ) {
			foreach ( array( 'dark', 'light' ) as $theme ) {
				$canvas  = $themes[ $theme ]['canvas'];
				$fixed   = Tokens::ensure_contrast( $color, $canvas );
				$message = sprintf( 'Type "%s" (%s) must reach 3:1 on the %s canvas (got %s).', $type, $color, $theme, $fixed );

				$this->assertGreaterThanOrEqual( 3.0, Tokens::contrast_ratio( $fixed, $canvas ), $message );
			}
		}
	}

	/**
	 * Theme token sets are complete (every required key in both themes).
	 *
	 * @return void
	 */
	public function test_themes_define_full_token_sets(): void {
		$required = array( 'canvas', 'surface', 'node_label', 'edge', 'edge_hierarchy', 'edge_similarity', 'edge_reference', 'edge_authorship', 'edge_label', 'border', 'selection', 'accent', 'muted' );

		foreach ( Tokens::themes() as $theme => $tokens ) {
			foreach ( $required as $key ) {
				$this->assertArrayHasKey( $key, $tokens, "Theme {$theme} is missing the {$key} token." );
				$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $tokens[ $key ] );
			}
		}
	}

	/**
	 * visual_defaults() covers the full visual_* key set with valid enums.
	 *
	 * @return void
	 */
	public function test_visual_defaults_shape(): void {
		$defaults = Tokens::visual_defaults();

		$this->assertContains( $defaults['visual_theme'], array( 'dark', 'light', 'auto', 'admin' ) );
		$this->assertContains( $defaults['visual_color_by'], array( 'type', 'community', 'degree', 'monochrome' ) );
		$this->assertContains( $defaults['visual_icon_mode'], array( 'filled', 'outline', 'high' ) );
		$this->assertContains( $defaults['visual_edge_style'], array( 'plain', 'arrows', 'tapered', 'density', 'auto' ) );
		$this->assertContains( $defaults['visual_edge_labels'], array( 'off', 'hover', 'always' ) );
		$this->assertSame( array(), $defaults['visual_type_colors'] );
		$this->assertSame( array(), $defaults['visual_type_icons'] );

		// Every default must be present in the Schema defaults merge.
		$schemaDefaults = Schema::defaultSettings();
		foreach ( $defaults as $key => $value ) {
			$this->assertArrayHasKey( $key, $schemaDefaults, "Schema::defaultSettings() is missing {$key}." );
		}
	}

	/**
	 * visual_config() resolves settings into the nested JS delivery shape
	 * and falls back to defaults for invalid values.
	 *
	 * @return void
	 */
	public function test_visual_config_delivery_shape(): void {
		$config = Tokens::visual_config( array() );

		$this->assertSame( 'dark', $config['theme'] );
		$this->assertSame( 'type', $config['color_by'] );
		$this->assertTrue( $config['show_icons'] );
		$this->assertArrayHasKey( 'type_palette', $config );
		$this->assertArrayHasKey( 'community_palette', $config );
		$this->assertArrayHasKey( 'degree_ramp', $config );
		$this->assertArrayHasKey( 'edge_families', $config );
		$this->assertArrayHasKey( 'shape_map', $config );
		$this->assertArrayHasKey( 'dark', $config['themes'] );
		$this->assertArrayHasKey( 'light', $config['themes'] );
	}

	/**
	 * Invalid settings values fall back to defaults in visual_config().
	 *
	 * @return void
	 */
	public function test_visual_config_invalid_values_fall_back(): void {
		$config = Tokens::visual_config(
			array(
				'visual_theme'        => 'neon',
				'visual_color_by'     => 'mood',
				'visual_size_min'     => 999,
				'visual_size_max'     => 1,
				'visual_min_label_zoom' => 42,
			)
		);

		$this->assertSame( 'dark', $config['theme'] );
		$this->assertSame( 'type', $config['color_by'] );
		$this->assertLessThanOrEqual( 40, $config['size_min'] );
		$this->assertGreaterThanOrEqual( $config['size_min'], $config['size_max'] );
		$this->assertLessThanOrEqual( 1.0, $config['min_label_zoom'] );
	}

	/**
	 * type color overrides are sanitized (hex only, sanitized keys).
	 *
	 * @return void
	 */
	public function test_sanitize_type_colors(): void {
		$clean = Tokens::sanitize_type_colors(
			array(
				'post'        => '#ff0000',
				'bad color!'  => '#00ff00',
				'term'        => 'not-a-hex',
				'page'        => '#abc',
				'entity'      => 'javascript:alert(1)',
			)
		);

		$this->assertArrayHasKey( 'post', $clean );
		$this->assertSame( '#ff0000', $clean['post'] );
		$this->assertArrayNotHasKey( 'bad color!', $clean );
		$this->assertArrayNotHasKey( 'term', $clean );
		$this->assertArrayNotHasKey( 'entity', $clean );
	}

	/**
	 * Icon overrides are allowlisted against the icon catalog.
	 *
	 * @return void
	 */
	public function test_sanitize_type_icons(): void {
		$clean = Tokens::sanitize_type_icons(
			array(
				'post' => 'doc',
				'page' => 'not-an-icon',
				'term' => 'tag',
			)
		);

		$this->assertArrayHasKey( 'post', $clean );
		$this->assertSame( 'doc', $clean['post'] );
		$this->assertArrayNotHasKey( 'page', $clean );
		$this->assertSame( 'tag', $clean['term'] );
	}

	/**
	 * The contrast report covers every palette type and its fixes pass.
	 *
	 * @return void
	 */
	public function test_contrast_report_rows_and_fixes(): void {
		$report = Tokens::contrast_report( array() );

		$this->assertNotEmpty( $report );

		$types = array();
		foreach ( $report as $row ) {
			$types[] = $row['type'];
			$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $row['color'] );
			$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $row['fix_dark'] );
			$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $row['fix_light'] );
			$this->assertGreaterThanOrEqual( 3.0, Tokens::contrast_ratio( $row['fix_dark'], Tokens::themes()['dark']['canvas'] ) );
			$this->assertGreaterThanOrEqual( 3.0, Tokens::contrast_ratio( $row['fix_light'], Tokens::themes()['light']['canvas'] ) );
		}

		foreach ( array_keys( Tokens::type_palette() ) as $type ) {
			$this->assertContains( $type, $types, "Contrast report is missing type {$type}." );
		}
	}

	/**
	 * The type_palette and visual_config filters are applied.
	 *
	 * @return void
	 */
	public function test_filters_are_applied(): void {
		add_filter(
			Schema::FILTER_TYPE_PALETTE,
			static function ( array $palette ): array {
				$palette['widget'] = '#123456';
				return $palette;
			}
		);

		add_filter(
			Schema::FILTER_VISUAL_CONFIG,
			static function ( array $visual ): array {
				$visual['brand'] = 'nvoos';
				return $visual;
			},
			10,
			2
		);

		$this->assertArrayHasKey( 'widget', Tokens::type_palette() );

		$config = Tokens::visual_config( array() );
		$this->assertSame( 'nvoos', $config['brand'] );
	}
}
