<?php
/**
 * NV oOS Design System — Contrast Auditor.
 *
 * A single WCAG 2.2 contrast engine shared by the token linter and the email
 * auditor. Implements relative luminance and contrast ratio per WCAG 2.x and
 * evaluates the thresholds the design system cares about:
 *
 * - 4.5:1 for normal text (1.4.3 AA);
 * - 3:1 for large text, UI components and focus indicators (1.4.3 / 1.4.11);
 * - 7:1 / 4.5:1 for AAA where requested.
 *
 * @package NV_oOS_Design_System
 * @since   1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCAG contrast engine.
 *
 * @since 1.0.1
 */
class NV_oOS_Design_System_Contrast_Auditor {

	/**
	 * AA threshold for normal text.
	 *
	 * @var float
	 */
	const AA_NORMAL = 4.5;

	/**
	 * AA threshold for large text and UI components.
	 *
	 * @var float
	 */
	const AA_LARGE = 3.0;

	/**
	 * AAA threshold for normal text.
	 *
	 * @var float
	 */
	const AAA_NORMAL = 7.0;

	/**
	 * AAA threshold for large text.
	 *
	 * @var float
	 */
	const AAA_LARGE = 4.5;

	/**
	 * Parse a CSS colour into RGB (0–255), or null when it cannot be resolved
	 * without a browser (custom properties, unknown keywords).
	 *
	 * @param string $value CSS colour.
	 * @return array{0:int,1:int,2:int}|null
	 */
	public static function parse_color( $value ) {
		$value = strtolower( trim( (string) $value ) );

		$named = array(
			'white'   => '#ffffff',
			'black'   => '#000000',
			'red'     => '#ff0000',
			'green'   => '#008000',
			'blue'    => '#0000ff',
			'yellow'  => '#ffff00',
			'orange'  => '#ffa500',
			'purple'  => '#800080',
			'gray'    => '#808080',
			'grey'    => '#808080',
			'silver'  => '#c0c0c0',
			'maroon'  => '#800000',
			'navy'    => '#000080',
			'teal'    => '#008080',
			'olive'   => '#808000',
			'lime'    => '#00ff00',
			'aqua'    => '#00ffff',
			'cyan'    => '#00ffff',
			'fuchsia' => '#ff00ff',
			'magenta' => '#ff00ff',
		);
		if ( isset( $named[ $value ] ) ) {
			$value = $named[ $value ];
		}

		if ( preg_match( '/^#([0-9a-f]{3,8})$/', $value, $m ) ) {
			$hex = $m[1];
			if ( 3 === strlen( $hex ) || 4 === strlen( $hex ) ) {
				return array(
					hexdec( str_repeat( $hex[0], 2 ) ),
					hexdec( str_repeat( $hex[1], 2 ) ),
					hexdec( str_repeat( $hex[2], 2 ) ),
				);
			}
			if ( 6 === strlen( $hex ) || 8 === strlen( $hex ) ) {
				return array(
					hexdec( substr( $hex, 0, 2 ) ),
					hexdec( substr( $hex, 2, 2 ) ),
					hexdec( substr( $hex, 4, 2 ) ),
				);
			}
		}

		if ( preg_match( '/^rgba?\(([^)]+)\)$/', $value, $m ) ) {
			$parts = preg_split( '/\s*,\s*/', trim( $m[1] ) );
			if ( count( $parts ) >= 3 ) {
				return array(
					(int) max( 0, min( 255, (float) $parts[0] ) ),
					(int) max( 0, min( 255, (float) $parts[1] ) ),
					(int) max( 0, min( 255, (float) $parts[2] ) ),
				);
			}
		}

		if ( preg_match( '/^hsla?\(([^)]+)\)$/', $value, $m ) ) {
			$parts = preg_split( '/\s*,\s*/', trim( $m[1] ) );
			if ( count( $parts ) >= 3 ) {
				$h = (float) rtrim( $parts[0], 'deg' );
				$s = (float) rtrim( $parts[1], '%' );
				$l = (float) rtrim( $parts[2], '%' );
				return self::hsl_to_rgb( $h, $s, $l );
			}
		}

		return null;
	}

