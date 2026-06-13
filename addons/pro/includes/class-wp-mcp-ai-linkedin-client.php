<?php
/**
 * LinkedIn REST API client.
 *
 * Provides a wrapper around the LinkedIn REST API (https://learn.microsoft.com/en-us/linkedin/).
 * Authentication uses OAuth 2.0 with a refresh token obtained via the
 * Remote Sites OAuth flow. Access tokens are cached in a transient.
 *
 * @package WP_MCP_AI_Pro
 * @since   2.10.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_LinkedIn_Client' ) ) {

	/**
	 * LinkedIn REST API client.
	 *
	 * Handles API communication with LinkedIn via the REST API.
	 * WordPress integration and capability checks are handled by tool classes.
	 *
	 * @since 2.10.0
	 */
	class WP_MCP_AI_LinkedIn_Client {

		/**
		 * LinkedIn REST API base URL.
		 *
		 * @var string
		 */
		const API_BASE = 'https://api.linkedin.com/rest';

		/**
		 * LinkedIn OAuth2 token endpoint.
		 *
		 * @var string
		 */
		const TOKEN_ENDPOINT = 'https://www.linkedin.com/oauth/v2/accessToken';

		/**
		 * LinkedIn OAuth2 authorization endpoint.
		 *
		 * @var string
		 */
		const AUTH_ENDPOINT = 'https://www.linkedin.com/oauth/v2/authorization';

		/**
		 * Default request timeout in seconds.
		 *
		 * @var int
		 */
		const DEFAULT_TIMEOUT = 30;

		/**
		 * Maximum response body size in bytes (5 MB).
		 *
		 * @var int
		 */
		const MAX_RESPONSE_SIZE = 5242880;

		/**
		 * Remote Sites connection ID.
		 *
		 * @var string|null
		 */
		protected $connection_id = null;

		/**
		 * Resolved connection data array (cached per request).
		 *
		 * @var array|null
		 */
		protected $connection = null;

		/**
		 * Constructor.
		 *
		 * @param string|null $connection_id Optional Remote Sites connection ID.
		 */
		public function __construct( $connection_id = null ) {
			$this->connection_id = $connection_id;
		}

		// ------------------------------------------------------------------ //
		// Credential helpers                                                  //
		// ------------------------------------------------------------------ //

		/**
		 * Load and cache the connection data array.
		 *
		 * @return array|null Connection array or null when not found.
		 */
		protected function get_connection() {
			if ( null !== $this->connection ) {
				return $this->connection;
			}

			if ( ! empty( $this->connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$this->connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $this->connection_id );
			}

			return $this->connection;
		}

		/**
		 * Get a valid access token, refreshing via the refresh token if needed.
		 *
		 * Access tokens are cached in a transient for 90% of expires_in (min 60s).
		 *
		 * @return string|WP_Error Access token string or WP_Error on failure.
		 */
		public function get_access_token() {
			$connection = $this->get_connection();

			if ( ! $connection ) {
				return new WP_Error(
					'wp_mcp_ai_linkedin_no_connection',
					__( 'LinkedIn connection not found.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( empty( $connection['refresh_token'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_linkedin_no_refresh_token',
					__( 'LinkedIn refresh token is missing. Please complete the OAuth flow.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Build a per-connection transient key.
			$transient_key = 'wp_mcp_ai_linkedin_at_' . md5( $this->connection_id );

			$cached = get_transient( $transient_key );
			if ( $cached ) {
				return $cached;
			}

			// Decrypt sensitive values.
			$client_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_secret'] );
			$refresh_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['refresh_token'] );

			// Exchange refresh token for a new access token.
			$response = wp_remote_post(
				self::TOKEN_ENDPOINT,
				array(
					'timeout' => self::DEFAULT_TIMEOUT,
					'headers' => array(
						'Content-Type' => 'application/x-www-form-urlencoded',
					),
					'body'    => array(
						'grant_type'    => 'refresh_token',
						'client_id'     => $connection['client_id'],
						'client_secret' => $client_secret,
						'refresh_token' => $refresh_token,
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'wp_mcp_ai_linkedin_token_request_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'LinkedIn token request failed: %s', 'mcp-ai-wpoos-pro' ),
						$response->get_error_message()
					)
				);
			}

			$status = wp_remote_retrieve_response_code( $response );
			$body   = wp_remote_retrieve_body( $response );

			if ( 200 !== (int) $status ) {
				return new WP_Error(
					'wp_mcp_ai_linkedin_token_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'LinkedIn token endpoint returned HTTP %d. Check your credentials.', 'mcp-ai-wpoos-pro' ),
						$status
					)
				);
			}

			$data = json_decode( $body, true );

			if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_linkedin_token_parse_error',
					__( 'Could not parse access token from LinkedIn response.', 'mcp-ai-wpoos-pro' )
				);
			}

			$access_token = $data['access_token'];
			// Use 90% of the reported lifetime as cache TTL (10% safety margin).
			$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
			$cache_ttl  = max( 60, (int) floor( $expires_in * 0.9 ) );

			set_transient( $transient_key, $access_token, $cache_ttl );

			return $access_token;
		}

		// ------------------------------------------------------------------ //
		// API requests                                                        //
		// ------------------------------------------------------------------ //

		/**
		 * Execute a GET request against the LinkedIn REST API.
		 *
		 * @param string $path    API path relative to /rest/ (e.g. '/me').
		 * @param array  $params  Optional query-string parameters.
		 * @return array|WP_Error Decoded response data or WP_Error on failure.
		 */
		public function get( $path, $params = array() ) {
			return $this->request( 'GET', $path, $params );
		}

		/**
		 * Execute a request against the LinkedIn REST API.
		 *
		 * @param string $method  HTTP method (GET, POST).
		 * @param string $path    API path relative to /rest/.
		 * @param array  $params  Query parameters (GET) or body data (POST).
		 * @return array|WP_Error Decoded response data or WP_Error on failure.
		 */
		protected function request( $method, $path, $params = array() ) {
			$access_token = $this->get_access_token();

			if ( is_wp_error( $access_token ) ) {
				return $access_token;
			}

			$url = self::API_BASE . $path;

			$args = array(
				'timeout' => self::DEFAULT_TIMEOUT,
				'headers' => array(
					'Authorization'             => 'Bearer ' . $access_token,
					'LinkedIn-Version'          => '202505',
					'X-RestLi-Protocol-Version' => '2.0.0',
					'Accept'                    => 'application/json',
				),
			);

			if ( 'GET' === strtoupper( $method ) ) {
				if ( ! empty( $params ) ) {
					$url = add_query_arg( $params, $url );
				}
				$response = wp_remote_get( $url, $args );
			} else {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body']                    = wp_json_encode( $params );
				$response                        = wp_remote_post( $url, $args );
			}

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'wp_mcp_ai_linkedin_request_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'LinkedIn API request failed: %s', 'mcp-ai-wpoos-pro' ),
						$response->get_error_message()
					)
				);
			}

			$status = wp_remote_retrieve_response_code( $response );
			$body   = wp_remote_retrieve_body( $response );

			// Guard against oversized responses.
			if ( strlen( $body ) > self::MAX_RESPONSE_SIZE ) {
				return new WP_Error(
					'wp_mcp_ai_linkedin_response_too_large',
					__( 'LinkedIn API response exceeded maximum allowed size.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( 401 === (int) $status ) {
				// Invalidate cached token so next call fetches a fresh one.
				$transient_key = 'wp_mcp_ai_linkedin_at_' . md5( $this->connection_id );
				delete_transient( $transient_key );

				return new WP_Error(
					'wp_mcp_ai_linkedin_unauthorized',
					__( 'LinkedIn API returned 401 Unauthorized. Please re-authorise the connection.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( $status < 200 || $status >= 300 ) {
				return new WP_Error(
					'wp_mcp_ai_linkedin_http_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'LinkedIn API returned HTTP %d.', 'mcp-ai-wpoos-pro' ),
						$status
					)
				);
			}

			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				return new WP_Error(
					'wp_mcp_ai_linkedin_parse_error',
					__( 'Could not parse LinkedIn API response.', 'mcp-ai-wpoos-pro' )
				);
			}

			return $data;
		}

		/**
		 * Retrieve the authenticated user's LinkedIn profile.
		 *
		 * @return array|WP_Error Profile data or WP_Error on failure.
		 */
		public function get_me() {
			return $this->get( '/me' );
		}

		/**
		 * Search for LinkedIn jobs (when API access is available).
		 *
		 * Uses the LinkedIn Jobs Search endpoint when the authenticated
		 * app has the required permissions.
		 *
		 * @param array $filters Search filter parameters.
		 * @return array|WP_Error Search results or WP_Error.
		 */
		public function search_jobs( $filters = array() ) {
			$params = array();

			if ( ! empty( $filters['keywords'] ) ) {
				$params['keywords'] = sanitize_text_field( $filters['keywords'] );
			}
			if ( ! empty( $filters['location'] ) ) {
				$params['location'] = sanitize_text_field( $filters['location'] );
			}
			if ( ! empty( $filters['count'] ) ) {
				$params['count'] = absint( $filters['count'] );
			}
			if ( ! empty( $filters['start'] ) ) {
				$params['start'] = absint( $filters['start'] );
			}

			return $this->get( '/jobSearch', $params );
		}

		/**
		 * Test the connection by fetching the authenticated user's profile.
		 *
		 * @return array|WP_Error Test result array or WP_Error on failure.
		 */
		public function test_connection() {
			$result = $this->get_me();

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'success' => true,
				'message' => __( 'LinkedIn connection is working.', 'mcp-ai-wpoos-pro' ),
			);
		}
	}
}
