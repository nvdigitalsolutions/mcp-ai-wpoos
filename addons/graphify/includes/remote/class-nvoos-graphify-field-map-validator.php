<?php
/**
 * NV oOS Graphify — Field-Map Validator
 *
 * Validates JSON field-map strings (as used by the CSV / generic REST /
 * webhook receiver drivers) before they are saved to the database. Gives
 * admins fast, structured feedback on typos and missing keys.
 *
 * Validation rules (deliberately minimal):
 *   - Input must be valid JSON decoded to an associative array.
 *   - Top-level keys may include: id, label, url, type, properties (object).
 *   - At least one of `id` or `label` must be present (otherwise nothing
 *     useful can be ingested).
 *   - Top-level scalars (id, label, url, type) must be strings.
 *   - `properties` must be an object whose values are strings.
 *   - All path strings must be non-empty.
 *
 * Returns a normalised result with the parsed map, the list of fields it
 * references (dotted-path leaves), and an array of `errors` /  `warnings`.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field-map validator for driver `field_map` admin inputs.
 *
 * @since 0.7.9
 */
class NV_oOS_Graphify_Field_Map_Validator {

	/**
	 * Recognised top-level scalar keys.
	 *
	 * @var string[]
	 */
	private static $top_level_scalars = array( 'id', 'label', 'url', 'type' );

	/**
	 * Validate a raw JSON string.
	 *
	 * @param string $json Raw JSON.
	 * @return array {
	 *     @type bool   $valid     True when there are no errors.
	 *     @type array  $map       Parsed map (empty on parse failure).
	 *     @type array  $errors    Human-readable error messages.
	 *     @type array  $warnings  Human-readable warning messages.
	 *     @type array  $fields    Flat list of dotted-path leaves the map references.
	 * }
	 */
	public static function validate( $json ) {
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
			$result['errors'][] = __( 'Field map is empty.', 'nvoos-graphify' );
			return $result;
		}

		$decoded = json_decode( $trimmed, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			$result['errors'][] = sprintf(
				/* translators: %s json_last_error_msg() */
				__( 'Invalid JSON: %s', 'nvoos-graphify' ),
				json_last_error_msg()
			);
			return $result;
		}
		if ( ! is_array( $decoded ) || self::is_list( $decoded ) ) {
			$result['errors'][] = __( 'Field map must be a JSON object.', 'nvoos-graphify' );
			return $result;
		}

		$result['map'] = $decoded;

		// id / label requirement.
		$has_id    = ! empty( $decoded['id'] );
		$has_label = ! empty( $decoded['label'] );
		if ( ! $has_id && ! $has_label ) {
			$result['errors'][] = __( 'Field map must include at least an "id" or a "label" path.', 'nvoos-graphify' );
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
					__( 'Field map "%s" must be a string path.', 'nvoos-graphify' ),
					$key
				);
				continue;
			}
			if ( '' === trim( $value ) ) {
				$result['errors'][] = sprintf(
					/* translators: %s top-level field key */
					__( 'Field map "%s" is empty.', 'nvoos-graphify' ),
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
				$result['errors'][] = __( 'Field map "properties" must be a JSON object.', 'nvoos-graphify' );
			} else {
				foreach ( $props as $prop_name => $prop_path ) {
					if ( ! is_string( $prop_name ) || '' === trim( $prop_name ) ) {
						$result['errors'][] = __( 'Property keys must be non-empty strings.', 'nvoos-graphify' );
						continue;
					}
					if ( ! is_string( $prop_path ) || '' === trim( $prop_path ) ) {
						$result['errors'][] = sprintf(
							/* translators: %s property name */
							__( 'Property "%s" must map to a non-empty string path.', 'nvoos-graphify' ),
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
					__( 'Unknown top-level key "%s" will be ignored.', 'nvoos-graphify' ),
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
	 * @param string $json   Raw JSON map.
	 * @param array  $sample One sample record.
	 * @return array Same shape as validate(), with extra `unresolved` key.
	 */
	public static function validate_against_sample( $json, array $sample ) {
		$result               = self::validate( $json );
		$result['unresolved'] = array();
		if ( ! $result['valid'] ) {
			return $result;
		}
		foreach ( $result['fields'] as $path ) {
			if ( ! class_exists( 'NV_oOS_Graphify_Field_Mapper' ) ) {
				break;
			}
			$value = NV_oOS_Graphify_Field_Mapper::resolve( $sample, $path, null );
			if ( null === $value ) {
				$result['unresolved'][] = $path;
			}
		}
		if ( ! empty( $result['unresolved'] ) ) {
			$result['warnings'][] = sprintf(
				/* translators: %s comma-separated field paths */
				__( 'These paths did not resolve in the sample record: %s', 'nvoos-graphify' ),
				implode( ', ', $result['unresolved'] )
			);
		}
		return $result;
	}

	/**
	 * True when an array has sequential numeric keys (i.e. it's a list,
	 * not an object).
	 *
	 * @param array $arr Array to inspect.
	 * @return bool
	 */
	private static function is_list( array $arr ) {
		if ( array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}
}
