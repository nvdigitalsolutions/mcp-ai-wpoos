<?php
/**
 * NV oOS Design System — DTCG Codec.
 *
 * Converts between the flat NDS token registry (CSS strings) and the W3C
 * Design Tokens Community Group format (2025.10). Handles colour and
 * dimension objects, composite shadows, aliases, group extension, and the
 * lossless `$extensions` escape hatch for values that CSS can express but
 * DTCG cannot (e.g. `var(--…)` colours, `em` dimensions).
 *
 * The codec is deliberately WordPress-free: every method is static and uses
 * plain PHP so it can be exercised by the standalone test bootstrap.
 *
 * @package NV_oOS_Design_System
 * @since   1.0.0
 *
 * @see https://www.designtokens.org/tr/2025.10/format/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DTCG value codec.
 *
 * @since 1.0.0
 */
class NV_oOS_Design_System_DTCG_Codec {

	/**
	 * Vendor extension key. The spec requires tools to preserve unknown
	 * extension data, and this key carries the NDS-only metadata that DTCG
	 * cannot represent.
	 *
	 * @var string
	 */
	const EXTENSION_KEY = 'com.nvdigitalsolutions.nds';

	/**
	 * DTCG types this codec can emit.
	 *
	 * @var array<int, string>
	 */
	const DTCG_TYPES = array(
		'color',
		'dimension',
		'fontFamily',
		'fontWeight',
		'duration',
		'cubicBezier',
		'number',
		'strokeStyle',
		'border',
		'transition',
		'shadow',
		'gradient',
		'typography',
		'string',
	);

	/**
	 * Resolve the DTCG type for an NDS token.
	 *
	 * The registry's `type` is an input type (color/size/font/shadow/
	 * transition), which is too coarse for DTCG. Token ids carry the semantic
	 * intent, so intent wins; the input type is the fallback.
	 *
	 * @param NV_oOS_Design_System_Data_Token $token Token.
	 * @return string DTCG type.
	 */
	public static function dtcg_type_for_token( $token ) {
		$id = (string) $token->id;

		if ( 0 === strpos( $id, 'font_weight' ) ) {
			return 'fontWeight';
		}
		if ( 0 === strpos( $id, 'line_height' ) ) {
			return 'number';
		}
		if ( 0 === strpos( $id, 'font_family' ) ) {
			return 'fontFamily';
		}
		if ( 0 === strpos( $id, 'font_size' ) ) {
			return 'dimension';
		}
		if ( 0 === strpos( $id, 'space_' ) ) {
			return 'dimension';
		}
		if ( 0 === strpos( $id, 'shadow' ) ) {
			return 'shadow';
		}
		if ( 0 === strpos( $id, 'transition' ) || 0 === strpos( $id, 'motion' ) ) {
			return 'duration';
		}
		if ( 0 === strpos( $id, 'color_' ) ) {
			return 'color';
		}

		switch ( $token->type ) {
			case 'color':
				return 'color';
			case 'font':
				return 'fontFamily';
			case 'shadow':
				return 'shadow';
			case 'transition':
				return 'duration';
			case 'size':
			case 'spacing':
				return 'dimension';
			default:
				return 'string';
		}
	}

	/**
	 * Convert a registry token to a DTCG token entry.
	 *
	 * @param NV_oOS_Design_System_Data_Token $token Token.
	 * @return array<string, mixed> DTCG entry (`$type`, `$value`, optional metadata).
	 */
	public static function to_dtcg( $token ) {
		$type  = self::dtcg_type_for_token( $token );
		$entry = array( '$type' => $type );

		$converted = self::to_dtcg_value( $type, (string) $token->value );

		if ( isset( $converted['__type'] ) ) {
			// The value could not be represented as the semantic type; emit a
			// valid string token and record the intended type.
			$entry['$type']       = 'string';
			$entry['$value']      = (string) $converted['value'];
			$entry['$extensions'] = array(
				self::EXTENSION_KEY => array(
					'expectedType' => $type,
					'legacyValue'  => (string) $token->value,
				),
			);
		} else {
			$entry['$value'] = $converted['value'];
			if ( ! empty( $converted['extensions'] ) ) {
				$entry['$extensions'] = array( self::EXTENSION_KEY => $converted['extensions'] );
			}
		}

		if ( ! empty( $token->description ) ) {
			$entry['$description'] = (string) $token->description;
		}

		if ( ! empty( $token->deprecated ) ) {
			$entry['$deprecated'] = is_string( $token->deprecated ) ? $token->deprecated : true;
		}

		if ( ! empty( $token->meta ) && is_array( $token->meta ) ) {
			$extension                                   = isset( $entry['$extensions'][ self::EXTENSION_KEY ] )
				? $entry['$extensions'][ self::EXTENSION_KEY ]
				: array();
			$entry['$extensions'][ self::EXTENSION_KEY ] = array_merge( $extension, $token->meta );
		}

		return $entry;
	}

