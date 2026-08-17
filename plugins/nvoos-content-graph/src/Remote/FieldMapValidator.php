<?php
declare(strict_types=1);

namespace NvoosContentGraph\Remote;

use function __;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_last_error;
use function json_last_error_msg;
use function range;
use function sprintf;
use function trim;

/**
 * Field-Map Validator — validates JSON field-map strings (as used by the
 * CSV / generic REST / webhook receiver drivers) before they are saved.
 *
 * Gives admins fast, structured feedback on typos and missing keys.
 *
 * Validation rules (deliberately minimal):
 *   - Input must be valid JSON decoding to an associative array.
 *   - Top-level keys may include: id, label, url, type, properties (object).
 *   - At least one of `id` or `label` must be present.
 *   - Top-level scalars (id, label, url, type) must be non-empty strings.
 *   - `properties` must be an object whose values are non-empty strings.
 *
 * @since 1.0.2
 */
final class FieldMapValidator {

	/**
	 * Recognised top-level scalar keys.
	 *
	 * @var string[]
	 */
	private static $top_level_scalars = array( 'id', 'label', 'url', 'type' );

	/**
	 * Validate a raw JSON field-map string.
	 *
	 * @since 1.0.2
	 *
	 * @param string $json Raw JSON.
	 * @return array{
	 *     valid: bool,
	 *     map: array<string,mixed>,
	 *     errors: string[],
	 *     warnings: string[],
	 *     fields: string[],
	 * }
	 */
	public static function validate( $json ): array {
		$json    = (string) $json;
		$result  = array(
			'valid'    => false,
			'map'      => array(),
			'errors'   => array(),
			'warnings' => array(),
			'fields'   => array(),
		);
		$trimmed = trim( $json );
		if ( '' === $trimmed ) {
			$result['errors'][] = __( 'Field map is empty.', 'nvoos-content-graph' );
			return $result;
		}

		$decoded = json_decode( $trimmed, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			$result['errors'][] = sprintf(
				/* translators: %s json_last_error_msg() */
				__( 'Invalid JSON: %s', 'nvoos-content-graph' ),
				json_last_error_msg()
			);
			return $result;
		}
		if ( ! is_array( $decoded ) || self::is_list( $decoded ) ) {
			$result['errors'][] = __( 'Field map must be a JSON object.', 'nvoos-content-graph' );
			return $result;
		}

		$result['map'] = $decoded;

		// id / label requirement.
		$has_id    = ! empty( $decoded['id'] );
		$has_label = ! empty( $decoded['label'] );
		if ( ! $has_id && ! $has_label ) {
			$result['errors'][] = __( 'Field map must include at least an "id" or a "label" path.', 'nvoos-content-graph' );
		}

		// Top-level scalar shapes.
		foreach ( self::$top_level_scalars as $key ) {
			if ( ! isset( $decoded[ $key ] ) ) {
				continue;
			}
			$value = $decoded[ $key ];
			if ( ! is_string( $value ) ) {
				$result['errors'][] = sprintf(
					/* translators: %s top-level field key */
					__( 'Field map "%s" must be a string path.', 'nvoos-content-graph' ),
					$key
				);
				continue;
			}
			if ( '' === trim( $value ) ) {
				$result['errors'][] = sprintf(
					/* translators: %s top-level field key */
					__( 'Field map "%s" is empty.', 'nvoos-content-graph' ),
					$key
				);
				continue;
			}
			$result['fields'][] = $value;
		}

		// Properties shape.
		if ( isset( $decoded['properties'] ) ) {
			$props = $decoded['properties'];
			if ( ! is_array( $props ) || self::is_list( $props ) ) {
				$result['errors'][] = __( 'Field map "properties" must be a JSON object.', 'nvoos-content-graph' );
			} else {
				foreach ( $props as $prop_name => $prop_path ) {
					if ( ! is_string( $prop_name ) || '' === trim( $prop_name ) ) {
						$result['errors'][] = __( 'Property keys must be non-empty strings.', 'nvoos-content-graph' );
						continue;
					}
					if ( ! is_string( $prop_path ) || '' === trim( $prop_path ) ) {
						$result['errors'][] = sprintf(
							/* translators: %s property name */
							__( 'Property "%s" must map to a non-empty string path.', 'nvoos-content-graph' ),
							$prop_name
						);
						continue;
					}
					$result['fields'][] = $prop_path;
				}
			}
		}

		// Unknown top-level keys → warnings.
		$known = array_merge( self::$top_level_scalars, array( 'properties' ) );
		foreach ( array_keys( $decoded ) as $key ) {
			if ( ! in_array( $key, $known, true ) ) {
				$result['warnings'][] = sprintf(
					/* translators: %s top-level field key */
					__( 'Unknown top-level key "%s" will be ignored.', 'nvoos-content-graph' ),
					$key
				);
			}
		}

		// Dedupe field list while preserving order.
		$result['fields'] = array_values( array_unique( $result['fields'] ) );
		$result['valid']  = empty( $result['errors'] );
		return $result;
	}

	/**
	 * Validate against a sample record: every referenced dotted path must
	 * resolve to a non-null value in the sample. Useful for live preview.
	 *
	 * @since 1.0.2
	 *
	 * @param string              $json   Raw JSON map.
	 * @param array<string,mixed> $sample One sample record.
	 * @return array<string,mixed> Same shape as validate(), with extra `unresolved` key.
	 */
	public static function validate_against_sample( $json, array $sample ): array {
		$result               = self::validate( $json );
		$result['unresolved'] = array();
		if ( ! $result['valid'] ) {
			return $result;
		}
		foreach ( $result['fields'] as $path ) {
			if ( null === self::resolvePath( $sample, $path ) ) {
				$result['unresolved'][] = $path;
			}
		}
		if ( ! empty( $result['unresolved'] ) ) {
			$result['warnings'][] = sprintf(
				/* translators: %s comma-separated field paths */
				__( 'These paths did not resolve in the sample record: %s', 'nvoos-content-graph' ),
				implode( ', ', $result['unresolved'] )
			);
		}
		return $result;
	}

	/**
	 * Resolve a dotted path (e.g. "author.name") inside a nested array.
	 *
	 * @since 1.0.2
	 *
	 * @param array<string,mixed> $record Record to search.
	 * @param string              $path   Dotted path.
	 * @return mixed|null Value at path, or null when unresolvable.
	 */
	private static function resolvePath( array $record, string $path ) {
		$segments = explode( '.', $path );
		$current  = $record;
		foreach ( $segments as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}
			$current = $current[ $segment ];
		}
		return null === $current ? null : $current;
	}

	/**
	 * True when an array has sequential numeric keys (i.e. it is a list,
	 * not an associative object).
	 *
	 * @since 1.0.2
	 *
	 * @param array<mixed> $arr Array to inspect.
	 * @return bool
	 */
	private static function is_list( array $arr ): bool {
		if ( array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}
}
