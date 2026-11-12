<?php
/**
 * Test suite for deterministic media prompt assembly.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Prompt_Craft.
 */
class Test_Prompt_Craft extends WP_UnitTestCase {

	/**
	 * Load the composer.
	 */
	public function set_up() {
		parent::set_up();
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-prompt-craft.php';
	}

	/**
	 * Every vocabulary map is non-empty and keyed safely.
	 */
	public function test_vocabularies_are_populated() {
		$this->assertNotEmpty( WP_MCP_AI_Prompt_Craft::get_cameras() );
		$this->assertNotEmpty( WP_MCP_AI_Prompt_Craft::get_lenses() );
		$this->assertNotEmpty( WP_MCP_AI_Prompt_Craft::get_focal_lengths() );
		$this->assertNotEmpty( WP_MCP_AI_Prompt_Craft::get_apertures() );
		$this->assertNotEmpty( WP_MCP_AI_Prompt_Craft::get_styles() );

		foreach ( WP_MCP_AI_Prompt_Craft::get_cameras() as $slug ) {
			$this->assertSame( $slug, sanitize_key( $slug ), "Camera slug {$slug} is not key-safe" );
		}

		foreach ( WP_MCP_AI_Prompt_Craft::get_lenses() as $slug ) {
			$this->assertSame( $slug, sanitize_key( $slug ), "Lens slug {$slug} is not key-safe" );
		}

		foreach ( WP_MCP_AI_Prompt_Craft::get_styles() as $slug ) {
			$this->assertSame( $slug, sanitize_key( $slug ), "Style slug {$slug} is not key-safe" );
		}
	}

	/**
	 * The style enum accepts the slugs the video tools already expose.
	 */
	public function test_styles_cover_video_tool_enum() {
		$styles = WP_MCP_AI_Prompt_Craft::get_styles();

		foreach ( array( 'cinematic', 'realistic', 'anime', 'documentary', 'artistic' ) as $required ) {
			$this->assertContains( $required, $styles, "Style enum is missing {$required}" );
		}

		$this->assertContains( 'none', $styles, 'The "none" passthrough value must be accepted' );
	}

	/**
	 * With no options the base prompt is returned, minus a trailing period.
	 */
	public function test_compose_without_options() {
		$result = WP_MCP_AI_Prompt_Craft::compose( 'A cat sitting on a mat.' );

		$this->assertSame( 'A cat sitting on a mat', $result );
	}

	/**
	 * Camera, lens, focal length and aperture compose in a fixed order.
	 */
	public function test_compose_photographic_clause_order() {
		$result = WP_MCP_AI_Prompt_Craft::compose(
			'Portrait of a woman',
			array(
				'camera'       => 'classic-16mm-film',
				'lens'         => 'vintage-prime',
				'focal_length' => 85,
				'aperture'     => '1.4',
			)
		);

		$this->assertStringContainsString( 'Portrait of a woman', $result );
		$this->assertStringContainsString( 'shot on a classic 16mm film camera', $result );
		$this->assertStringContainsString( 'using a vintage prime lens', $result );
		$this->assertStringContainsString( '85mm (classic portrait perspective)', $result );
		$this->assertStringContainsString( 'aperture f/1.4, shallow depth of field, creamy bokeh', $result );

		// Order: camera, lens, focal, aperture.
		$camera_pos   = strpos( $result, 'shot on a' );
		$lens_pos     = strpos( $result, 'using a' );
		$focal_pos    = strpos( $result, '85mm' );
		$aperture_pos = strpos( $result, 'aperture f/1.4' );

		$this->assertTrue( $camera_pos < $lens_pos );
		$this->assertTrue( $lens_pos < $focal_pos );
		$this->assertTrue( $focal_pos < $aperture_pos );
	}

	/**
	 * An aperture may be supplied with or without the "f/" prefix.
	 */
	public function test_aperture_prefix_is_optional() {
		$plain = WP_MCP_AI_Prompt_Craft::compose( 'Shot', array( 'aperture' => '11' ) );
		$full  = WP_MCP_AI_Prompt_Craft::compose( 'Shot', array( 'aperture' => 'f/11' ) );

		$this->assertSame( $plain, $full );
		$this->assertStringContainsString( 'aperture f/11', $plain );
	}

	/**
	 * Unknown slugs and out-of-range focal lengths are ignored, not echoed.
	 */
	public function test_unknown_slugs_are_ignored() {
		$result = WP_MCP_AI_Prompt_Craft::compose(
			'Shot',
			array(
				'camera'       => 'not-a-camera',
				'lens'         => 'not-a-lens',
				'focal_length' => 123,
				'aperture'     => '2.8',
				'style'        => 'not-a-style',
			)
		);

		$this->assertSame( 'Shot', $result );
	}