	/**
	 * Convert a CSS value to a DTCG value for a given type.
	 *
	 * @param string $type  DTCG type.
	 * @param string $value CSS value.
	 * @return array{value: mixed, extensions?: array}
	 */
	public static function to_dtcg_value( $type, $value ) {
		$value = trim( (string) $value );

		switch ( $type ) {
			case 'color':
				$color = self::css_color_to_dtcg( $value );
				return null === $color ? array(
					'__type' => true,
					'value'  => $value,
				) : array( 'value' => $color );

			case 'dimension':
				$dimension = self::css_dimension_to_dtcg( $value );
				return null === $dimension
					? array(
						'__type' => true,
						'value'  => $value,
					)
					: array( 'value' => $dimension );

			case 'fontWeight':
				return self::css_font_weight_to_dtcg( $value );

			case 'number':
				return is_numeric( $value )
					? array( 'value' => 0 + $value )
					: array(
						'__type' => true,
						'value'  => $value,
					);

			case 'duration':
				$duration = self::css_duration_to_dtcg( $value );
				return null === $duration
					? array(
						'__type' => true,
						'value'  => $value,
					)
					: array( 'value' => $duration );

			case 'fontFamily':
				$families = self::css_font_family_to_dtcg( $value );
				return array( 'value' => $families );

			case 'shadow':
				$shadow = self::css_shadow_to_dtcg( $value );
				return null === $shadow
					? array(
						'__type' => true,
						'value'  => $value,
					)
					: array( 'value' => $shadow );

			case 'string':
			default:
				return array( 'value' => $value );
		}
	}

	/**
	 * Convert a DTCG value to a CSS value.
	 *
	 * @param string $type  DTCG type.
	 * @param mixed  $value DTCG value.
	 * @return array{value: string, errors: array<int, string>, meta: array<string, mixed>}
	 */
	public static function from_dtcg_value( $type, $value ) {
		$errors = array();
		$meta   = array();

		switch ( $type ) {
			case 'color':
				$css = self::dtcg_color_to_css( $value, $errors, $meta );
				break;

			case 'dimension':
				$css = self::dtcg_dimension_to_css( $value, $errors );
				break;

			case 'fontWeight':
				$css = self::dtcg_font_weight_to_css( $value, $errors );
				break;

			case 'number':
				$css = is_numeric( $value ) ? (string) ( 0 + $value ) : '';
				if ( '' === $css ) {
					$errors[] = 'number value must be numeric.';
				}
				break;

			case 'duration':
				$css = self::dtcg_duration_to_css( $value, $errors );
				break;

			case 'fontFamily':
				$css = self::dtcg_font_family_to_css( $value, $errors );
				break;

			case 'shadow':
				$css = self::dtcg_shadow_to_css( $value, $errors );
				break;

			case 'string':
				$css = is_scalar( $value ) ? (string) $value : '';
				if ( '' === $css && ! is_string( $value ) ) {
					$errors[] = 'string value must be a scalar.';
				}
				break;

			default:
				$css      = is_scalar( $value ) ? (string) $value : '';
				$errors[] = sprintf( 'unsupported DTCG type "%s".', $type );
				break;
		}

		return array(
			'value'  => $css,
			'errors' => $errors,
			'meta'   => $meta,
		);
	}

	// -----------------------------------------------------------------------
	// Colour.
	// -----------------------------------------------------------------------

	/**
	 * Convert a CSS colour to a DTCG colour object, or null when the value is
	 * not representable (custom properties, exotic functions).
	 *
	 * @param string $value CSS colour.
	 * @return array|null
	 */
	public static function css_color_to_dtcg( $value ) {
		$value = trim( (string) $value );

		$named = self::named_color_to_hex( $value );
		if ( null !== $named ) {
			$value = $named;
		}

		if ( preg_match( '/^#([0-9a-fA-F]{3,8})$/', $value, $m ) ) {
			$rgba = self::hex_to_rgba( $m[1] );
			if ( null === $rgba ) {
				return null;
			}
			return self::rgba_to_dtcg( $rgba[0], $rgba[1], $rgba[2], $rgba[3] );
		}

		if ( preg_match( '/^rgba?\(([^)]+)\)$/i', $value, $m ) ) {
			$parts = self::split_color_components( $m[1] );
			if ( count( $parts ) < 3 ) {
				return null;
			}
			$r = self::component_to_number( $parts[0], 255 );
			$g = self::component_to_number( $parts[1], 255 );
			$b = self::component_to_number( $parts[2], 255 );
			$a = isset( $parts[3] ) ? self::component_to_number( $parts[3], 1, true ) : 1;
			if ( null === $r || null === $g || null === $b || null === $a ) {
				return null;
			}
			return self::rgba_to_dtcg( $r, $g, $b, $a );
		}

		if ( preg_match( '/^hsla?\(([^)]+)\)$/i', $value, $m ) ) {
			$parts = self::split_color_components( $m[1] );
			if ( count( $parts ) < 3 ) {
				return null;
			}
			$h = (float) rtrim( trim( $parts[0] ), 'deg' );
			$s = self::component_to_number( $parts[1], 100 );
			$l = self::component_to_number( $parts[2], 100 );
			$a = isset( $parts[3] ) ? self::component_to_number( $parts[3], 1, true ) : 1;
			if ( null === $s || null === $l || null === $a ) {
				return null;
			}
			$rgb                = self::hsl_to_rgb( $h, $s, $l );
			$obj                = self::rgba_to_dtcg( $rgb[0], $rgb[1], $rgb[2], $a );
			$obj['$extensions'] = array(
				self::EXTENSION_KEY => array(
					'sourceColorSpace' => 'hsl',
					'sourceValue'      => $value,
				),
			);
			return $obj;
		}

		return null;
	}

