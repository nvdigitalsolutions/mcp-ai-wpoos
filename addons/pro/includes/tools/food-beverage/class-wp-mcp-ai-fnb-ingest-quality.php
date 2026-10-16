<?php
/**
 * Food & Beverage Management Toolkit — Ingest Quality.
 *
 * Deduplication and out-of-band flagging for the native-storage import bridge,
 * mirroring the Health Records vitals patterns (`is_near_duplicate` and the
 * `assess_*`/`generate_alerts` banding) but bound to F&B domain rules instead of
 * clinical thresholds. Everything is read-only: the import bridge surfaces the
 * results in its dry-run preview and lets the (manage_options) write step decide.
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
 * Ingest-quality helpers for F&B native storage (dedupe + out-of-band flags).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Ingest_Quality {

	/**
	 * Whether an incoming canonical row is a near-duplicate of an existing one,
	 * keyed on the table's idempotency columns with a small numeric tolerance.
	 *
	 * A row counts as near-duplicate only when every non-empty key column is
	 * within tolerance AND the whole row's key fields are byte-identical in
	 * spirit (empty-on-existing-but-present-incoming is NOT a duplicate, so a
	 * re-import that fills in previously-missing keys still writes).
	 *
	 * @param string $table_id   Spec table ID.
	 * @param array  $existing   Existing canonical row (flat, keyed by canonical names).
	 * @param array  $incoming   Incoming canonical row (flat, keyed by canonical names).
	 * @param array  $key_columns Idempotency columns (default: empty → compare all).
	 * @return bool
	 */
	public static function is_near_duplicate( $table_id, array $existing, array $incoming, array $key_columns = array() ) {
		if ( empty( $key_columns ) ) {
			$definition  = WP_MCP_AI_Fnb_Data_Source::table_definition( $table_id );
			$key_columns = is_array( $definition ) && ! empty( $definition['canonical'] ) ? $definition['canonical'] : array();
		}

		foreach ( $key_columns as $key ) {
			$existing_val = isset( $existing[ $key ] ) ? $existing[ $key ] : null;
			$incoming_val = isset( $incoming[ $key ] ) ? $incoming[ $key ] : null;

			if ( null === $existing_val || '' === (string) $existing_val ) {
				// Existing row is missing this key — incoming adds data, not a dup.
				return false;
			}
			if ( null === $incoming_val || '' === (string) $incoming_val ) {
				// Incoming is missing a key the existing row has — not a clean dup.
				return false;
			}

			// Numeric fields compare with a small tolerance; everything else exact.
			if ( is_numeric( $existing_val ) && is_numeric( $incoming_val ) ) {
				if ( abs( (float) $existing_val - (float) $incoming_val ) > 0.001 ) {
					return false;
				}
			} elseif ( (string) $existing_val !== (string) $incoming_val ) {
				return false;
			}
		}

		return count( $key_columns ) > 0;
	}

	/**
	 * Flag a single row for out-of-band values (never throws; returns list of
	 * human-readable flags keyed by a stable slug).
	 *
	 * @param string $table_id Spec table ID.
	 * @param array  $row      Canonical-keyed row.
	 * @return array<int,array{slug:string,severity:string,message:string}>
	 */
	public static function assess_row( $table_id, array $row ) {
		$flags = array();

		// Negative quantities are almost always a data-entry error.
		foreach ( array( 'qty_sold', 'qty_delivered', 'guests' ) as $qty_key ) {
			if ( isset( $row[ $qty_key ] ) && '' !== (string) $row[ $qty_key ] && (float) $row[ $qty_key ] < 0 ) {
				$flags[] = array(
					'slug'     => 'negative_' . $qty_key,
					'severity' => 'high',
					/* translators: %s: column name */
					'message'  => sprintf( __( 'Negative %s value detected — likely a data-entry error.', 'mcp-ai-wpoos-pro' ), str_replace( '_', ' ', $qty_key ) ),
				);
			}
		}

		// A sold/delivered line with a zero (or missing) price skews cost metrics.
		foreach ( array( 'price_lkr', 'pack_price_lkr', 'line_total_lkr' ) as $price_key ) {
			if ( isset( $row[ $price_key ] ) && '' !== (string) $row[ $price_key ] && 0.0 === (float) $row[ $price_key ] ) {
				$has_qty = false;
				foreach ( array( 'qty_sold', 'qty_delivered' ) as $qk ) {
					if ( isset( $row[ $qk ] ) && '' !== (string) $row[ $qk ] && (float) $row[ $qk ] > 0 ) {
						$has_qty = true;
						break;
					}
				}
				if ( $has_qty ) {
					$flags[] = array(
						'slug'     => 'zero_' . $price_key,
						'severity' => 'medium',
						/* translators: %s: column name */
						'message'  => sprintf( __( 'Zero %s on a line that has quantity — cost metrics will be skewed.', 'mcp-ai-wpoos-pro' ), str_replace( '_', ' ', $price_key ) ),
					);
				}
			}
		}

		return $flags;
	}

	/**
	 * Aggregate out-of-band flags across a set of rows for the dry-run preview.
	 *
	 * @param string           $table_id Spec table ID.
	 * @param array<int,array> $rows     Canonical-keyed rows.
	 * @return array{count:int,flags:array<int,array>}
	 */
	public static function generate_flags( $table_id, array $rows ) {
		$all = array();
		foreach ( $rows as $row ) {
			$all = array_merge( $all, self::assess_row( $table_id, $row ) );
		}

		return array(
			'count' => count( $all ),
			'flags' => array_slice( $all, 0, 20 ),
		);
	}
}