	/**
	 * WCAG relative luminance.
	 *
	 * @param array{0:int,1:int,2:int} $rgb RGB 0–255.
	 * @return float
	 */
	public static function relative_luminance( $rgb ) {
		$channels = array();
		foreach ( $rgb as $channel ) {
			$c          = $channel / 255;
			$channels[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * Contrast ratio between two CSS colours, or null when either cannot be
	 * resolved to RGB.
	 *
	 * @param string $foreground Foreground CSS colour.
	 * @param string $background Background CSS colour.
	 * @return float|null
	 */
	public static function ratio( $foreground, $background ) {
		$fg = self::parse_color( $foreground );
		$bg = self::parse_color( $background );

		if ( null === $fg || null === $bg ) {
			return null;
		}

		$l1 = self::relative_luminance( $fg );
		$l2 = self::relative_luminance( $bg );

		$lighter = max( $l1, $l2 );
		$darker  = min( $l1, $l2 );

		return ( $lighter + 0.05 ) / ( $darker + 0.05 );
	}

	/**
	 * WCAG level for a ratio.
	 *
	 * @param float $ratio Contrast ratio.
	 * @param bool  $large Whether the text is large (>= 18.66px bold or 24px).
	 * @return string `AAA`, `AA`, or `fail`.
	 */
	public static function level( $ratio, $large = false ) {
		if ( null === $ratio ) {
			return 'unresolved';
		}
		if ( $large ) {
			if ( $ratio >= self::AAA_LARGE ) {
				return 'AAA';
			}
			return $ratio >= self::AA_LARGE ? 'AA' : 'fail';
		}
		if ( $ratio >= self::AAA_NORMAL ) {
			return 'AAA';
		}
		return $ratio >= self::AA_NORMAL ? 'AA' : 'fail';
	}

	/**
	 * Audit a list of pairs.
	 *
	 * Each pair: `label`, `fg`, `bg`, optional `large` (bool), optional
	 * `non_text` (bool, forces the 3:1 threshold).
	 *
	 * @param array<int, array<string, mixed>> $pairs Pairs.
	 * @return array<int, array<string, mixed>>
	 */
	public static function audit_pairs( $pairs ) {
		$results = array();

		foreach ( $pairs as $pair ) {
			$label    = isset( $pair['label'] ) ? (string) $pair['label'] : '';
			$fg       = isset( $pair['fg'] ) ? (string) $pair['fg'] : '';
			$bg       = isset( $pair['bg'] ) ? (string) $pair['bg'] : '';
			$large    = ! empty( $pair['large'] );
			$non_text = ! empty( $pair['non_text'] );

			$ratio    = self::ratio( $fg, $bg );
			$required = ( $large || $non_text ) ? self::AA_LARGE : self::AA_NORMAL;

			$results[] = array(
				'label'    => $label,
				'fg'       => $fg,
				'bg'       => $bg,
				'ratio'    => $ratio,
				'required' => $required,
				'pass'     => null !== $ratio && $ratio >= $required,
				'level'    => self::level( $ratio, $large ),
				'large'    => $large,
				'non_text' => $non_text,
			);
		}

		return $results;
	}

	/**
	 * Derive the standard contrast pairs from a token map.
	 *
	 * @param array<string, NV_oOS_Design_System_Data_Token> $tokens Tokens keyed by id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function derive_pairs( $tokens ) {
		$get = static function ( $id, $fallback = '' ) use ( $tokens ) {
			return isset( $tokens[ $id ] ) ? (string) $tokens[ $id ]->value : $fallback;
		};

		$surface       = $get( 'color_surface', '#ffffff' );
		$surface_hover = $get( 'color_surface_hover', $surface );
		$text          = $get( 'color_text_primary', '#000000' );
		$text_muted    = $get( 'color_text_secondary', $text );
		$accent        = $get( 'color_accent', $text );
		$border        = $get( 'color_border_strong', $get( 'color_border', $text_muted ) );
		$focus         = $get( 'color_focus_ring', $accent );

		$pairs = array(
			array(
				'label' => __( 'Primary text on surface', 'nvoos-design-system' ),
				'fg'    => $text,
				'bg'    => $surface,
			),
			array(
				'label' => __( 'Secondary text on surface', 'nvoos-design-system' ),
				'fg'    => $text_muted,
				'bg'    => $surface,
			),
			array(
				'label' => __( 'Primary text on surface hover', 'nvoos-design-system' ),
				'fg'    => $text,
				'bg'    => $surface_hover,
			),
			array(
				'label' => __( 'Button text on accent', 'nvoos-design-system' ),
				'fg'    => $text,
				'bg'    => $accent,
			),
			array(
				'label'    => __( 'Border against surface (non-text)', 'nvoos-design-system' ),
				'fg'       => $border,
				'bg'       => $surface,
				'non_text' => true,
			),
			array(
				'label'    => __( 'Focus ring against surface (non-text)', 'nvoos-design-system' ),
				'fg'       => $focus,
				'bg'       => $surface,
				'non_text' => true,
			),
		);

		return $pairs;
	}

	/**
	 * Suggest the nearest compliant colour by adjusting HSL lightness.
	 *
	 * @param string $foreground Foreground CSS colour.
	 * @param string $background Background CSS colour.
	 * @param float  $target     Required ratio.
	 * @return string|null Suggested hex, or null when unresolvable.
	 */
	public static function suggest( $foreground, $background, $target = self::AA_NORMAL ) {
		$fg = self::parse_color( $foreground );
		$bg = self::parse_color( $background );

		if ( null === $fg || null === $bg ) {
			return null;
		}

		$hsl          = self::rgb_to_hsl( $fg[0], $fg[1], $fg[2] );
		$bg_luminance = self::relative_luminance( $bg );

		// Darken on light backgrounds, lighten on dark backgrounds.
		$direction = $bg_luminance > 0.5 ? -1 : 1;

		for ( $step = 1; $step <= 100; $step++ ) {
			$lightness = max( 0, min( 100, $hsl[2] + ( $direction * $step ) ) );
			$rgb       = self::hsl_to_rgb( $hsl[0], $hsl[1], $lightness );
			$ratio     = self::ratio( self::rgb_to_hex( $rgb ), $background );

			if ( null !== $ratio && $ratio >= $target ) {
				return self::rgb_to_hex( $rgb );
			}
		}

		return null;
	}

	// -----------------------------------------------------------------------
	// Colour helpers.
	// -----------------------------------------------------------------------

	/**
	 * HSL to RGB.
	 *
	 * @param float $h Hue 0–360.
	 * @param float $s Saturation 0–100.
	 * @param float $l Lightness 0–100.
	 * @return array{0:int,1:int,2:int}
	 */
	public static function hsl_to_rgb( $h, $s, $l ) {
		$h = fmod( ( $h % 360 + 360 ), 360 ) / 360;
		$s = max( 0, min( 100, $s ) ) / 100;
		$l = max( 0, min( 100, $l ) ) / 100;

		if ( 0.0 === $s ) {
			$v = (int) round( $l * 255 );
			return array( $v, $v, $v );
		}

		$q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
		$p = 2 * $l - $q;

		return array(
			(int) round( self::hue_to_rgb( $p, $q, $h + 1 / 3 ) * 255 ),
			(int) round( self::hue_to_rgb( $p, $q, $h ) * 255 ),
			(int) round( self::hue_to_rgb( $p, $q, $h - 1 / 3 ) * 255 ),
		);
	}

	/**
	 * RGB to HSL.
	 *
	 * @param int $r Red 0–255.
	 * @param int $g Green 0–255.
	 * @param int $b Blue 0–255.
	 * @return array{0:float,1:float,2:float}
	 */
	private static function rgb_to_hsl( $r, $g, $b ) {
		$r = $r / 255;
		$g = $g / 255;
		$b = $b / 255;

		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;
		$d   = $max - $min;

		if ( 0.0 === $d ) {
			return array( 0.0, 0.0, $l * 100 );
		}

		$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );

		if ( $max === $r ) {
			$h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
		} elseif ( $max === $g ) {
			$h = ( $b - $r ) / $d + 2;
		} else {
			$h = ( $r - $g ) / $d + 4;
		}

		return array( $h * 60, $s * 100, $l * 100 );
	}

	/**
	 * Hue-to-RGB helper.
	 *
	 * @param float $p First component.
	 * @param float $q Second component.
	 * @param float $t Hue offset.
	 * @return float
	 */
	private static function hue_to_rgb( $p, $q, $t ) {
		if ( $t < 0 ) {
			++$t;
		}
		if ( $t > 1 ) {
			--$t;
		}
		if ( $t < 1 / 6 ) {
			return $p + ( $q - $p ) * 6 * $t;
		}
		if ( $t < 1 / 2 ) {
			return $q;
		}
		if ( $t < 2 / 3 ) {
			return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
		}
		return $p;
	}

	/**
	 * RGB to hex.
	 *
	 * @param array{0:int,1:int,2:int} $rgb RGB.
	 * @return string
	 */
	public static function rgb_to_hex( $rgb ) {
		return sprintf( '#%02x%02x%02x', (int) $rgb[0], (int) $rgb[1], (int) $rgb[2] );
	}
}