	/**
	 * Convert a DTCG colour object (or legacy string) to CSS.
	 *
	 * @param mixed                $value  DTCG value.
	 * @param array<int, string>   $errors Error accumulator (by reference).
	 * @param array<string, mixed> $meta   Meta accumulator (by reference).
	 * @return string
	 */
	public static function dtcg_color_to_css( $value, &$errors, &$meta ) {
		if ( is_string( $value ) ) {
			$value = trim( $value );
			if ( '' === $value ) {
				$errors[] = 'colour value is empty.';
				return '';
			}
			if ( preg_match( '/;\s*\}*\s*<|[{}<>]/', $value ) ) {
				$errors[] = 'colour string contains unsafe CSS characters.';
				return '';
			}
			return $value;
		}

		if ( ! is_array( $value ) ) {
			$errors[] = 'colour value must be an object or a legacy string.';
			return '';
		}

		$color_space = isset( $value['colorSpace'] ) ? (string) $value['colorSpace'] : '';
		$components  = isset( $value['components'] ) && is_array( $value['components'] ) ? $value['components'] : array();
		$hex         = isset( $value['hex'] ) && is_string( $value['hex'] ) ? $value['hex'] : '';

		// JSON is untrusted: casting an array or object to float silently
		// yields 1.0, which would produce a wrong colour instead of an error.
		$alpha = 1.0;
		if ( isset( $value['alpha'] ) ) {
			$alpha = self::numeric_or_null( $value['alpha'] );
			if ( null === $alpha ) {
				$errors[] = 'colour alpha must be numeric.';
				return '';
			}
		}

		if ( 'srgb' === $color_space && 3 === count( $components ) ) {
			$channels = array();
			foreach ( array_slice( $components, 0, 3 ) as $component ) {
				$number = self::numeric_or_null( $component );
				if ( null === $number ) {
					$errors[] = 'colour components must be numeric.';
					return '';
				}
				$channels[] = (int) round( max( 0, min( 1, $number ) ) * 255 );
			}

			return self::rgba_to_css( $channels[0], $channels[1], $channels[2], max( 0, min( 1, $alpha ) ) );
		}

		if ( '' !== $hex && preg_match( '/^#([0-9a-fA-F]{3,8})$/', $hex ) ) {
			if ( $alpha < 1 ) {
				$rgba = self::hex_to_rgba( ltrim( $hex, '#' ) );
				if ( null !== $rgba ) {
					return self::rgba_to_css( $rgba[0], $rgba[1], $rgba[2], $alpha );
				}
			}
			return $hex;
		}

		$errors[] = sprintf( 'unsupported DTCG colour (colorSpace "%s", no usable hex fallback).', $color_space );
		return '';
	}

	// -----------------------------------------------------------------------
	// Dimension / duration / weight / font.
	// -----------------------------------------------------------------------

	/**
	 * Convert a CSS dimension to a DTCG dimension object.
	 *
	 * @param string $value CSS value.
	 * @return array|null
	 */
	public static function css_dimension_to_dtcg( $value ) {
		if ( preg_match( '/^(-?\d+(?:\.\d+)?)(px|rem)$/', trim( (string) $value ), $m ) ) {
			return array(
				'value' => 0 + $m[1],
				'unit'  => $m[2],
			);
		}
		return null;
	}

	/**
	 * Convert a DTCG dimension object to CSS.
	 *
	 * @param mixed              $value  DTCG value.
	 * @param array<int, string> $errors Error accumulator.
	 * @return string
	 */
	public static function dtcg_dimension_to_css( $value, &$errors ) {
		if ( is_string( $value ) ) {
			if ( preg_match( '/^-?\d+(?:\.\d+)?(px|rem|em|%|vh|vw|ch|ex|pt|pc|in|cm|mm)?$/', trim( $value ) ) ) {
				return trim( $value );
			}
			$errors[] = 'dimension string is not a valid CSS length.';
			return '';
		}

		if ( ! is_array( $value ) || ! isset( $value['value'], $value['unit'] ) ) {
			$errors[] = 'dimension value must be an object with value and unit.';
			return '';
		}

		$unit = (string) $value['unit'];
		if ( ! in_array( $unit, array( 'px', 'rem' ), true ) ) {
			$errors[] = sprintf( 'dimension unit "%s" is not px or rem.', $unit );
			return '';
		}

		// JSON is untrusted: a non-numeric value would raise a TypeError on
		// the arithmetic coercion below.
		$number = $value['value'];
		if ( ! is_int( $number ) && ! is_float( $number ) && ! ( is_string( $number ) && is_numeric( $number ) ) ) {
			$errors[] = 'dimension value must be numeric.';
			return '';
		}

		return (string) ( 0 + $number ) . $unit;
	}

