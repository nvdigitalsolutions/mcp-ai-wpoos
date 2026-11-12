<?php
/**
 * Deterministic prompt assembly for generative-media requests.
 *
 * Composes camera, lens, focal-length, aperture, style, quality and
 * reference-identity fragments into a single prompt string. This is pure
 * string assembly: no provider call, no token cost, no non-determinism, so the
 * same input always yields byte-identical output and every fragment is unit
 * testable.
 *
 * Division of labour: the model decides *what* to shoot; this class decides how
 * to say the photographic and identity-consistency parts consistently.
 *
 * Fragments are intentionally not run through translation functions. They are
 * prompt payload sent to an image/video model, not interface copy — translating
 * them would silently change generation behaviour per site locale.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds prompt text from structured photographic and identity inputs.
 */
class WP_MCP_AI_Prompt_Craft {

	/**
	 * Camera bodies. Slug => label + prompt fragment.
	 */
	const CAMERAS = array(
		'modular-8k-digital'           => array(
			'label'    => 'Modular 8K Digital',
			'fragment' => 'modular 8K digital cinema camera',
		),
		'full-frame-cine-digital'      => array(
			'label'    => 'Full-Frame Cine Digital',
			'fragment' => 'full-frame digital cinema camera',
		),
		'grand-format-70mm-film'       => array(
			'label'    => 'Grand Format 70mm Film',
			'fragment' => 'grand format 70mm film camera',
		),
		'studio-digital-s35'           => array(
			'label'    => 'Studio Digital S35',
			'fragment' => 'Super 35 studio digital camera',
		),
		'classic-16mm-film'            => array(
			'label'    => 'Classic 16mm Film',
			'fragment' => 'classic 16mm film camera',
		),
		'premium-large-format-digital' => array(
			'label'    => 'Premium Large Format Digital',
			'fragment' => 'premium large-format digital cinema camera',
		),
	);

	/**
	 * Lens characters. Slug => label + prompt fragment.
	 */
	const LENSES = array(
		'creative-tilt'         => array(
			'label'    => 'Creative Tilt',
			'fragment' => 'creative tilt lens effect',
		),
		'compact-anamorphic'    => array(
			'label'    => 'Compact Anamorphic',
			'fragment' => 'compact anamorphic lens',
		),
		'extreme-macro'         => array(
			'label'    => 'Extreme Macro',
			'fragment' => 'extreme macro lens',
		),
		'70s-cinema-prime'      => array(
			'label'    => '70s Cinema Prime',
			'fragment' => '1970s cinema prime lens',
		),
		'classic-anamorphic'    => array(
			'label'    => 'Classic Anamorphic',
			'fragment' => 'classic anamorphic lens',
		),
		'premium-modern-prime'  => array(
			'label'    => 'Premium Modern Prime',
			'fragment' => 'premium modern prime lens',
		),
		'warm-cinema-prime'     => array(
			'label'    => 'Warm Cinema Prime',
			'fragment' => 'warm-toned cinema prime lens',
		),
		'swirl-bokeh-portrait'  => array(
			'label'    => 'Swirl Bokeh Portrait',
			'fragment' => 'swirl bokeh portrait lens',
		),
		'vintage-prime'         => array(
			'label'    => 'Vintage Prime',
			'fragment' => 'vintage prime lens',
		),
		'halation-diffusion'    => array(
			'label'    => 'Halation Diffusion',
			'fragment' => 'halation diffusion filter',
		),
		'clinical-sharp-prime'  => array(
			'label'    => 'Clinical Sharp Prime',
			'fragment' => 'ultra-sharp clinical prime lens',
		),
	);

	/**
	 * Focal lengths in millimetres => perspective fragment.
	 */
	const FOCAL_LENGTHS = array(
		8  => 'ultra-wide perspective',
		14 => 'wide-angle perspective',
		24 => 'wide-angle dynamic perspective',
		35 => 'natural cinematic perspective',
		50 => 'standard portrait perspective',
		85 => 'classic portrait perspective',
	);

	/**
	 * Apertures, keyed without the "f/" prefix. Key => label + effect fragment.
	 */
	const APERTURES = array(
		'1.4' => array(
			'label'    => 'f/1.4',
			'fragment' => 'shallow depth of field, creamy bokeh',
		),
		'4'   => array(
			'label'    => 'f/4',
			'fragment' => 'balanced depth of field',
		),
		'11'  => array(
			'label'    => 'f/11',
			'fragment' => 'deep focus, sharp foreground to background',
		),
	);

