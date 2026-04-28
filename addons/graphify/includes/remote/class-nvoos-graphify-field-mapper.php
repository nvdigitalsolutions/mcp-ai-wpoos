<?php
/**
 * NV oOS Graphify — Field Mapper
 *
 * Resolves dotted JSON-path-style field maps from raw remote records to
 * graph-node array shapes. Used by the generic REST driver, the CSV
 * driver, and the webhook receiver to translate vendor-specific payloads
 * into the canonical { node_id, label, type, url, properties } shape that
 * NV_oOS_Graphify_DB::upsert_node() expects.
 *
 * Supported path syntax (intentionally minimal):
 *   "name"         — top-level key
 *   "user.email"   — nested key
 *   "items.0.id"   — numeric array index
 *
 * Wildcards and filter expressions are intentionally NOT supported — those
 * belong in a dedicated parser; this class is the safe minimum.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field mapper for vendor-record → graph-node translation.
 *
 * @since 0.7.0
 */
class NV_oOS_Graphify_Field_Mapper {

	/**
	 * Resolve a single dotted path against a record.
	 *
	 * Returns null if any segment is missing.
	 *
	 * @since 0.7.0
	 *
	 * @param array  $record  Decoded record (associative array).
	 * @param string $path    Dotted path.
	 * @param mixed  $default Default value when not found.
	 * @return mixed
	 */
	public static function resolve( $record, $path, $default = null ) {
		if ( ! is_array( $record ) || '' === (string) $path ) {
			return $default;
		}
		$cursor = $record;
		$parts  = explode( '.', (string) $path );
		foreach ( $parts as $segment ) {
			if ( is_array( $cursor ) && array_key_exists( $segment, $cursor ) ) {
				$cursor = $cursor[ $segment ];
				continue;
			}
			// Numeric index against an indexed array.
			if ( is_array( $cursor ) && ctype_digit( $segment ) && array_key_exists( (int) $segment, $cursor ) ) {
				$cursor = $cursor[ (int) $segment ];
				continue;
			}
			return $default;
		}
		return $cursor;
	}

	/**
	 * Apply a field map to a single record and produce a node array suitable
	 * for NV_oOS_Graphify_DB::upsert_node().
	 *
	 * Recognised map keys:
	 *   id, label, url, type, external_id   — strings
	 *   properties                           — array<string,string> of property_name => path
	 *
	 * Required map fields: id, label.
	 *
	 * @since 0.7.0
	 *
	 * @param array  $record      Decoded record.
	 * @param array  $map         Field map.
	 * @param string $source_slug Source slug for node_id namespacing.
	 * @return array|null Node array or null when required fields are missing.
	 */
	public static function map_to_node( array $record, array $map, $source_slug ) {
		$source_slug = sanitize_key( $source_slug );

		$id_path    = isset( $map['id'] ) ? (string) $map['id'] : '';
		$label_path = isset( $map['label'] ) ? (string) $map['label'] : '';
		$id         = $id_path ? self::resolve( $record, $id_path ) : null;
		$label      = $label_path ? self::resolve( $record, $label_path ) : null;

		if ( null === $id || '' === (string) $id || null === $label || '' === (string) $label ) {
			return null;
		}

		$type        = isset( $map['type'] ) ? sanitize_text_field( (string) $map['type'] ) : 'remote_record';
		$url_path    = isset( $map['url'] ) ? (string) $map['url'] : '';
		$ext_path    = isset( $map['external_id'] ) ? (string) $map['external_id'] : $id_path;
		$external_id = $ext_path ? (string) self::resolve( $record, $ext_path, '' ) : (string) $id;
		$url         = $url_path ? esc_url_raw( (string) self::resolve( $record, $url_path, '' ) ) : '';

		$properties = array();
		if ( isset( $map['properties'] ) && is_array( $map['properties'] ) ) {
			foreach ( $map['properties'] as $prop_name => $prop_path ) {
				$prop_name = sanitize_key( $prop_name );
				if ( '' === $prop_name ) {
					continue;
				}
				$value = self::resolve( $record, (string) $prop_path );
				if ( null !== $value ) {
					$properties[ $prop_name ] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
				}
			}
		}

		$node_id_seed = $external_id ? $external_id : $id;
		$node_id      = 'remote_' . $source_slug . '_' . md5( (string) $node_id_seed );

		return array(
			'node_id'     => $node_id,
			'label'       => sanitize_text_field( (string) $label ),
			'type'        => $type,
			'post_id'     => 0,
			'url'         => $url,
			'properties'  => $properties,
			'external_id' => sanitize_text_field( (string) $external_id ),
			'source_slug' => $source_slug,
			'confidence'  => 1.0,
		);
	}

	/**
	 * Apply a field map across an array of records.
	 *
	 * Records that fail validation (missing required fields) are silently
	 * skipped — return value contains only successful nodes.
	 *
	 * @since 0.7.0
	 *
	 * @param array  $records     Array of decoded records.
	 * @param array  $map         Field map.
	 * @param string $source_slug Source slug.
	 * @return array Array of node arrays.
	 */
	public static function map_collection( array $records, array $map, $source_slug ) {
		$nodes = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$node = self::map_to_node( $record, $map, $source_slug );
			if ( null !== $node ) {
				$nodes[] = $node;
			}
		}
		return $nodes;
	}
}