	/**
	 * Convert a CSS duration to a DTCG duration object.
	 *
	 * @param string $value CSS value.
	 * @return array|null
	 */
	public static function css_duration_to_dtcg( $value ) {
		if ( preg_match( '/^(\d+(?:\.\d+)?)(ms|s)$/', trim( (string) $value ), $m ) ) {
			return array(
				'value' => 0 + $m[1],
				'unit'  => $m[2],
			);
		}
		return null;
	}

	/**
	 * Convert a DTCG duration object to CSS.
	 *
	 * @param mixed              $value  DTCG value.
	 * @param array<int, string> $errors Error accumulator.
	 * @return string
	 */
	public static function dtcg_duration_to_css( $value, &$errors ) {
		if ( is_string( $value ) ) {
			if ( preg_match( '/^\d+(?:\.\d+)?(ms|s)$/', trim( $value ) ) ) {
				return trim( $value );
			}
			$errors[] = 'duration string is not a valid CSS time.';
			return '';
		}

		if ( ! is_array( $value ) || ! isset( $value['value'], $value['unit'] ) ) {
			$errors[] = 'duration value must be an object with value and unit.';
			return '';
		}

		$unit = (string) $value['unit'];
		if ( ! in_array( $unit, array( 'ms', 's' ), true ) ) {
			$errors[] = sprintf( 'duration unit "%s" is not ms or s.', $unit );
			return '';
		}

		// JSON is untrusted: a non-numeric value would raise a TypeError on
		// the arithmetic coercion below.
		$number = $value['value'];
		if ( ! is_int( $number ) && ! is_float( $number ) && ! ( is_string( $number ) && is_numeric( $number ) ) ) {
			$errors[] = 'duration value must be numeric.';
			return '';
		}

		return (string) ( 0 + $number ) . $unit;
	}

	/**
	 * Convert a CSS font weight to a DTCG value.
	 *
	 * @param string $value CSS value.
	 * @return array{value: mixed, __type?: bool}
	 */
	public static function css_font_weight_to_dtcg( $value ) {
		$value = trim( (string) $value );
		if ( is_numeric( $value ) && (float) $value >= 1 && (float) $value <= 1000 ) {
			return array( 'value' => 0 + $value );
		}

		$keywords = array(
			'thin'        => 100,
			'hairline'    => 100,
			'extra-light' => 200,
			'ultra-light' => 200,
			'light'       => 300,
			'normal'      => 400,
			'regular'     => 400,
			'book'        => 400,
			'medium'      => 500,
			'semi-bold'   => 600,
			'demi-bold'   => 600,
			'bold'        => 700,
			'extra-bold'  => 800,
			'ultra-bold'  => 800,
			'black'       => 900,
			'heavy'       => 900,
			'extra-black' => 950,
			'ultra-black' => 950,
		);

		$key = strtolower( $value );
		if ( isset( $keywords[ $key ] ) ) {
			return array( 'value' => $keywords[ $key ] );
		}

		return array(
			'__type' => true,
			'value'  => $value,
		);
	}

	/**
	 * Convert a DTCG font weight to CSS.
	 *
	 * @param mixed              $value  DTCG value.
	 * @param array<int, string> $errors Error accumulator.
	 * @return string
	 */
	public static function dtcg_font_weight_to_css( $value, &$errors ) {
		if ( is_numeric( $value ) ) {
			$weight = (float) $value;
			if ( $weight < 1 || $weight > 1000 ) {
				$errors[] = 'fontWeight must be between 1 and 1000.';
				return '';
			}
			return (string) ( 0 + $value );
		}

		if ( is_string( $value ) && preg_match( '/^[a-z-]{3,20}$/i', $value ) ) {
			return $value;
		}

		$errors[] = 'fontWeight must be a number or a keyword.';
		return '';
	}

