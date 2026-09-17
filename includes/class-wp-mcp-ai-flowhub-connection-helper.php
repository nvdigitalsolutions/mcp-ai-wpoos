<?php
/**
 * FlowHub Connection Helper.
 *
 * Shared connection-resolution logic for FlowHub tools (base and Pro).
 *
 * Resolves the effective credential source for a FlowHub tool call:
 *
 *   1. Explicit Remote Sites connection ID argument (validated).
 *   2. FlowHub toolkit settings (client_id + api_key).
 *   3. Legacy NV oOS admin settings (flowhub_client_id + flowhub_api_key).
 *   4. The toolkit's configured sync connections (first valid FlowHub one).
 *   5. The first enabled FlowHub connection in Remote Sites.
 *
 * This lets tools run against a Remote Sites connection (e.g. a synced
 * "Kaya Flowhub" connection) even when the local settings stores have no
 * credentials — mirroring how the sync engine already resolves credentials.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_FlowHub_Connection_Helper' ) ) {

	/**
	 * FlowHub connection resolution helper.
	 *
	 * @since 1.1.81
	 */
	class WP_MCP_AI_FlowHub_Connection_Helper {

		/**
		 * FlowHub toolkit settings option name.
		 *
		 * @since 1.1.81
		 * @var string
		 */
		const TOOLKIT_OPTION = 'wp_mcp_ai_flowhub_toolkit_settings';

		/**
		 * Connection type value for FlowHub Remote Sites connections.
		 *
		 * @since 1.1.81
		 * @var string
		 */
		const CONNECTION_TYPE = 'flowhub';

		/**
		 * Resolve the effective FlowHub connection for a tool call.
		 *
		 * Priority order:
		 *  1. Explicit connection ID (validated: exists, FlowHub type, enabled, has credentials).
		 *  2. Toolkit settings credentials.
		 *  3. Legacy admin settings credentials.
		 *  4. First valid configured sync connection.
		 *  5. First enabled FlowHub connection in Remote Sites.
		 *
		 * @since 1.1.81
		 *
		 * @param string|null $explicit_connection_id Optional connection ID from tool arguments.
		 * @return array|WP_Error Resolved connection array:
		 *                        array(
		 *                            'connection_id' => string,       // '' means settings mode.
		 *                            'connection'    => array|null,   // Raw connection record (connection modes only).
		 *                            'source'        => string,       // 'connection' | 'sync_connection' | 'settings'.
		 *                            'credentials'   => array,        // client_id / api_key / location_id.
		 *                            'proxy'         => array,        // url / auth for proxied connections.
		 *                        )
		 *                        WP_Error when nothing resolves.
		 */
		public static function resolve_connection( $explicit_connection_id = null ) {
			// 1. Explicit connection ID from tool arguments.
			if ( ! empty( $explicit_connection_id ) ) {
				$connection_id = sanitize_key( $explicit_connection_id );
				$connection    = self::validate_connection( $connection_id );

				if ( is_wp_error( $connection ) ) {
					return $connection;
				}

				return array(
					'connection_id' => $connection_id,
					'connection'    => $connection,
					'source'        => 'connection',
					'credentials'   => self::get_connection_credentials( $connection_id ),
					'proxy'         => self::resolve_proxy( $connection_id, $connection ),
				);
			}

			// 2. Settings-based credentials (toolkit, then legacy admin store).
			$credentials = self::get_settings_credentials();
			if ( ! empty( $credentials['client_id'] ) && ! empty( $credentials['api_key'] ) ) {
				return array(
					'connection_id' => '',
					'connection'    => null,
					'source'        => 'settings',
					'credentials'   => $credentials,
					'proxy'         => self::resolve_proxy(),
				);
			}

			// 3. The toolkit's configured sync connections (admin's explicit choice).
			foreach ( self::get_sync_connections() as $connection_id ) {
				$connection = self::validate_connection( $connection_id );
				if ( ! is_wp_error( $connection ) ) {
					return array(
						'connection_id' => $connection_id,
						'connection'    => $connection,
						'source'        => 'sync_connection',
						'credentials'   => self::get_connection_credentials( $connection_id ),
						'proxy'         => self::resolve_proxy( $connection_id, $connection ),
					);
				}
			}

			// 4. First enabled FlowHub connection in Remote Sites.
			$first = self::get_first_enabled_connection();
			if ( null !== $first ) {
				return array(
					'connection_id' => $first['id'],
					'connection'    => $first['connection'],
					'source'        => 'connection',
					'credentials'   => self::get_connection_credentials( $first['id'] ),
					'proxy'         => self::resolve_proxy( $first['id'], $first['connection'] ),
				);
			}

			return new WP_Error(
				'wp_mcp_ai_flowhub_missing_credentials',
				__( 'FlowHub API credentials are not configured. Set your client ID and API key in the FlowHub Toolkit settings, or create an enabled FlowHub connection in Remote Sites.', 'mcp-ai-wpoos' )
			);
		}

		/**
		 * Validate a FlowHub Remote Sites connection.
		 *
		 * @since 1.1.81
		 *
		 * @param string $connection_id Connection ID (conn_...).
		 * @return array|WP_Error Connection record, or WP_Error with the same
		 *                        error codes the base tools historically used.
		 */
		public static function validate_connection( $connection_id ) {
			$connection_id = sanitize_key( $connection_id );

			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_not_found',
					__( 'Connection not found. Please check the connection ID.', 'mcp-ai-wpoos' )
				);
			}

			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

			if ( null === $connection ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_not_found',
					__( 'Connection not found. Please check the connection ID.', 'mcp-ai-wpoos' )
				);
			}

			// Validate connection type.
			if ( empty( $connection['connection_type'] ) || self::CONNECTION_TYPE !== $connection['connection_type'] ) {
				return new WP_Error(
					'wp_mcp_ai_pro_wrong_connection_type',
					__( 'This connection is not a Flowhub connection.', 'mcp-ai-wpoos' )
				);
			}

			// Check if connection is enabled.
			if ( empty( $connection['enabled'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_disabled',
					__( 'This connection is disabled. Please enable it in Remote Sites settings.', 'mcp-ai-wpoos' )
				);
			}

			// Check credentials exist on the connection.
			$credentials = self::get_connection_credentials( $connection_id );
			if ( empty( $credentials['client_id'] ) || empty( $credentials['api_key'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_flowhub_missing_credentials',
					__( 'This FlowHub connection is missing its client ID or API key. Edit the connection in Remote Sites and add both values.', 'mcp-ai-wpoos' )
				);
			}

			return $connection;
		}

		/**
		 * Get decrypted credentials for a FlowHub Remote Sites connection.
		 *
		 * @since 1.1.81
		 *
		 * @param string $connection_id Connection ID (conn_...).
		 * @return array{client_id:string, api_key:string, location_id:string}
		 */
		public static function get_connection_credentials( $connection_id ) {
			$credentials = array(
				'client_id'   => '',
				'api_key'     => '',
				'location_id' => '',
			);

			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				return $credentials;
			}

			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( sanitize_key( $connection_id ) );

			if ( ! $connection ) {
				return $credentials;
			}

			$credentials['client_id']   = isset( $connection['client_id'] ) ? trim( (string) $connection['client_id'] ) : '';
			$credentials['api_key']     = isset( $connection['api_key'] ) ? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] ) : '';
			$credentials['location_id'] = isset( $connection['location_id'] ) ? trim( (string) $connection['location_id'] ) : '';

			return $credentials;
		}

		/**
		 * Resolve proxy configuration for a FlowHub request.
		 *
		 * The connection's proxy wins when enabled (proxy password is decrypted
		 * from the connection record), falling back to the FlowHub toolkit
		 * settings — matching the sync engine's resolution order.
		 *
		 * @since 1.1.82
		 *
		 * @param string     $connection_id Optional connection ID (conn_...).
		 * @param array|null $connection    Optional pre-fetched connection record.
		 * @return array{url:string, auth:string} Proxy URL and optional
		 *         user:pass auth string (both empty when no proxy applies).
		 */
		public static function resolve_proxy( $connection_id = '', $connection = null ) {
			$config = array(
				'url'  => '',
				'auth' => '',
			);

			if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) && ! empty( $connection_id ) ) {
				if ( null === $connection ) {
					$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( sanitize_key( $connection_id ) );
				}

				if ( is_array( $connection ) && ! empty( $connection['proxy_enabled'] ) && ! empty( $connection['proxy_url'] ) ) {
					$username = isset( $connection['proxy_username'] ) ? trim( (string) $connection['proxy_username'] ) : '';
					$password = isset( $connection['proxy_password'] ) ? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['proxy_password'] ) : '';

					$config['url']  = (string) $connection['proxy_url'];
					$config['auth'] = ( ! empty( $username ) || ! empty( $password ) ) ? $username . ':' . $password : '';

					return $config;
				}
			}

			// Fall back to toolkit settings.
			$settings = get_option( self::TOOLKIT_OPTION, array() );
			if ( ! empty( $settings['proxy_enabled'] ) && ! empty( $settings['proxy_url'] ) ) {
				$username = isset( $settings['proxy_username'] ) ? trim( (string) wp_unslash( $settings['proxy_username'] ) ) : '';
				$password = isset( $settings['proxy_password'] ) ? (string) wp_unslash( $settings['proxy_password'] ) : '';

				$config['url']  = (string) wp_unslash( $settings['proxy_url'] );
				$config['auth'] = ( ! empty( $username ) || ! empty( $password ) ) ? $username . ':' . $password : '';
			}

			return $config;
		}

		/**
		 * Get settings-based FlowHub credentials.
		 *
		 * Checks the FlowHub toolkit settings first, then the legacy NV oOS
		 * admin settings (flowhub_* keys) used by the base plugin client.
		 *
		 * @since 1.1.81
		 *
		 * @return array{client_id:string, api_key:string, location_id:string}
		 */
		public static function get_settings_credentials() {
			$credentials = array(
				'client_id'   => '',
				'api_key'     => '',
				'location_id' => '',
			);

			// FlowHub toolkit settings.
			$settings = get_option( self::TOOLKIT_OPTION, array() );
			if ( ! empty( $settings['client_id'] ) && ! empty( $settings['api_key'] ) ) {
				$credentials['client_id']   = trim( (string) wp_unslash( $settings['client_id'] ) );
				$credentials['api_key']     = (string) wp_unslash( $settings['api_key'] ); // API key; stored as-is.
				$credentials['location_id'] = isset( $settings['location_id'] ) ? trim( (string) wp_unslash( $settings['location_id'] ) ) : '';

				return $credentials;
			}

			// Legacy base-plugin admin settings (flowhub_* keys).
			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
				$admin = WP_MCP_AI_Admin_Settings::get_settings();
				if ( ! empty( $admin['flowhub_client_id'] ) && ! empty( $admin['flowhub_api_key'] ) ) {
					$credentials['client_id']   = trim( (string) wp_unslash( $admin['flowhub_client_id'] ) );
					$credentials['api_key']     = (string) wp_unslash( $admin['flowhub_api_key'] ); // API key; stored as-is.
					$credentials['location_id'] = isset( $admin['flowhub_location_id'] ) ? trim( (string) wp_unslash( $admin['flowhub_location_id'] ) ) : '';
				}
			}

			return $credentials;
		}

		/**
		 * Get the configured sync connection IDs from toolkit settings.
		 *
		 * @since 1.1.81
		 *
		 * @return string[] Connection IDs.
		 */
		public static function get_sync_connections() {
			$settings = get_option( self::TOOLKIT_OPTION, array() );
			$sync     = isset( $settings['sync_connections'] ) ? $settings['sync_connections'] : array();

			if ( ! is_array( $sync ) ) {
				return array();
			}

			$ids = array_map(
				static function ( $connection_id ) {
					return sanitize_key( (string) $connection_id );
				},
				$sync
			);

			return array_values( array_filter( $ids ) );
		}

		/**
		 * Find the first enabled FlowHub connection with credentials.
		 *
		 * @since 1.1.81
		 *
		 * @return array{id:string, connection:array}|null Connection record with
		 *         its ID, or null when none exists.
		 */
		public static function get_first_enabled_connection() {
			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				return null;
			}

			foreach ( WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() as $connection_id => $connection ) {
				if ( ! is_array( $connection ) ) {
					continue;
				}

				if ( self::CONNECTION_TYPE !== ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) {
					continue;
				}

				if ( empty( $connection['enabled'] ) ) {
					continue;
				}

				// Skip connections without stored credentials — they cannot serve requests.
				if ( empty( $connection['client_id'] ) || empty( $connection['api_key'] ) ) {
					continue;
				}

				return array(
					'id'         => (string) $connection_id,
					'connection' => $connection,
				);
			}

			return null;
		}

		/**
		 * Whether FlowHub credentials are configured anywhere.
		 *
		 * @since 1.1.81
		 *
		 * @return bool True when settings credentials or a usable connection exist.
		 */
		public static function is_configured() {
			$credentials = self::get_settings_credentials();
			if ( ! empty( $credentials['client_id'] ) && ! empty( $credentials['api_key'] ) ) {
				return true;
			}

			foreach ( self::get_sync_connections() as $connection_id ) {
				if ( ! is_wp_error( self::validate_connection( $connection_id ) ) ) {
					return true;
				}
			}

			return null !== self::get_first_enabled_connection();
		}
	}
}