	/**
	 * Visual style presets => prompt fragments.
	 *
	 * The first six mirror the enum already exposed by the video tools
	 * (cinematic, realistic, anime, documentary, artistic, none) plus the
	 * product/ugc/design looks used by the media skills. "none" is accepted
	 * and contributes nothing so callers can pass a tool enum straight through.
	 */
	const STYLES = array(
		'cinematic'   => array( 'cinematic lighting', 'smooth camera movement', 'film-still colour grade', 'dramatic contrast' ),
		'realistic'   => array( 'photorealistic', 'natural lighting', 'true-to-life detail and texture' ),
		'ugc'         => array( 'authentic handheld smartphone footage', 'casual framing', 'natural available light' ),
		'cartoon'     => array( 'bold cartoon illustration', 'clean linework', 'flat vibrant colour' ),
		'product'     => array( 'clean commercial product photography', 'controlled studio lighting', 'seamless background' ),
		'design'      => array( 'graphic design layout', 'crisp legible typography', 'high contrast' ),
		'anime'       => array( 'anime style', 'cel-shaded colour', 'expressive line art' ),
		'documentary' => array( 'documentary photography', 'candid framing', 'available light' ),
		'artistic'    => array( 'fine-art composition', 'painterly texture', 'expressive colour' ),
		// Accepted so a video-tool enum can be passed straight through; adds nothing.
		'none'        => array(),
	);

	/**
	 * Quality fragments appended when include_quality_tags is on.
	 *
	 * Deliberately modest: inflated numeric claims ("8K resolution") consume
	 * prompt budget without reliably improving output.
	 */
	const QUALITY_TAGS = array( 'professional photography', 'ultra-detailed', 'high dynamic range' );

	/**
	 * Available camera slugs.
	 *
	 * @return string[]
	 */
	public static function get_cameras() {
		return array_keys( self::CAMERAS );
	}

	/**
	 * Available lens slugs.
	 *
	 * @return string[]
	 */
	public static function get_lenses() {
		return array_keys( self::LENSES );
	}

	/**
	 * Available focal lengths in millimetres.
	 *
	 * @return int[]
	 */
	public static function get_focal_lengths() {
		return array_keys( self::FOCAL_LENGTHS );
	}

	/**
	 * Available aperture keys (without the "f/" prefix).
	 *
	 * PHP casts numeric-string array keys such as "4" and "11" to integers,
	 * so the keys are re-cast to strings to keep the vocabulary strictly
	 * string-typed for schema enums and strict comparisons.
	 *
	 * @return string[]
	 */
	public static function get_apertures() {
		return array_map( 'strval', array_keys( self::APERTURES ) );
	}

	/**
	 * Available style slugs.
	 *
	 * @return string[]
	 */
	public static function get_styles() {
		return array_keys( self::STYLES );
	}

	/**
	 * Compose the final prompt.
	 *
	 * Recognised options: camera, lens, focal_length, aperture, style,
	 * include_quality_tags (bool), extra_tags (string[]).
	 *
	 * The base prompt loses a single trailing period so the result reads as one
	 * comma-separated clause list rather than mixed punctuation.
	 *
	 * @param string $base    Base prompt text.
	 * @param array  $options Composition options.
	 * @return string
	 */
	public static function compose( $base, $options = array() ) {
		$options = is_array( $options ) ? $options : array();
		$base    = is_string( $base ) ? trim( $base ) : '';

		$parts = array();

		if ( '' !== $base ) {
			$parts[] = rtrim( $base, ". \t\n\r\0\x0B" );
		}

		$style = isset( $options['style'] ) ? sanitize_key( (string) $options['style'] ) : '';
		if ( '' !== $style && isset( self::STYLES[ $style ] ) ) {
			foreach ( self::STYLES[ $style ] as $fragment ) {
				$parts[] = $fragment;
			}
		}

		$camera_clause = self::build_camera_clause( $options );
		if ( '' !== $camera_clause ) {
			$parts[] = $camera_clause;
		}

		if ( ! empty( $options['include_quality_tags'] ) ) {
			foreach ( self::QUALITY_TAGS as $fragment ) {
				$parts[] = $fragment;
			}
		}

		if ( isset( $options['extra_tags'] ) && is_array( $options['extra_tags'] ) ) {
			foreach ( $options['extra_tags'] as $tag ) {
				if ( ! is_scalar( $tag ) ) {
					continue;
				}
				$tag = trim( (string) $tag );
				if ( '' !== $tag ) {
					$parts[] = $tag;
				}
			}
		}

		return self::join_unique( $parts );
	}

