<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Unit\Admin;

use NvoosContentGraph\Admin\Sections\AppearanceSection;
use WP_UnitTestCase;

/**
 * Unit tests for the Appearance settings section sanitization.
 *
 * @since 1.0.4
 */
class AppearanceSectionTest extends WP_UnitTestCase {

	/** @var AppearanceSection */
	private AppearanceSection $section;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->section = new AppearanceSection();
	}

	/**
	 * Scalar fields: enum values are enforced, invalid values fall back
	 * to the declared defaults.
	 *
	 * @return void
	 */
	public function test_sanitize_scalar_fields(): void {
		$out = $this->section->sanitize(
			array(
				'visual_theme'  => 'neon',
				'visual_color_by' => 'community',
				'visual_show_icons' => '0',
				'visual_icon_mode' => 'outline',
				'visual_edge_style' => 'density',
				'visual_edge_labels' => 'always',
				'visual_size_min' => '5',
				'visual_size_max' => '999',
				'visual_label_font_size' => '99',
				'visual_min_label_zoom' => '7',
				'visual_anim_enabled' => '1',
			)
		);

		$this->assertSame( 'dark', $out['visual_theme'] ); // invalid -> default.
		$this->assertSame( 'community', $out['visual_color_by'] );
		$this->assertSame( 0, $out['visual_show_icons'] );
		$this->assertSame( 'outline', $out['visual_icon_mode'] );
		$this->assertSame( 'density', $out['visual_edge_style'] );
		$this->assertSame( 'always', $out['visual_edge_labels'] );
		$this->assertSame( 8, $out['visual_size_min'] );   // clamped to min.
		$this->assertSame( 120, $out['visual_size_max'] ); // clamped to max.
		$this->assertSame( 16, $out['visual_label_font_size'] );
		$this->assertSame( 1.0, $out['visual_min_label_zoom'] ); // clamped 0..1.
		$this->assertSame( 1, $out['visual_anim_enabled'] );
	}

	/**
	 * Type color grid: hex values kept, junk dropped.
	 *
	 * @return void
	 */
	public function test_sanitize_type_colors_grid(): void {
		$out = $this->section->sanitize(
			array(
				'visual_type_colors' => array(
					'post' => '#ff0000',
					'term' => 'garbage',
				),
			)
		);

		$this->assertArrayHasKey( 'visual_type_colors', $out );
		$this->assertArrayHasKey( 'post', $out['visual_type_colors'] );
		$this->assertSame( '#ff0000', $out['visual_type_colors']['post'] );
		$this->assertArrayNotHasKey( 'term', $out['visual_type_colors'] );
	}

	/**
	 * Type icon grid: catalog slugs kept, unknown slugs dropped.
	 *
	 * @return void
	 */
	public function test_sanitize_type_icons_grid(): void {
		$out = $this->section->sanitize(
			array(
				'visual_type_icons' => array(
					'post' => 'doc',
					'page' => 'whatever',
				),
			)
		);

		$this->assertArrayHasKey( 'post', $out['visual_type_icons'] );
		$this->assertSame( 'doc', $out['visual_type_icons']['post'] );
		$this->assertArrayNotHasKey( 'page', $out['visual_type_icons'] );
	}

	/**
	 * Empty input still yields sanitized empty grids (no notices, no
	 * accidental wiping of unrelated settings).
	 *
	 * @return void
	 */
	public function test_sanitize_empty_input(): void {
		$out = $this->section->sanitize( array() );

		$this->assertSame( array(), $out['visual_type_colors'] );
		$this->assertSame( array(), $out['visual_type_icons'] );
		$this->assertArrayNotHasKey( 'visual_theme', $out );
	}

	/**
	 * Every declared field key is present in the visual defaults.
	 *
	 * @return void
	 */
	public function test_fields_are_all_visual_prefixed(): void {
		foreach ( array_keys( $this->section->get_fields() ) as $key ) {
			$this->assertStringStartsWith( 'visual_', $key, "Field {$key} must use the visual_ prefix." );
		}
	}
}
