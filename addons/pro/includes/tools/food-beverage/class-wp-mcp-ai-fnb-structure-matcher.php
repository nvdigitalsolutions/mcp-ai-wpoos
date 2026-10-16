<?php
/**
 * Food & Beverage Management Toolkit — Structure Matcher.
 *
 * Header-driven table inference: resolves *what a file is* from its normalised
 * headers (matched against each table's canonical columns) rather than from its
 * filename. This is the "auto-map when no mapping is provided" behaviour and it
 * demotes the legacy `$sheet_map` name guess to a last-resort fallback.
 *
 * Confidence is `matched / required`, where `required` is a per-table subset of
 * the canonical columns (the idempotency + natural keys an operator must supply
 * for a row to be meaningful), not every optional column — so sparse owner files
 * still map.
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
 * Structural (header-based) table matcher for F&B ingestion.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Structure_Matcher {

	const DEFAULT_THRESHOLD = 0.60;

	/**
	 * Per-table required columns (the subset of canonical columns that must be
	 * present for a confident match). Keyed by table ID; tables with no entry
	 * fall back to the full canonical list.
	 *
	 * @var array<string,array<int,string>>
	 */
	private static $required = array(
		'6.0'  => array( 'date', 'item_id', 'qty_sold', 'revenue_lkr' ),
		'8.0'  => array( 'delivery_date', 'supplier_id', 'invoice', 'ingredient_id', 'qty_delivered' ),
		'9.0'  => array( 'count_date', 'ingredient_id', 'opening', 'closing' ),
		'10.0' => array( 'date', 'ingredient_id', 'qty', 'reason' ),
		'16.0' => array( 'booking_id', 'date', 'guests' ),
		'1.0'  => array( 'item_id', 'item', 'price_lkr' ),
		'2.0'  => array( 'item_id', 'ingredient_id' ),
		'3.0'  => array( 'ingredient_id', 'ingredient', 'unit' ),
		'4.0'  => array( 'supplier_id', 'supplier', 'lead_time', 'order_cutoff' ),
		'5.0'  => array( 'supplier_id', 'ingredient_id', 'pack_price_lkr' ),
		'7.0'  => array( 'date', 'total_covers' ),
		'15.0' => array( 'month', 'line', 'budget_lkr' ),
	);

	/**
	 * Return the required canonical columns for a table ID.
	 *
	 * @param string $table_id Spec table ID.
	 * @return array<int,string>
	 */
	public static function required_columns( $table_id ) {
		if ( isset( self::$required[ $table_id ] ) ) {
			return self::$required[ $table_id ];
		}

		$definition = WP_MCP_AI_Fnb_Data_Source::table_definition( $table_id );
		$canonical  = is_array( $definition ) && ! empty( $definition['canonical'] ) ? $definition['canonical'] : array();

		/**
		 * Filter the required columns for a table ID (used for confidence scoring).
		 *
		 * @param array  $required Required canonical columns.
		 * @param string $table_id Spec table ID.
		 */
		return apply_filters( 'wp_mcp_ai_fnb_required_columns', array_values( $canonical ), $table_id );
	}

	/**
	 * Score a header set against a single table ID.
	 *
	 * @param string $table_id Spec table ID.
	 * @param array  $headers  Raw (unnormalised) header strings.
	 * @return array{array{confidence:float,matched:array<int,string>,missing:array<int,string>,required:array<int,string>}|false}
	 */
	public static function match_table( $table_id, array $headers ) {
		$definition = WP_MCP_AI_Fnb_Data_Source::table_definition( $table_id );
		if ( null === $definition ) {
			return false;
		}

		$canonical  = isset( $definition['canonical'] ) ? $definition['canonical'] : array();
		$required   = self::required_columns( $table_id );
		$normalised = array_map( array( 'WP_MCP_AI_Fnb_Data_Source', 'normalise_header' ), $headers );

		$matched = array();
		$missing = array();
		foreach ( $required as $key ) {
			$index = WP_MCP_AI_Fnb_Data_Source::resolve_column( $table_id, $normalised, $key );
			if ( false !== $index ) {
				$matched[] = $key;
			} else {
				$missing[] = $key;
			}
		}

		$confidence = count( $required ) > 0 ? (float) ( count( $matched ) / count( $required ) ) : 0.0;

		return array(
			'confidence' => $confidence,
			'matched'    => $matched,
			'missing'    => $missing,
			'required'   => $required,
			'canonical'  => $canonical,
		);
	}

	/**
	 * Find the best-matching table ID for a header set at or above a threshold.
	 *
	 * @param array $headers   Raw header strings.
	 * @param float $threshold Minimum confidence (0..1).
	 * @return array{table_id:string,score:array}|false Best table id + score, or false.
	 */
	public static function best_table( array $headers, $threshold = self::DEFAULT_THRESHOLD ) {
		$best     = false;
		$best_val = 0.0;

		foreach ( WP_MCP_AI_Fnb_Data_Source::list_tables() as $entry ) {
			$score = self::match_table( $entry['id'], $headers );
			if ( false === $score ) {
				continue;
			}
			if ( $score['confidence'] >= $best_val ) {
				$best_val = $score['confidence'];
				$best     = array(
					'table_id' => $entry['id'],
					'score'    => $score,
				);
			}
		}

		if ( false === $best || $best_val < $threshold ) {
			return false;
		}

		return $best;
	}
}
