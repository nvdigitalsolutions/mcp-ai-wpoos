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
 * F&B toolkit settings wrapper.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Settings {

	const OPTION_KEY = 'wp_mcp_ai_fnb_settings';

	/**
	 * Default settings.
	 *
	 * @var array
	 */
	private static $defaults = array(
		'enabled'          => false,
		'demo_mode'        => true,
		'adapter'          => 'drive', // drive | csv.
		'data_folder_id'   => '',
		'setup_folder_id'  => '',
		'drafts_folder_id' => '',
		'csv_data_dir'     => '',
		'connection_id'    => '',
		'as_of_date'       => '',
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
		return (bool) self::get( 'demo_mode', true );
	}
}
