<?php
/**
 * Food & Beverage Management Toolkit — Settings.
 *
 * Central configuration for the F&B toolkit: Drive folder IDs, adapter
 * selection, demo mode, and assumption constants (G-11 disclosure).
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

	/**
	 * Seeds the F&B assistant packs (A1–A4).
	 *
	 * @since 1.6.0
	 */
class WP_MCP_AI_Fnb_Settings {

	const OPTION_KEY = 'wp_mcp_ai_fnb_settings';

	/**
	 * Google Drive file ID of the canonical Surf Club Midigama demo workbook.
	 *
	 * This is the single shared spreadsheet whose worksheets/tabs hold all 17
	 * spec data tables plus the "assumptions" sheet. Kept only for the explicit
	 * "Load demo data" action — it is never a silent fallback (demo_mode is off
	 * by default, and workbook_id() returns empty when data_workbook_id is unset).
	 *
	 * NOTE: This is the Sheets file ID, not the Drive folder ID
	 * (1HNq3xBZtbzOboxbkrVcB7lTc-JUOI5On).
	 *
	 * @var string
	 */
	const DEMO_WORKBOOK_ID = '1L6YzAq08EnR-Hvu9Wne_dULNDBuBhBwplrv8KdIlWlE';

	/**
	 * Default settings.
	 *
	 * @var array
	 */
	private static $defaults = array(
		'enabled'                    => false,
		'demo_mode'                  => false,
		'adapter'                    => 'drive', // drive | csv.
		'ingest_mode'                => 'folder', // folder | workbook | split. folder = single-folder universal ingest.
		'data_folder_id'             => '',
		'setup_folder_id'            => '',
		'data_workbook_id'           => '', // Optional: single shared workbook (multi-worksheet) Drive file ID.
		'data_workbook_name'         => '', // Optional: filter workbook candidates by (fuzzy) file name.
		'drafts_folder_id'           => '',
		'csv_data_dir'               => '',
		'connection_id'              => '',
		'as_of_date'                 => '',
		'venue_name'                 => '', // Tenant Profile: venue display name.
		'venue_type'                 => '', // Tenant Profile: restaurant|bar|cafe|club|hotel|ghost-kitchen.
		'currency'                   => '', // Tenant Profile: ISO-4217 code (empty = unset).
		'locale'                     => '', // Tenant Profile: BCP-47 locale.
		'owner_role'                 => '', // Tenant Profile: owner/decision-maker role.
		'enable_fnb_native_storage'  => false, // Phase 9: CPT-backed native storage for the five transactional tables.
		'pr_auto_approval_threshold' => 0,     // Phase 9b: PR totals above this need a finance co-sign at release (0 = never).
	);

	/**
	 * Get all settings merged over defaults.
	 *
	 * @return array
	 */
	public static function get_all() {
		$saved = get_option( self::OPTION_KEY, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::$defaults );
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::get_all();

		return isset( $all[ $key ] ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Whether the toolkit is enabled.
	 *
	 * Mirrors the registry gate in the Pro addon (`enable_fnb_toolkit`).
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		return ! empty( $settings['enable_fnb_toolkit'] );
	}

	/**
	 * Whether demo mode is active.
	 *
	 * @return bool
	 */
	public static function is_demo_mode() {
		return (bool) self::get( 'demo_mode', false );
	}

	/**
	 * Resolve the Google Drive file ID of the source workbook.
	 *
	 * Returns the explicitly configured `data_workbook_id` when set; otherwise
	 * returns an empty string so the read path falls through to folder lookup.
	 * The canonical demo workbook is available only through the explicit
	 * "Load demo data" action — it is never a silent fallback.
	 *
	 * @return string Drive file ID (empty when none is configured).
	 */
	public static function workbook_id() {
		$explicit = (string) self::get( 'data_workbook_id', '' );
		return trim( $explicit );
	}

	/**
	 * Whether the Phase 9 native storage layer (CPTs + import bridge) is on.
	 *
	 * When enabled, the five transactional tables (6.0 daily sales, 8.0
	 * purchases, 9.0 stock counts, 10.0 waste log, 16.0 bookings) resolve
	 * from their CPTs instead of the Drive/CSV adapter; the reference tables
	 * keep their existing backend.
	 *
	 * @return bool
	 */
	public static function is_native_storage_enabled() {
		return (bool) self::get( 'enable_fnb_native_storage', false );
	}
}
