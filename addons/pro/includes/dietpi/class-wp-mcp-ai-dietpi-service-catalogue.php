<?php
/**
 * DietPi Service Catalogue
 *
 * Lightweight registry of known DietPi services with their default ports,
 * API types, and DietPi software IDs. Partners can register additional
 * services or override defaults via the `wp_mcp_ai_dietpi_service_catalogue` filter.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage DietPi_Toolkit
 * @since      1.3.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_DietPi_Service_Catalogue' ) ) {

	/**
	 * DietPi service catalogue.
	 *
	 * @since 1.3.0
	 */
	class WP_MCP_AI_DietPi_Service_Catalogue {

		/**
		 * Default service catalogue.
		 *
		 * Each entry: { name, port, api_type, dietpi_id, api_path, auth_type }.
		 *
		 * api_type values: 'json-rpc', 'rest', 'rest-v3', 'ssh-only'.
		 *
		 * @since 1.3.0
		 * @var array
		 */
		const SERVICES = array(
			'transmission-daemon' => array(
				'name'      => 'Transmission',
				'port'      => 9091,
				'api_type'  => 'json-rpc',
				'dietpi_id' => 44,
				'api_path'  => '/transmission/rpc',
				'auth_type' => 'basic',
				'category'  => 'downloads',
			),
			'jackett'             => array(
				'name'       => 'Jackett',
				'port'       => 9117,
				'api_type'   => 'rest',
				'dietpi_id'  => 135,
				'api_path'   => '/api/v2.0',
				'auth_type'  => 'api_key_query',
				'auth_param' => 'apikey',
				'category'   => 'indexer',
			),
			'sonarr'              => array(
				'name'        => 'Sonarr',
				'port'        => 8989,
				'api_type'    => 'rest-v3',
				'dietpi_id'   => 144,
				'api_path'    => '/api/v3',
				'auth_type'   => 'api_key_header',
				'auth_header' => 'X-Api-Key',
				'category'    => 'media-automation',
			),
			'radarr'              => array(
				'name'        => 'Radarr',
				'port'        => 7878,
				'api_type'    => 'rest-v3',
				'dietpi_id'   => 145,
				'api_path'    => '/api/v3',
				'auth_type'   => 'api_key_header',
				'auth_header' => 'X-Api-Key',
				'category'    => 'media-automation',
			),
			'plexmediaserver'     => array(
				'name'        => 'Plex Media Server',
				'port'        => 32400,
				'api_type'    => 'rest',
				'dietpi_id'   => 42,
				'api_path'    => '',
				'auth_type'   => 'token_header',
				'auth_header' => 'X-Plex-Token',
				'category'    => 'media-center',
			),
			'jellyfin'            => array(
				'name'           => 'Jellyfin',
				'port'           => 8096,
				'api_type'       => 'rest',
				'dietpi_id'      => 169,
				'api_path'       => '',
				'auth_type'      => 'token_header_emby',
				'auth_header'    => 'X-Emby-Authorization',
				'auth_value_fmt' => 'MediaBrowser Client="NV oOS", Device="DietPi Toolkit", DeviceId="mcp-ai-wpoos", Version="1.0", Token="%s"',
				'category'       => 'media-center',
			),
		);

		/**
		 * Get the full service catalogue.
		 *
		 * Merges registered defaults with any overrides from the
		 * `wp_mcp_ai_dietpi_service_catalogue` filter.
		 *
		 * @since 1.3.0
		 *
		 * @return array
		 */
		public static function get_all() {
			$defaults = self::SERVICES;

			/**
			 * Filter the DietPi service catalogue.
			 *
			 * Register additional services or override port / API path defaults.
			 *
			 * @since 1.3.0
			 *
			 * @param array $defaults Default service catalogue.
			 * @return array
			 */
			$services = apply_filters( 'wp_mcp_ai_dietpi_service_catalogue', $defaults );

			return is_array( $services ) ? $services : $defaults;
		}

		/**
		 * Get a single service entry by its DietPi service name.
		 *
		 * @since 1.3.0
		 *
		 * @param string $service_name DietPi service name (e.g. 'sonarr', 'transmission-daemon').
		 * @return array|null Service entry or null.
		 */
		public static function get( $service_name ) {
			$services = self::get_all();
			return isset( $services[ $service_name ] ) ? $services[ $service_name ] : null;
		}

		/**
		 * Get the subset of services actively managed by this toolkit.
		 *
		 * These are the 6 core apps (Transmission, Jackett, Sonarr, Radarr, Plex, Jellyfin).
		 *
		 * @since 1.3.0
		 *
		 * @return array
		 */
		public static function get_managed_apps() {
			return array_keys( self::SERVICES );
		}

		/**
		 * Get services filtered by category.
		 *
		 * @since 1.3.0
		 *
		 * @param string $category Category slug (downloads, indexer, media-automation, media-center).
		 * @return array
		 */
		public static function get_by_category( $category ) {
			$services = self::get_all();
			$filtered = array();

			foreach ( $services as $slug => $entry ) {
				if ( isset( $entry['category'] ) && $entry['category'] === $category ) {
					$filtered[ $slug ] = $entry;
				}
			}

			return $filtered;
		}

		/**
		 * Resolve the full base URL for an app including port and API path.
		 *
		 * @since 1.3.0
		 *
		 * @param string $service_name DietPi service name.
		 * @param string $host         Pi hostname or IP.
		 * @return string|null Full base URL or null if service unknown.
		 */
		public static function resolve_url( $service_name, $host ) {
			$entry = self::get( $service_name );
			if ( null === $entry ) {
				return null;
			}

			$host = rtrim( $host, '/' );
			$port = isset( $entry['port'] ) ? ':' . $entry['port'] : '';
			$path = isset( $entry['api_path'] ) ? $entry['api_path'] : '';

			// Transmission requires no scheme in the catalogue — tools use http by default.
			$scheme = 'http';
			return $scheme . '://' . $host . $port . $path;
		}
	}
}