	/**
	 * Convert a CSS font stack to a DTCG fontFamily value.
	 *
	 * @param string $value CSS value.
	 * @return string|array
	 */
	public static function css_font_family_to_dtcg( $value ) {
		$parts = array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) );
		if ( 1 === count( $parts ) ) {
			return $parts[0];
		}
		return $parts;
	}

	/**
	 * Convert a DTCG fontFamily value to CSS.
	 *
	 * @param mixed              $value  DTCG value.
	 * @param array<int, string> $errors Error accumulator.
	 * @return string
	 */
	public static function dtcg_font_family_to_css( $value, &$errors ) {
		if ( is_string( $value ) ) {
			return trim( $value );
		}

		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $family ) {
				if ( is_string( $family ) && '' !== trim( $family ) ) {
					$parts[] = trim( $family );
				}
			}
			if ( ! empty( $parts ) ) {
				return implode( ', ', $parts );
			}
		}

		$errors[] = 'fontFamily must be a string or an array of strings.';
		return '';
	}

	// -----------------------------------------------------------------------
	// Shadow.
	// -----------------------------------------------------------------------

	/**
	 * Convert a CSS box-shadow to a DTCG shadow object.
	 *
	 * @param string $value CSS value.
	 * @return array|null
	 */
	public static function css_shadow_to_dtcg( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || 'none' === strtolower( $value ) ) {
			return null;
		}

		// Single shadow only. Multi-shadow strings are preserved as strings.
		if ( false !== strpos( $value, ',' ) && ! preg_match( '/rgba?\([^)]*\)/', $value ) ) {
			return null;
		}

		$inset = false;
		if ( preg_match( '/^inset\s+/i', $value ) ) {
			$inset = true;
			$value = preg_replace( '/^inset\s+/i', '', $value );
		}

		$pattern = '/^(-?(?:\d+(?:\.\d+)?(?:px|rem)|0(?:\.0+)?))\s+(-?(?:\d+(?:\.\d+)?(?:px|rem)|0(?:\.0+)?))\s+(-?(?:\d+(?:\.\d+)?(?:px|rem)|0(?:\.0+)?))(?:\s+(-?(?:\d+(?:\.\d+)?(?:px|rem)|0(?:\.0+)?)))?\s+(.+)$/';
		if ( ! preg_match( $pattern, trim( $value ), $m ) ) {
			return null;
		}

		$color = self::css_color_to_dtcg( trim( $m[5] ) );
		if ( null === $color ) {
			return null;
		}

		$offset_x = self::shadow_length_to_dtcg( $m[1] );
		$offset_y = self::shadow_length_to_dtcg( $m[2] );
		$blur     = self::shadow_length_to_dtcg( $m[3] );
		$spread   = isset( $m[4] ) && '' !== $m[4] ? self::shadow_length_to_dtcg( $m[4] ) : array(
			'value' => 0,
			'unit'  => 'px',
		);

		if ( null === $offset_x || null === $offset_y || null === $blur || null === $spread ) {
			return null;
		}

		$shadow = array(
			'color'   => $color,
			'offsetX' => $offset_x,
			'offsetY' => $offset_y,
			'blur'    => $blur,
			'spread'  => $spread,
		);

		if ( $inset ) {
			$shadow['inset'] = true;
		}

		return $shadow;
	}

	/**
	 * Convert a shadow length, normalising a bare zero to `0px`.
	 *
	 * CSS allows unitless zero for shadow offsets, blur and spread; DTCG
	 * dimensions require a unit.
	 *
	 * @param string $value Raw length.
	 * @return array|null
	 */
	private static function shadow_length_to_dtcg( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^-?0(?:\.0+)?$/', $value ) ) {
			return array(
				'value' => 0,
				'unit'  => 'px',
			);
		}
		return self::css_dimension_to_dtcg( $value );
	}

	/**
	 * Convert a DTCG shadow to CSS.
	 *
	 * @param mixed              $value  DTCG value.
	 * @param array<int, string> $errors Error accumulator.
	 * @return string
	 */
	public static function dtcg_shadow_to_css( $value, &$errors ) {
		if ( is_string( $value ) ) {
			return trim( $value );
		}

		$shadows = isset( $value['offsetX'] ) ? array( $value ) : ( is_array( $value ) ? $value : array() );
		$parts   = array();

		foreach ( $shadows as $shadow ) {
			if ( ! is_array( $shadow ) ) {
				$errors[] = 'shadow entries must be objects.';
				continue;
			}

			$inset = ! empty( $shadow['inset'] ) ? 'inset ' : '';
			$color = '';
			if ( isset( $shadow['color'] ) ) {
				$tmp_errors = array();
				$tmp_meta   = array();
				$color      = self::dtcg_color_to_css( $shadow['color'], $tmp_errors, $tmp_meta );
				$errors     = array_merge( $errors, $tmp_errors );
			}

			$offset_x = isset( $shadow['offsetX'] ) ? self::shadow_length_to_css( $shadow['offsetX'], $errors ) : '';
			$offset_y = isset( $shadow['offsetY'] ) ? self::shadow_length_to_css( $shadow['offsetY'], $errors ) : '';
			$blur     = isset( $shadow['blur'] ) ? self::shadow_length_to_css( $shadow['blur'], $errors ) : '';
			$spread   = isset( $shadow['spread'] ) ? self::shadow_length_to_css( $shadow['spread'], $errors ) : '';

			// Canonical form: unitless zero, and a zero spread is omitted. This
			// keeps exporter/importer round-trips byte-identical.
			$lengths = array( $offset_x, $offset_y, $blur );
			if ( '' !== $spread && '0' !== $spread ) {
				$lengths[] = $spread;
			}

			$parts[] = trim( $inset . implode( ' ', $lengths ) . ' ' . $color );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Convert a shadow length to canonical CSS (unitless zero).
	 *
	 * @param mixed              $value  DTCG dimension.
	 * @param array<int, string> $errors Error accumulator.
	 * @return string
	 */
	private static function shadow_length_to_css( $value, &$errors ) {
		$css = self::dtcg_dimension_to_css( $value, $errors );
		if ( '0px' === $css || '0rem' === $css ) {
			return '0';
		}
		return $css;
	}

	/**
	 * Accept an int, float or numeric string; reject everything else.
	 *
	 * `(float) $array` silently yields 1.0, so untrusted JSON must be checked
	 * before any numeric cast.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Candidate value.
	 * @return float|null
	 */
	private static function numeric_or_null( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}

		if ( is_string( $value ) && is_numeric( trim( $value ) ) ) {
			return (float) trim( $value );
		}

		return null;
	}

	// -----------------------------------------------------------------------
	// Document resolution (groups + aliases).
	// -----------------------------------------------------------------------

	/**
	 * Expand `$extends`, flatten `$root`, and resolve `{group.token}` aliases.
	 *
	 * @param array $doc Decoded DTCG document.
	 * @return array{groups: array<string, array<string, array>>, errors: array<int, string>}
	 */
	public static function resolve_document( $doc ) {
		$errors   = array();
		$expanded = self::expand_groups( $doc, $errors );
		$groups   = self::normalise_groups( $expanded, $errors );

		$flat = array();
		foreach ( $groups as $group => $tokens ) {
			foreach ( $tokens as $id => $entry ) {
				$flat[ $group . '.' . $id ] = $entry;
			}
		}

		foreach ( $groups as $group => $tokens ) {
			foreach ( $tokens as $id => $entry ) {
				if ( isset( $entry['$value'] ) && is_string( $entry['$value'] ) && preg_match( '/^\{([^}]+)\}$/', trim( $entry['$value'] ), $m ) ) {
					$resolved = self::resolve_alias( trim( $m[1] ), $flat, array() );
					if ( isset( $resolved['error'] ) ) {
						$errors[] = sprintf( '%s/%s: %s', $group, $id, $resolved['error'] );
						continue;
					}
					$groups[ $group ][ $id ]['$value']       = $resolved['value'];
					$groups[ $group ][ $id ]['$aliasOf']     = trim( $m[1] );
					$groups[ $group ][ $id ]['$aliasOfType'] = isset( $resolved['type'] ) ? $resolved['type'] : '';
				}
			}
		}

		return array(
			'groups' => $groups,
			'errors' => $errors,
		);
	}

	/**
	 * Recursively expand group `$extends` (deep merge, local wins).
	 *
	 * @param array              $node   Document or group node.
	 * @param array<int, string> $errors Error accumulator.
	 * @param array<int, string> $stack  Group path stack for cycle detection.
	 * @return array
	 */
	private static function expand_groups( $node, &$errors, $stack = array() ) {
		if ( ! is_array( $node ) ) {
			return array();
		}

		$out = array();
		foreach ( $node as $key => $child ) {
			if ( 0 === strpos( (string) $key, '$' ) ) {
				$out[ $key ] = $child;
				continue;
			}

			if ( ! is_array( $child ) ) {
				$out[ $key ] = $child;
				continue;
			}

			if ( isset( $child['$extends'] ) && is_string( $child['$extends'] ) && preg_match( '/^\{([^}]+)\}$/', trim( $child['$extends'] ), $m ) ) {
				$target_path = trim( $m[1] );
				if ( in_array( $target_path, $stack, true ) ) {
					$errors[] = sprintf( 'circular group extension: %s.', $target_path );
					continue;
				}

				$target = self::find_group( $node, $target_path );
				if ( null === $target ) {
					$errors[] = sprintf( 'group $extends target "%s" not found.', $target_path );
					continue;
				}

				$target_expanded = self::expand_groups( $target, $errors, array_merge( $stack, array( $target_path ) ) );
				$child_expanded  = self::expand_groups( $child, $errors, array_merge( $stack, array( $key ) ) );
				unset( $child_expanded['$extends'] );

				$out[ $key ] = self::deep_merge( $target_expanded, $child_expanded );
				continue;
			}

			$out[ $key ] = self::expand_groups( $child, $errors, array_merge( $stack, array( (string) $key ) ) );
		}

		return $out;
	}

	/**
	 * Find a group by dotted path in a document.
	 *
	 * @param array  $doc  Document.
	 * @param string $path Dotted path.
	 * @return array|null
	 */
	private static function find_group( $doc, $path ) {
		$parts = explode( '.', $path );
		$node  = $doc;
		foreach ( $parts as $part ) {
			if ( ! is_array( $node ) || ! isset( $node[ $part ] ) || ! is_array( $node[ $part ] ) ) {
				return null;
			}
			$node = $node[ $part ];
		}
		return $node;
	}

	/**
	 * Deep-merge two arrays; the second argument wins for scalars.
	 *
	 * @param array $base Base array.
	 * @param array $over Overrides.
	 * @return array
	 */
	private static function deep_merge( $base, $over ) {
		foreach ( $over as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! self::is_token_entry( $value ) ) {
				$base[ $key ] = self::deep_merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/**
	 * Whether an array is a token entry (has `$value`).
	 *
	 * @param array $value Array.
	 * @return bool
	 */
	private static function is_token_entry( $value ) {
		return is_array( $value ) && array_key_exists( '$value', $value );
	}

	/**
	 * Normalise groups: map `$root` to `<group>_root`, flatten one level.
	 *
	 * @param array              $doc    Expanded document.
	 * @param array<int, string> $errors Error accumulator.
	 * @return array<string, array<string, array>>
	 */
	private static function normalise_groups( $doc, &$errors ) {
		$groups = array();

		foreach ( $doc as $group_key => $group ) {
			if ( 0 === strpos( (string) $group_key, '$' ) ) {
				continue;
			}
			if ( ! is_array( $group ) ) {
				continue;
			}

			$group_type = isset( $group['$type'] ) && is_string( $group['$type'] ) ? $group['$type'] : '';
			$tokens     = array();

			foreach ( $group as $id => $entry ) {
				if ( 0 === strpos( (string) $id, '$' ) ) {
					continue;
				}
				if ( ! is_array( $entry ) || ! array_key_exists( '$value', $entry ) ) {
					// Nested groups are unfolded into dotted ids for flat registry
					// compatibility, with group type inheritance.
					if ( is_array( $entry ) ) {
						$nested = self::normalise_nested_group( $entry, $group_type );
						if ( empty( $nested ) && isset( $entry['$type'] ) ) {
							// A `$type` with no `$value` and no child tokens is a
							// malformed token, not an empty group.
							$errors[] = sprintf( '%s/%s: token entry must be a JSON object with $value.', $group_key, $id );
							continue;
						}
						foreach ( $nested as $nested_id => $nested_entry ) {
							$tokens[ $id . '_' . $nested_id ] = $nested_entry;
						}
					}
					continue;
				}
				if ( '' === $group_type || isset( $entry['$type'] ) ) {
					$tokens[ $id ] = $entry;
				} else {
					$entry['$type'] = $group_type;
					$tokens[ $id ]  = $entry;
				}
			}

			if ( isset( $group['$root'] ) && is_array( $group['$root'] ) ) {
				$root_entry = $group['$root'];
				if ( '' !== $group_type && ! isset( $root_entry['$type'] ) ) {
					$root_entry['$type'] = $group_type;
				}
				$tokens[ $group_key . '_root' ] = $root_entry;
			}

			if ( ! empty( $tokens ) ) {
				$groups[ (string) $group_key ] = $tokens;
			}
		}

		return $groups;
	}

	/**
	 * Flatten a nested group into underscore-separated ids.
	 *
	 * @param array  $group      Nested group.
	 * @param string $group_type Inherited type.
	 * @return array<string, array>
	 */
	private static function normalise_nested_group( $group, $group_type ) {
		$out = array();
		foreach ( $group as $id => $entry ) {
			if ( 0 === strpos( (string) $id, '$' ) ) {
				continue;
			}
			if ( is_array( $entry ) && array_key_exists( '$value', $entry ) ) {
				if ( '' !== $group_type && ! isset( $entry['$type'] ) ) {
					$entry['$type'] = $group_type;
				}
				$out[ (string) $id ] = $entry;
			} elseif ( is_array( $entry ) ) {
				foreach ( self::normalise_nested_group( $entry, $group_type ) as $nested_id => $nested_entry ) {
					$out[ (string) $id . '_' . $nested_id ] = $nested_entry;
				}
			}
		}
		return $out;
	}

	/**
	 * Resolve a single alias path.
	 *
	 * @param string              $path  Dotted token path.
	 * @param array<string,array> $flat  Flat token map.
	 * @param array<int, string>  $stack Resolution stack.
	 * @return array{value?: mixed, type?: string, error?: string}
	 */
	private static function resolve_alias( $path, $flat, $stack ) {
		if ( in_array( $path, $stack, true ) ) {
			return array( 'error' => sprintf( 'circular reference: %s.', implode( ' -> ', array_merge( $stack, array( $path ) ) ) ) );
		}

		if ( ! isset( $flat[ $path ] ) ) {
			return array( 'error' => sprintf( 'unresolved reference: {%s}.', $path ) );
		}

		$entry = $flat[ $path ];
		$value = isset( $entry['$value'] ) ? $entry['$value'] : null;

		if ( is_string( $value ) && preg_match( '/^\{([^}]+)\}$/', trim( $value ), $m ) ) {
			return self::resolve_alias( trim( $m[1] ), $flat, array_merge( $stack, array( $path ) ) );
		}

		return array(
			'value' => $value,
			'type'  => isset( $entry['$type'] ) ? (string) $entry['$type'] : '',
		);
	}

	// -----------------------------------------------------------------------
	// Small helpers.
	// -----------------------------------------------------------------------

	/**
	 * Parse a hex colour into RGBA (0–255, alpha 0–1).
	 *
	 * @param string $hex Hex without the leading #.
	 * @return array{0:int,1:int,2:int,3:float}|null
	 */
	private static function hex_to_rgba( $hex ) {
		$hex = preg_replace( '/[^0-9a-fA-F]/', '', (string) $hex );

		if ( 3 === strlen( $hex ) || 4 === strlen( $hex ) ) {
			$r = hexdec( str_repeat( $hex[0], 2 ) );
			$g = hexdec( str_repeat( $hex[1], 2 ) );
			$b = hexdec( str_repeat( $hex[2], 2 ) );
			$a = 4 === strlen( $hex ) ? hexdec( str_repeat( $hex[3], 2 ) ) / 255 : 1.0;
			return array( (int) $r, (int) $g, (int) $b, (float) $a );
		}

		if ( 6 === strlen( $hex ) || 8 === strlen( $hex ) ) {
			$r = hexdec( substr( $hex, 0, 2 ) );
			$g = hexdec( substr( $hex, 2, 2 ) );
			$b = hexdec( substr( $hex, 4, 2 ) );
			$a = 8 === strlen( $hex ) ? hexdec( substr( $hex, 6, 2 ) ) / 255 : 1.0;
			return array( (int) $r, (int) $g, (int) $b, (float) $a );
		}

		return null;
	}

	/**
	 * Build a DTCG colour object from RGBA.
	 *
	 * @param int   $r Red 0–255.
	 * @param int   $g Green 0–255.
	 * @param int   $b Blue 0–255.
	 * @param float $a Alpha 0–1.
	 * @return array
	 */
	private static function rgba_to_dtcg( $r, $g, $b, $a ) {
		$hex = sprintf( '#%02x%02x%02x', (int) $r, (int) $g, (int) $b );
		$obj = array(
			'colorSpace' => 'srgb',
			'components' => array(
				round( $r / 255, 5 ),
				round( $g / 255, 5 ),
				round( $b / 255, 5 ),
			),
			'hex'        => $hex,
		);
		if ( $a < 1 ) {
			$obj['alpha'] = round( $a, 5 );
		}
		return $obj;
	}

	/**
	 * Build CSS from RGBA.
	 *
	 * @param int   $r Red 0–255.
	 * @param int   $g Green 0–255.
	 * @param int   $b Blue 0–255.
	 * @param float $a Alpha 0–1.
	 * @return string
	 */
	private static function rgba_to_css( $r, $g, $b, $a ) {
		if ( $a < 1 ) {
			return sprintf( 'rgba(%d, %d, %d, %s)', (int) $r, (int) $g, (int) $b, rtrim( rtrim( sprintf( '%.3f', $a ), '0' ), '.' ) );
		}
		return sprintf( '#%02x%02x%02x', (int) $r, (int) $g, (int) $b );
	}

	/**
	 * Split comma-separated colour components, tolerating spaces and slashes.
	 *
	 * @param string $value Component string.
	 * @return array<int, string>
	 */
	private static function split_color_components( $value ) {
		$value = str_replace( '/', ' ', (string) $value );
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );
	}

	/**
	 * Convert a colour component to a number, honouring percentages.
	 *
	 * @param string $value    Component.
	 * @param float  $max      Maximum for a 0–1 scale.
	 * @param bool   $is_alpha Whether this is the alpha component.
	 * @return float|null
	 */
	private static function component_to_number( $value, $max, $is_alpha = false ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		if ( '%' === substr( $value, -1 ) ) {
			$number = (float) rtrim( $value, '%' );
			return $is_alpha ? max( 0, min( 1, $number / 100 ) ) : max( 0, min( 255, $number * 255 / 100 ) );
		}

		$number = (float) $value;
		if ( $is_alpha ) {
			return max( 0, min( 1, $number ) );
		}
		return max( 0, min( 255, $number ) );
	}

	/**
	 * Convert HSL to RGB (0–255).
	 *
	 * @param float $h Hue 0–360.
	 * @param float $s Saturation 0–100.
	 * @param float $l Lightness 0–100.
	 * @return array{0:int,1:int,2:int}
	 */
	private static function hsl_to_rgb( $h, $s, $l ) {
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
	 * Map a small set of CSS named colours to hex.
	 *
	 * @param string $value Colour name.
	 * @return string|null
	 */
	private static function named_color_to_hex( $value ) {
		$map = array(
			'white'       => '#ffffff',
			'black'       => '#000000',
			'red'         => '#ff0000',
			'green'       => '#008000',
			'blue'        => '#0000ff',
			'yellow'      => '#ffff00',
			'orange'      => '#ffa500',
			'purple'      => '#800080',
			'gray'        => '#808080',
			'grey'        => '#808080',
			'silver'      => '#c0c0c0',
			'maroon'      => '#800000',
			'navy'        => '#000080',
			'teal'        => '#008080',
			'olive'       => '#808000',
			'lime'        => '#00ff00',
			'aqua'        => '#00ffff',
			'cyan'        => '#00ffff',
			'fuchsia'     => '#ff00ff',
			'magenta'     => '#ff00ff',
			'transparent' => '#00000000',
		);

		$key = strtolower( trim( (string) $value ) );
		return isset( $map[ $key ] ) ? $map[ $key ] : null;
	}
}
