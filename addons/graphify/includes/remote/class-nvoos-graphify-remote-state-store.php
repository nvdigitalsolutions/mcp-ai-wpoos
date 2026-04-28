<?php
/**
 * NV oOS Graphify — Per-Source State Store
 *
 * Lightweight key/value store for per-source sync watermarks (last cursor,
 * last_updated_at, last sync token, error counters). Backed by the existing
 * graph_meta table via NV_oOS_Graphify_DB::get_meta() / ::set_meta(),
 * so no new schema migration is required.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-source state storage helper.
 *
 * @since 0.7.0
 */
class NV_oOS_Graphify_Remote_State_Store {

	/**
	 * Meta-key prefix for per-source state rows.
	 *
	 * @var string
	 */
	const META_PREFIX = 'remote_state_';

	/**
	 * Build the meta key for a given source slug.
	 *
	 * @param string $slug Source slug.
	 * @return string
	 */
	private static function key( $slug ) {
		return self::META_PREFIX . sanitize_key( $slug );
	}

	/**
	 * Return the full state array for a source.
	 *
	 * @since 0.7.0
	 *
	 * @param string $slug Source slug.
	 * @return array Associative state array; empty array when no state exists.
	 */
	public static function get_state( $slug ) {
		if ( ! class_exists( 'NV_oOS_Graphify_DB' ) ) {
			return array();
		}
		$value = NV_oOS_Graphify_DB::get_meta( self::key( $slug ), array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Read a single field from the state for a source.
	 *
	 * @since 0.7.0
	 *
	 * @param string $slug    Source slug.
	 * @param string $field   Field name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get( $slug, $field, $default = null ) {
		$state = self::get_state( $slug );
		$field = sanitize_key( $field );
		return array_key_exists( $field, $state ) ? $state[ $field ] : $default;
	}

	/**
	 * Set a single field in the state for a source.
	 *
	 * @since 0.7.0
	 *
	 * @param string $slug  Source slug.
	 * @param string $field Field name (sanitised).
	 * @param mixed  $value Value (must be JSON-serialisable).
	 * @return void
	 */
	public static function set( $slug, $field, $value ) {
		if ( ! class_exists( 'NV_oOS_Graphify_DB' ) ) {
			return;
		}
		$state                           = self::get_state( $slug );
		$state[ sanitize_key( $field ) ] = $value;
		NV_oOS_Graphify_DB::set_meta( self::key( $slug ), $state );
	}

	/**
	 * Replace the entire state array for a source.
	 *
	 * @since 0.7.0
	 *
	 * @param string $slug  Source slug.
	 * @param array  $state Full state array.
	 * @return void
	 */
	public static function replace( $slug, array $state ) {
		if ( ! class_exists( 'NV_oOS_Graphify_DB' ) ) {
			return;
		}
		NV_oOS_Graphify_DB::set_meta( self::key( $slug ), $state );
	}

	/**
	 * Clear all state for a source.
	 *
	 * @since 0.7.0
	 *
	 * @param string $slug Source slug.
	 * @return void
	 */
	public static function clear( $slug ) {
		if ( ! class_exists( 'NV_oOS_Graphify_DB' ) ) {
			return;
		}
		NV_oOS_Graphify_DB::set_meta( self::key( $slug ), array() );
	}

	/**
	 * Convenience: record a successful sync timestamp + optional cursor.
	 *
	 * @since 0.7.0
	 *
	 * @param string      $slug   Source slug.
	 * @param string|null $cursor Optional cursor / watermark value.
	 * @return void
	 */
	public static function mark_synced( $slug, $cursor = null ) {
		$state                 = self::get_state( $slug );
		$state['last_sync_at'] = gmdate( 'c' );
		if ( null !== $cursor ) {
			$state['last_cursor'] = (string) $cursor;
		}
		self::replace( $slug, $state );
	}
}
