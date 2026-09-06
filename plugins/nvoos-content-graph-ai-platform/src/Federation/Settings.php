<?php
/**
 * Federation settings helper.
 *
 * Reads the federation settings from the same grouped option the base plugin
 * uses (`wp_mcp_ai_settings`) so option keys stay byte-identical across both
 * implementations (data stability — extraction plan §3). The admin settings
 * UI remains in the base plugin during the transition; the Platform addon's
 * FederationAdmin provides its own dashboard surface.
 *
 * @package NvoosContentGraphAiPlatform
 * @since 2.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Federation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Federation settings accessor.
 */
class Settings {

	/**
	 * Grouped settings option key (same as the base plugin).
	 */
	const OPTION_NAME = 'wp_mcp_ai_settings';

	/**
	 * Get federation settings with defaults.
	 *
	 * @return array Federation settings.
	 */
	public static function get_settings() {
		$all_settings = get_option( self::OPTION_NAME, array() );

		$defaults = array(
			'enable_federation'           => false,
			'enable_federation_directory' => false,
			'enable_mesh'                 => false,
			'federation_regions'          => array( 'global' ),
			'federation_data_tags'        => array(),
			'federation_qps'              => 5,
			'federation_burst'            => 10,
			'federation_jwks_keys'        => array(),
			'federation_price_hints'      => array(),
		);

		$federation_settings = array();
		foreach ( $defaults as $key => $default_value ) {
			if ( isset( $all_settings[ $key ] ) ) {
				$federation_settings[ $key ] = $all_settings[ $key ];
			} else {
				$federation_settings[ $key ] = $default_value;
			}
		}

		// Ensure regions is an array.
		if ( is_string( $federation_settings['federation_regions'] ) ) {
			$federation_settings['federation_regions'] = array_map(
				'trim',
				explode( ',', $federation_settings['federation_regions'] )
			);
		}

		// Ensure data_tags is an array.
		if ( is_string( $federation_settings['federation_data_tags'] ) ) {
			$federation_settings['federation_data_tags'] = array_filter(
				array_map(
					'trim',
					explode( ',', $federation_settings['federation_data_tags'] )
				)
			);
		}

		return $federation_settings;
	}

	/**
	 * Check if federation is enabled.
	 *
	 * @return bool True if federation is enabled.
	 */
	public static function is_federation_enabled() {
		$settings = self::get_settings();
		return ! empty( $settings['enable_federation'] );
	}

	/**
	 * Check if directory service is enabled.
	 *
	 * @return bool True if directory service is enabled.
	 */
	public static function is_directory_enabled() {
		$settings = self::get_settings();
		return ! empty( $settings['enable_federation_directory'] );
	}

	/**
	 * Check if mesh computing is enabled.
	 *
	 * @return bool True if mesh computing is enabled.
	 */
	public static function is_mesh_enabled() {
		$settings = self::get_settings();
		return ! empty( $settings['enable_mesh'] );
	}
}
