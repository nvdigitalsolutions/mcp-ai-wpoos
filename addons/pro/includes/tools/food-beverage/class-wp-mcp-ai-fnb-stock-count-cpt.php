<?php
/**
 * Food & Beverage Management Toolkit — Stock Count CPT.
 *
 * Phase 9 native storage for spec table 9.0 (Stock counts): one
 * `mcp_ai_fnb_stock_count` row per count date + count type + ingredient.
 * Registered only when the `enable_fnb_native_storage` toggle is on; rows
 * are upserted idempotently by the import bridge keyed on
 * (count_date, count_type, ingredient_id).
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

require_once __DIR__ . '/class-wp-mcp-ai-fnb-settings.php';
require_once __DIR__ . '/class-wp-mcp-ai-fnb-data-source.php';
require_once __DIR__ . '/trait-wp-mcp-ai-fnb-native-cpt-storage.php';

/**
 * `mcp_ai_fnb_stock_count` CPT registration and row upsert.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Stock_Count_CPT {
	use WP_MCP_AI_Fnb_Native_Cpt_Storage;

	/**
	 * CPT slug (≤20 chars — WordPress post-type key limit).
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_fnb_stock';

	/**
	 * Spec table ID this CPT backs.
	 *
	 * @var string
	 */
	const TABLE_ID = '9.0';

	/**
	 * Hook the CPT registration on `init`.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 12 );
	}

	/**
	 * Register the CPT when native storage is enabled.
	 *
	 * @return bool|WP_Error True when registered, false when the toggle is off, WP_Error on registration failure.
	 */
	public static function register() {
		if ( ! WP_MCP_AI_Fnb_Settings::is_native_storage_enabled() ) {
			return false;
		}

		return self::register_cpt(
			self::POST_TYPE,
			__( 'F&B Stock Count', 'mcp-ai-wpoos-pro' ),
			__( 'F&B Stock Counts', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Canonical columns that make a stock-count row unique.
	 *
	 * @return array<int,string>
	 */
	public static function key_columns() {
		return array( 'count_date', 'count_type', 'ingredient_id' );
	}

	/**
	 * Upsert a canonical stock-count row.
	 *
	 * @param array $row Canonical-keyed row values (count_date, count_type, period_start, ingredient_id, unit, category, opening, bought, closing, used, unit_cost, closing_value).
	 * @return array|WP_Error array{action: 'insert'|'update', post_id: int}
	 */
	public static function upsert( array $row ) {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_native_storage_disabled',
				__( 'F&B native storage is disabled — enable it on the Food & Beverage settings page.', 'mcp-ai-wpoos-pro' )
			);
		}

		$date       = isset( $row['count_date'] ) ? (string) $row['count_date'] : '';
		$type       = isset( $row['count_type'] ) ? (string) $row['count_type'] : '';
		$ingredient = isset( $row['ingredient_id'] ) ? (string) $row['ingredient_id'] : '';
		$title      = trim( sprintf( 'Stock count %s%s%s', $date, '' !== $type ? ' · ' . $type : '', '' !== $ingredient ? ' · ' . $ingredient : '' ) );

		return self::upsert_row(
			self::POST_TYPE,
			self::TABLE_ID,
			$row,
			$title,
			$date,
			self::key_columns(),
			'stock_count'
		);
	}
}
