<?php
/**
 * Cloudways API v2 Client
 *
 * Authenticated HTTP client for Cloudways Platform API v2.
 * Handles OAuth token caching, automatic refresh, and uniform error mapping.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage Cloudways_Toolkit
 * @since      1.1.15
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Cloudways_Client' ) ) {

	/**
	 * Cloudways API v2 HTTP client.
	 *
	 * Singleton. Provides authenticated GET/POST/PUT/DELETE methods,
	 * transparent token caching with automatic refresh, and uniform
	 * error mapping (all non-success responses become WP_Error).
	 */
	class WP_MCP_AI_Cloudways_Client {

		/**
		 * Singleton instance.
		 *
		 * @var self|null
		 */
		private static $instance = null;

		/**
		 * Filterable API base URL.
		 *
		 * @use apply_filters( 'wp_mcp_ai_cloudways_api_base', … )
		 *
		 * @var string
		 */
		const API_BASE = 'https://api.cloudways.com/api/v2';

		/**
		 * OAuth token endpoint.
		 *
		 * @use apply_filters( 'wp_mcp_ai_cloudways_oauth_endpoint', … )
		 *
		 * @var string
		 */
		const OAUTH_ENDPOINT = 'https://api.cloudways.com/api/v2/oauth/access_token';

		/**
		 * Seconds before expiry to refresh the token proactively.
		 *
		 * @var int
		 */
		const TOKEN_BUFFER_SECONDS = 60;

		/**
		 * Get the singleton instance.
		 *
		 * @since 1.1.15
		 *
		 * @return self
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Private constructor — singleton.
		 */
		private function __construct() { }

		/**
		 * Prevent cloning.
		 */
		private function __clone() { }

		/**
		 * Check whether Cloudways credentials are configured.
		 *
		 * @since 1.1.15
		 *
		 * @return bool
		 */
		public function is_configured() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return ! empty( $settings['cloudways_email'] ) && ! empty( $settings['cloudways_api_key'] );
		}

		/**
		 * Get a valid OAuth access token, refreshing if necessary.
		 *
		 * @since 1.1.15
		 *
		 * @return string|WP_Error Access token on success.
		 */
		public function get_access_token() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			// Return cached token if still valid.
			if ( ! empty( $settings['cloudways_access_token'] ) ) {
				$expires_at = isset( $settings['cloudways_token_expires_at'] ) ? absint( $settings['cloudways_token_expires_at'] ) : 0;

				if ( $expires_at > 0 && time() < ( $expires_at - self::TOKEN_BUFFER_SECONDS ) ) {
					return $settings['cloudways_access_token'];
				}
			}

			// Token missing or expired — exchange credentials.
			if ( empty( $settings['cloudways_email'] ) || empty( $settings['cloudways_api_key'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_no_credentials',
					__( 'Cloudways credentials are not configured.', 'mcp-ai-wpoos-pro' )
				);
			}

			$email   = $settings['cloudways_email'];
			$api_key = $settings['cloudways_api_key'];

			$endpoint = apply_filters( 'wp_mcp_ai_cloudways_oauth_endpoint', self::OAUTH_ENDPOINT );

			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout' => 15,
					'body'    => array(
						'email'   => $email,
						'api_key' => $api_key,
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_oauth_request_failed',
					$response->get_error_message()
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );
			$decoded     = json_decode( $body, true );

			if ( 200 !== $status_code ) {
				$error_message = '';

				if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
					$error_message = $decoded['message'];
				} elseif ( is_array( $decoded ) && isset( $decoded['error_description'] ) ) {
					$error_message = $decoded['error_description'];
				} else {
					$error_message = sprintf(
						/* translators: %d: HTTP status code. */
						__( 'HTTP %d response', 'mcp-ai-wpoos-pro' ),
						$status_code
					);
				}

				return new WP_Error(
					'wp_mcp_ai_cloudways_auth_failed',
					$error_message
				);
			}

			if ( ! is_array( $decoded ) || empty( $decoded['access_token'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_token',
					__( 'Cloudways did not return an access token.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Cache the new token.
			$settings['cloudways_access_token']     = $decoded['access_token'];
			$settings['cloudways_token_expires_at'] = time() + ( isset( $decoded['expires_in'] ) ? absint( $decoded['expires_in'] ) : 3600 );
			$settings['cloudways_connected']        = true;
			$settings['cloudways_connection_time']  = time();
			update_option( 'wp_mcp_ai_settings', $settings );

			return $decoded['access_token'];
		}

		/**
		 * Make an authenticated GET request.
		 *
		 * @since 1.1.15
		 *
		 * @param string $path  API path relative to base (e.g. '/server').
		 * @param array  $query Optional query parameters.
		 * @return array|WP_Error Decoded JSON body on success.
		 */
		public function get( $path, $query = array() ) {
			return $this->request( 'GET', $path, $query );
		}

		/**
		 * Make an authenticated POST request.
		 *
		 * @since 1.1.15
		 *
		 * @param string $path API path relative to base.
		 * @param array  $body Request body.
		 * @return array|WP_Error Decoded JSON body on success.
		 */
		public function post( $path, $body = array() ) {
			return $this->request( 'POST', $path, array(), $body );
		}

		/**
		 * Make an authenticated PUT request.
		 *
		 * @since 1.1.15
		 *
		 * @param string $path API path relative to base.
		 * @param array  $body Request body.
		 * @return array|WP_Error Decoded JSON body on success.
		 */
		public function put( $path, $body = array() ) {
			return $this->request( 'PUT', $path, array(), $body );
		}

		/**
		 * Make an authenticated DELETE request.
		 *
		 * @since 1.1.15
		 *
		 * @param string $path API path relative to base.
		 * @param array  $body Optional request body.
		 * @return array|WP_Error Decoded JSON body on success.
		 */
		public function delete( $path, $body = array() ) {
			return $this->request( 'DELETE', $path, array(), $body );
		}

		/**
		 * Centralized request method.
		 *
		 * @since 1.1.15
		 *
		 * @param string $method HTTP method.
		 * @param string $path   API path relative to base.
		 * @param array  $query  Query parameters.
		 * @param array  $body   Request body (for POST/PUT).
		 * @return array|WP_Error Decoded JSON body on success.
		 */
		private function request( $method, $path, $query = array(), $body = array() ) {
			$token = $this->get_access_token();

			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$base_url = apply_filters( 'wp_mcp_ai_cloudways_api_base', self::API_BASE );
			$url      = trailingslashit( $base_url ) . ltrim( $path, '/' );

			if ( ! empty( $query ) ) {
				$url = add_query_arg( $query, $url );
			}

			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = class_exists( 'WP_MCP_AI_Resource_Manager' ) ? WP_MCP_AI_Resource_Manager::instance() : null;
			$timeout      = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : ( $resource_mgr ? $resource_mgr->get_request_timeout() : 15 );
			$timeout      = max( 5, $timeout );

			$args = array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			);

			if ( ! empty( $body ) ) {
				$args['body']                    = wp_json_encode( $body );
				$args['headers']['Content-Type'] = 'application/json';
			}

			switch ( $method ) {
				case 'GET':
					$response = wp_remote_get( $url, $args );
					break;
				case 'DELETE':
					$args['method'] = 'DELETE';
					$response       = wp_remote_request( $url, $args );
					break;
				case 'POST':
					$response = wp_remote_post( $url, $args );
					break;
				case 'PUT':
				default:
					$args['method'] = 'PUT';
					$response       = wp_remote_request( $url, $args );
					break;
			}

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_request_failed',
					$response->get_error_message()
				);
			}

			$status_code   = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$data          = json_decode( $response_body, true );

			if ( $status_code >= 200 && $status_code < 300 ) {
				if ( ! is_array( $data ) ) {
					return new WP_Error(
						'wp_mcp_ai_cloudways_invalid_response',
						__( 'Cloudways returned an invalid response.', 'mcp-ai-wpoos-pro' )
					);
				}
				return $data;
			}

			// Map HTTP errors.
			$error_code = 'wp_mcp_ai_cloudways_api_error';

			if ( 401 === $status_code ) {
				$error_code = 'wp_mcp_ai_cloudways_unauthorized';
			} elseif ( 429 === $status_code ) {
				$error_code = 'wp_mcp_ai_cloudways_rate_limited';
			} elseif ( 404 === $status_code ) {
				$error_code = 'wp_mcp_ai_cloudways_not_found';
			}

			$error_message = '';
			if ( is_array( $data ) && isset( $data['message'] ) ) {
				$error_message = $data['message'];
			} elseif ( is_array( $data ) && isset( $data['error_description'] ) ) {
				$error_message = $data['error_description'];
			} else {
				$error_message = sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Cloudways API returned HTTP %d', 'mcp-ai-wpoos-pro' ),
					$status_code
				);
			}

			return new WP_Error( $error_code, $error_message );
		}

		/**
		 * Disconnect from Cloudways (clear cached tokens).
		 *
		 * @since 1.1.15
		 */
		public function disconnect() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			unset( $settings['cloudways_access_token'] );
			unset( $settings['cloudways_token_expires_at'] );
			unset( $settings['cloudways_connected'] );
			unset( $settings['cloudways_connection_time'] );
			unset( $settings['cloudways_account_name'] );

			update_option( 'wp_mcp_ai_settings', $settings );
		}

		/**
		 * Handle admin-post disconnect request for Cloudways.
		 *
		 * Clears cached OAuth tokens and redirects back to the connections page.
		 * API credentials (email, API key, server/app IDs) are preserved.
		 *
		 * @since 1.0.0
		 */
		public function handle_cloudways_disconnect() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'mcp-ai-wpoos' ) );
			}

			check_admin_referer( 'wp_mcp_ai_cloudways_disconnect' );

			$this->disconnect();

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'wp-mcp-ai-dashboard',
						'tab'        => 'tools',
						'subtab'     => 'connections',
						'connection' => 'cloudways',
						'cloudways_disconnected' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}
}