	/**
	 * Build the camera/lens/focal/aperture clause.
	 *
	 * @param array $options Composition options.
	 * @return string Empty string when no photographic option was supplied.
	 */
	public static function build_camera_clause( $options = array() ) {
		$options = is_array( $options ) ? $options : array();
		$clauses = array();

		$camera = isset( $options['camera'] ) ? sanitize_key( (string) $options['camera'] ) : '';
		if ( '' !== $camera && isset( self::CAMERAS[ $camera ] ) ) {
			$clauses[] = 'shot on a ' . self::CAMERAS[ $camera ]['fragment'];
		}

		$lens = isset( $options['lens'] ) ? sanitize_key( (string) $options['lens'] ) : '';
		if ( '' !== $lens && isset( self::LENSES[ $lens ] ) ) {
			$clauses[] = 'using a ' . self::LENSES[ $lens ]['fragment'];
		}

		$focal = isset( $options['focal_length'] ) ? absint( $options['focal_length'] ) : 0;
		if ( $focal > 0 && isset( self::FOCAL_LENGTHS[ $focal ] ) ) {
			$clauses[] = $focal . 'mm (' . self::FOCAL_LENGTHS[ $focal ] . ')';
		}

		$aperture = isset( $options['aperture'] ) ? sanitize_text_field( (string) $options['aperture'] ) : '';
		$aperture = ltrim( $aperture, 'fF/' );

		if ( '' !== $aperture && isset( self::APERTURES[ $aperture ] ) ) {
			$clauses[] = 'aperture ' . self::APERTURES[ $aperture ]['label'] . ', ' . self::APERTURES[ $aperture ]['fragment'];
		}

		return implode( ', ', $clauses );
	}

	/**
	 * Build ordinal identity-lock instructions for reference media.
	 *
	 * Numbering runs continuously across characters then items, so the ordinals
	 * match the order in which references are actually sent.
	 *
	 * @param array $characters List of array( 'name' => string, 'count' => int ).
	 * @param array $items      List of array( 'name' => string, 'count' => int ).
	 * @return string Empty string when no reference slots were supplied.
	 */
	public static function build_identity_lock( $characters = array(), $items = array() ) {
		$sections = array();
		$next     = 1;

		$groups = array(
			'character' => $characters,
			'item'      => $items,
		);

		foreach ( $groups as $kind => $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			foreach ( $group as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$count = isset( $entry['count'] ) ? absint( $entry['count'] ) : 0;
				if ( $count < 1 ) {
					continue;
				}

				$name = isset( $entry['name'] ) ? sanitize_text_field( (string) $entry['name'] ) : '';
				if ( '' === $name ) {
					$name = 'character' === $kind ? 'a person' : 'a product';
				}

				$sections[] = self::identity_sentence( $next, $count, $name, $kind );
				$next      += $count;
			}
		}

		return implode( ' ', $sections );
	}

	/**
	 * Count the reference slots described by a character/item list.
	 *
	 * @param array $characters Character entries.
	 * @param array $items      Item entries.
	 * @return int
	 */
	public static function count_identity_slots( $characters = array(), $items = array() ) {
		$total = 0;

		foreach ( array( $characters, $items ) as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			foreach ( $group as $entry ) {
				if ( is_array( $entry ) && isset( $entry['count'] ) ) {
					$total += absint( $entry['count'] );
				}
			}
		}

		return $total;
	}

	/**
	 * Build one identity sentence with correct singular/plural ordinals.
	 *
	 * @param int    $start First reference number.
	 * @param int    $count Number of references.
	 * @param string $name  Subject name.
	 * @param string $kind  "character" or "item".
	 * @return string
	 */
	protected static function identity_sentence( $start, $count, $name, $kind ) {
		if ( 1 === $count ) {
			$subject = 'Reference image ' . $start . ' shows';
		} else {
			$subject = 'Reference images ' . $start . '-' . ( $start + $count - 1 ) . ' show';
		}

		$clause = 'character' === $kind
			? '; keep this person\'s face, hair, skin tone and build exactly the same.'
			: '; keep its shape, colours, materials, label and logo exactly the same.';

		return $subject . ' ' . $name . $clause;
	}

	/**
	 * Join fragments, trimming, dropping empties, and removing duplicates.
	 *
	 * Comparison is case-insensitive so "Cinematic lighting" and
	 * "cinematic lighting" do not both appear.
	 *
	 * @param array $parts Fragments.
	 * @return string
	 */
	protected static function join_unique( $parts ) {
		$seen   = array();
		$unique = array();

		foreach ( $parts as $part ) {
			$part = is_string( $part ) ? trim( $part ) : '';
			if ( '' === $part ) {
				continue;
			}

			$key = strtolower( $part );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$unique[]     = $part;
		}

		return implode( ', ', $unique );
	}
}