	/**
	 * Quality tags are opt-in and appear once.
	 */
	public function test_quality_tags_are_opt_in_and_deduplicated() {
		$without = WP_MCP_AI_Prompt_Craft::compose( 'Shot' );
		$with    = WP_MCP_AI_Prompt_Craft::compose( 'Shot', array( 'include_quality_tags' => true ) );

		$this->assertStringNotContainsString( 'professional photography', $without );
		$this->assertStringContainsString( 'professional photography', $with );
		$this->assertSame( 1, substr_count( $with, 'ultra-detailed' ) );
	}

	/**
	 * Duplicate fragments collapse case-insensitively.
	 */
	public function test_extra_tags_deduplicate_against_style_fragments() {
		$result = WP_MCP_AI_Prompt_Craft::compose(
			'Shot',
			array(
				'style'      => 'cinematic',
				'extra_tags' => array( 'Cinematic Lighting', 'neon glow' ),
			)
		);

		$this->assertSame( 1, substr_count( strtolower( $result ), 'cinematic lighting' ) );
		$this->assertStringContainsString( 'neon glow', $result );
	}

	/**
	 * "none" adds nothing, so a video-tool enum can be passed straight through.
	 */
	public function test_none_style_is_a_no_op() {
		$this->assertSame( 'Shot', WP_MCP_AI_Prompt_Craft::compose( 'Shot', array( 'style' => 'none' ) ) );
	}

	/**
	 * Identity instructions use singular grammar for one reference.
	 */
	public function test_identity_lock_singular() {
		$result = WP_MCP_AI_Prompt_Craft::build_identity_lock(
			array(
				array(
					'name'  => 'Sarah',
					'count' => 1,
				),
			)
		);

		$this->assertSame( "Reference image 1 shows Sarah; keep this person's face, hair, skin tone and build exactly the same.", $result );
	}

	/**
	 * Numbering runs continuously from characters into items.
	 */
	public function test_identity_lock_numbering_continues_across_groups() {
		$result = WP_MCP_AI_Prompt_Craft::build_identity_lock(
			array(
				array(
					'name'  => 'Sarah',
					'count' => 2,
				),
			),
			array(
				array(
					'name'  => 'the serum bottle',
					'count' => 1,
				),
			)
		);

		$this->assertStringContainsString( 'Reference images 1-2 show Sarah;', $result );
		$this->assertStringContainsString( 'Reference image 3 shows the serum bottle;', $result );
	}

	/**
	 * Unnamed subjects fall back to a neutral noun phrase.
	 */
	public function test_identity_lock_defaults_names() {
		$result = WP_MCP_AI_Prompt_Craft::build_identity_lock(
			array( array( 'count' => 1 ) ),
			array( array( 'count' => 1 ) )
		);

		$this->assertStringContainsString( 'shows a person;', $result );
		$this->assertStringContainsString( 'shows a product;', $result );
	}

	/**
	 * Zero-count entries contribute nothing.
	 */
	public function test_identity_lock_ignores_empty_subjects() {
		$result = WP_MCP_AI_Prompt_Craft::build_identity_lock(
			array(
				array(
					'name'  => 'Sarah',
					'count' => 0,
				),
			)
		);

		$this->assertSame( '', $result );
	}

	/**
	 * Slot counting matches the descriptions emitted.
	 */
	public function test_count_identity_slots() {
		$characters = array(
			array(
				'name'  => 'A',
				'count' => 2,
			),
			array(
				'name'  => 'B',
				'count' => 3,
			),
		);
		$items      = array( array( 'count' => 1 ) );

		$this->assertSame( 6, WP_MCP_AI_Prompt_Craft::count_identity_slots( $characters, $items ) );
		$this->assertSame( 0, WP_MCP_AI_Prompt_Craft::count_identity_slots() );
	}

	/**
	 * Composition is deterministic.
	 */
	public function test_compose_is_deterministic() {
		$options = array(
			'style'                => 'product',
			'camera'               => 'studio-digital-s35',
			'focal_length'         => 50,
			'aperture'             => '4',
			'include_quality_tags' => true,
		);

		$this->assertSame(
			WP_MCP_AI_Prompt_Craft::compose( 'A serum bottle', $options ),
			WP_MCP_AI_Prompt_Craft::compose( 'A serum bottle', $options )
		);
	}
}
