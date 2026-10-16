<?php
/**
 * Food & Beverage Management Toolkit — Observation Mode.
 *
 * Binds loose, heterogeneous "observation" records — the F&B analogue of the
 * Health Records FHIR `Observation` / `vitals_log` row (a `type`, a value, a
 * unit, a timestamp, a source and a raw form) — onto the toolkit's canonical
 * transactional tables via a terminology map. This lets an owner hand over
 * semi-structured inputs (POS exports, photographed waste slips, supplier
 * delivery lines) and have the toolkit normalise them without a bespoke
 * wide-column layout per source.
 *
 * The terminology map is `measurement_type → table_id`; `bind()` then maps the
 * observation's fields onto that table's canonical columns. `raw_value` is
 * always preserved so a bad unit/price transform stays reversible (mirrors the
 * vitals `raw_value`/`value` split).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-data-source.php';

/**
 * Observation → canonical binding for F&B transactional tables.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Observation {

	/**
	 * Terminology map: observation `type` (measurement_type) → spec table ID.
	 *
	 * Keys are normalised (lowercase, `_`/`-`→space) at lookup time.
	 *
	 * @var array<string,string>
	 */
	private static $terminology = array(
		'sale'        => '6.0',
		'daily sale'  => '6.0',
		'purchase'    => '8.0',
		'delivery'    => '8.0',
		'stock count' => '9.0',
		'stock'       => '9.0',
		'waste'       => '10.0',
		'waste log'   => '10.0',
		'booking'     => '16.0',
		'reservation' => '16.0',
	);

	/**
	 * Normalise an observation `type` for terminology lookup.
	 *
	 * @param string $type Raw type string.
	 * @return string
	 */
	private static function normalise_type( $type ) {
		$value = strtolower( trim( (string) $type ) );
		$value = str_replace( array( '_', '-' ), ' ', $value );

		return preg_replace( '/\s+/', ' ', $value );
	}

	/**
	 * Resolve the target table ID for an observation type.
	 *
	 * @param string $type Observation type.
	 * @return string|false Table ID or false when unknown.
	 */
	public static function table_for_type( $type ) {
		$normalised = self::normalise_type( $type );

		return isset( self::$terminology[ $normalised ] ) ? self::$terminology[ $normalised ] : false;
	}

	/**
	 * Bind a single observation onto its target table's canonical columns.
	 *
	 * Accepts the loose observation shape plus optional canonical overrides; the
	 * `raw_value` (and the whole input when `raw_observation` is set) is always
	 * preserved on the returned row under `_raw_observation`.
	 *
	 * @param array $observation Observation record (type, item, qty, unit, recorded_at, source, raw_value, …).
	 * @param int   $index       Zero-based index (for error messages).
	 * @return array{table_id:string,row:array}|WP_Error
	 */
	public static function bind( array $observation, $index = 0 ) {
		$type = isset( $observation['type'] ) ? (string) $observation['type'] : '';
		if ( '' === $type ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_obs_missing_type',
				/* translators: %d: observation index */
				sprintf( __( 'Observation %d is missing a "type".', 'mcp-ai-wpoos-pro' ), $index + 1 )
			);
		}

		$table_id = self::table_for_type( $type );
		if ( false === $table_id ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_obs_unknown_type',
				/* translators: 1: observation index, 2: type */
				sprintf( __( 'Observation %1$d has an unrecognised type "%2$s".', 'mcp-ai-wpoos-pro' ), $index + 1, $type )
			);
		}

		$row = self::map_to_canonical( $table_id, $observation );

		// Always preserve the raw observation for audit/reversibility.
		$row['_raw_observation'] = isset( $observation['raw_value'] )
			? (string) $observation['raw_value']
			: wp_json_encode( $observation );

		return array(
			'table_id' => $table_id,
			'row'      => $row,
		);
	}

	/**
	 * Map an observation's loose fields onto a table's canonical columns.
	 *
	 * @param string $table_id    Target table ID.
	 * @param array  $observation Observation record.
	 * @return array Canonical-keyed row (missing canonical keys omitted).
	 */
	private static function map_to_canonical( $table_id, array $observation ) {
		$definition = WP_MCP_AI_Fnb_Data_Source::table_definition( $table_id );
		$canonical  = is_array( $definition ) && ! empty( $definition['canonical'] ) ? $definition['canonical'] : array();
		$row        = array();

		foreach ( $canonical as $key ) {
			// Direct hit: the observation already carries the canonical key.
			if ( array_key_exists( $key, $observation ) ) {
				$row[ $key ] = (string) $observation[ $key ];
				continue;
			}

			// Alias hit: map the common loose aliases onto the canonical key.
			$mapped = self::map_alias( $key, $observation );
			if ( null !== $mapped ) {
				$row[ $key ] = $mapped;
			}
		}

		return $row;
	}

	/**
	 * Resolve a canonical key from the loose observation aliases.
	 *
	 * @param string $key         Canonical key.
	 * @param array  $observation Observation record.
	 * @return string|null Mapped value or null.
	 */
	private static function map_alias( $key, array $observation ) {
		$aliases = array(
			'date'          => array( 'date', 'recorded_at', 'count_date', 'delivery_date' ),
			'delivery_date' => array( 'delivery_date', 'recorded_at', 'date' ),
			'count_date'    => array( 'count_date', 'recorded_at', 'date' ),
			'item_id'       => array( 'item_id', 'item', 'ingredient_id' ),
			'ingredient_id' => array( 'ingredient_id', 'item_id', 'item' ),
			'item'          => array( 'item', 'ingredient', 'name' ),
			'ingredient'    => array( 'ingredient', 'item', 'name' ),
			'qty_sold'      => array( 'qty_sold', 'qty' ),
			'qty_delivered' => array( 'qty_delivered', 'qty' ),
			'qty'           => array( 'qty', 'quantity' ),
			'unit'          => array( 'unit', 'uom' ),
			'reason'        => array( 'reason', 'waste_reason' ),
			'guests'        => array( 'guests', 'party_size', 'qty' ),
			'status'        => array( 'status', 'booking_status' ),
			'source'        => array( 'source', 'origin' ),
		);

		if ( ! isset( $aliases[ $key ] ) ) {
			return null;
		}

		foreach ( $aliases[ $key ] as $alias ) {
			if ( array_key_exists( $alias, $observation ) && '' !== (string) $observation[ $alias ] ) {
				return (string) $observation[ $alias ];
			}
		}

		return null;
	}

	/**
	 * Bind a set of observations and group them under their resolved table IDs.
	 *
	 * @param array<int,array> $observations Observation records.
	 * @return array{table_id => array<int,array> rows, errors:array<int,string>}
	 */
	public static function map_records( array $observations ) {
		$grouped = array();
		$errors  = array();

		foreach ( $observations as $index => $observation ) {
			if ( ! is_array( $observation ) ) {
				$errors[] = sprintf( __( 'Observation %d is not an object — skipped.', 'mcp-ai-wpoos-pro' ), $index + 1 );
				continue;
			}

			$bound = self::bind( $observation, $index );
			if ( is_wp_error( $bound ) ) {
				$errors[] = $bound->get_error_message();
				continue;
			}

			$grouped[ $bound['table_id'] ][] = $bound['row'];
		}

		return array(
			'grouped' => $grouped,
			'errors'  => $errors,
		);
	}
}
