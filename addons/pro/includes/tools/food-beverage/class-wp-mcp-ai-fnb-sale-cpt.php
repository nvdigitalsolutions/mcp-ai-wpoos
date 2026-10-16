<?php
/**
 * Food & Beverage Management Toolkit — Sale CPT.
 *
 * Phase 9 native storage for spec table 6.0 (Daily sales): one
 * `mcp_ai_fnb_sale` row per date + item + type. Registered only when the
 * `enable_fnb_native_storage` toggle is on; rows are upserted idempotently
 * by the import bridge keyed on (date, item_id, type).
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
 * `mcp_ai_fnb_sale` CPT registration and row upsert.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Sale_CPT {
	use WP_MCP_AI_Fnb_Native_Cpt_Storage;

	/**
	 * CPT slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_fnb_sale';

	/**
	 * Spec table ID this CPT backs.
	 *
	 * @var string
	 */
	const TABLE_ID = '6.0';

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
			__( 'F&B Sale', 'mcp-ai-wpoos-pro' ),
			__( 'F&B Sales', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Canonical columns that make a sale row unique.
	 *
	 * @return array<int,string>
	 */
	public static function key_columns() {
		return array( 'date', 'item_id', 'type' );
	}

	/**
	 * Upsert a canonical sale row.
	 *
	 * @param array $row Canonical-keyed row values (date, month, item_id, item, type, qty_sold, price, revenue).
	 * @return array|WP_Error array{action: 'insert'|'update', post_id: int}
	 */
	public static function upsert( array $row ) {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_native_storage_disabled',
				__( 'F&B native storage is disabled — enable it on the Food & Beverage settings page.', 'mcp-ai-wpoos-pro' )
			);
		}

		$date  = isset( $row['date'] ) ? (string) $row['date'] : '';
		$item  = isset( $row['item'] ) ? (string) $row['item'] : '';
		$title = trim( sprintf( 'Sale %s%s', $date, '' !== $item ? ' · ' . $item : '' ) );

		return self::upsert_row(
			self::POST_TYPE,
			self::TABLE_ID,
			$row,
			$title,
			$date,
			self::key_columns(),
			'sale'
		);
	}
}
