<?php
/**
 * Upwork GraphQL API client.
 *
 * Provides a wrapper around the Upwork API (https://developers.upwork.com/).
 * Authentication uses OAuth 2.0 with a refresh token obtained via the
 * Remote Sites OAuth flow. Access tokens are cached in a transient.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Upwork_Client' ) ) {

	/**
	 * Upwork GraphQL API client.
	 *
	 * Handles API communication with Upwork via the GraphQL endpoint.
	 * WordPress integration and capability checks are handled by tool classes.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Upwork_Client {

		/**
		 * Upwork GraphQL API endpoint.
		 *
		 * @var string
		 */
		const GRAPHQL_ENDPOINT = 'https://api.upwork.com/graphql';

		/**
		 * Upwork OAuth2 token endpoint.
		 *
		 * @var string
		 */
		const TOKEN_ENDPOINT = 'https://www.upwork.com/api/v3/oauth2/token';

		/**
		 * Upwork OAuth2 authorization endpoint.
		 *
		 * @var string
		 */
		const AUTH_ENDPOINT = 'https://www.upwork.com/ab/account-security/oauth2/authorize';

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
		 * Maximum GraphQL requests per rolling 60-second window.
		 *
		 * Upwork's GraphQL API enforces rate limits per OAuth application.
		 * This client-side throttle prevents 429 responses by pausing callers
		 * before the upstream limit is hit.
		 *
		 * @since 2.12.0
		 * @var int
		 */
		const RATE_LIMIT_PER_MINUTE = 30;

		/**
		 * Minimum backoff in seconds when a 429 response is received.
		 *
		 * @since 2.12.0
		 * @var int
		 */
		const MIN_BACKOFF_SECONDS = 5;

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
					'wp_mcp_ai_upwork_no_connection',
					__( 'Upwork connection not found.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( empty( $connection['refresh_token'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_upwork_no_refresh_token',
					__( 'Upwork refresh token is missing. Please complete the OAuth flow.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Build a per-connection transient key.
			$transient_key = 'wp_mcp_ai_upwork_at_' . md5( $this->connection_id );

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
						'Accept'       => 'application/json',
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
					'wp_mcp_ai_upwork_token_request_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Upwork token request failed: %s', 'mcp-ai-wpoos-pro' ),
						$response->get_error_message()
					)
				);
			}

			$status = wp_remote_retrieve_response_code( $response );
			$body   = wp_remote_retrieve_body( $response );

			if ( 200 !== (int) $status ) {
				return new WP_Error(
					'wp_mcp_ai_upwork_token_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Upwork token endpoint returned HTTP %d. Check your credentials.', 'mcp-ai-wpoos-pro' ),
						$status
					)
				);
			}

			$data = json_decode( $body, true );

			if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_upwork_token_parse_error',
					__( 'Could not parse access token from Upwork response.', 'mcp-ai-wpoos-pro' )
				);
			}

			$access_token = $data['access_token'];
			// Use 90% of the reported lifetime as cache TTL (10% safety margin ensures a fresh
			// token is available before expiry), with a floor of 60 seconds.
			$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
			$cache_ttl  = max( 60, (int) floor( $expires_in * 0.9 ) );

			// Cache the token with a conservative TTL.
			set_transient( $transient_key, $access_token, $cache_ttl );

			return $access_token;
		}

		// ------------------------------------------------------------------ //
		// API requests                                                        //
		// ------------------------------------------------------------------ //

		/**
		 * Execute a GraphQL query against the Upwork API.
		 *
		 * Enforces a client-side rate limit (per rolling 60 s window) to avoid
		 * HTTP 429 responses from the Upwork upstream.
		 *
		 * @param string $query     GraphQL query string.
		 * @param array  $variables Optional GraphQL variables.
		 * @return array|WP_Error Decoded response data or WP_Error on failure.
		 */
		public function graphql( $query, $variables = array() ) {
			// ── Client-side rate limiter ──
			$rate_error = $this->check_rate_limit();
			if ( is_wp_error( $rate_error ) ) {
				return $rate_error;
			}

			$access_token = $this->get_access_token();

			if ( is_wp_error( $access_token ) ) {
				return $access_token;
			}

			$payload = array( 'query' => $query );

			if ( ! empty( $variables ) ) {
				$payload['variables'] = $variables;
			}

			$response = wp_remote_post(
				self::GRAPHQL_ENDPOINT,
				array(
					'timeout' => self::DEFAULT_TIMEOUT,
					'headers' => array(
						'Authorization' => 'Bearer ' . $access_token,
						'Content-Type'  => 'application/json',
						'Accept'        => 'application/json',
					),
					'body'    => wp_json_encode( $payload ),
				)
			);

			// Track the request for rate limiting (success or failure — we count
			// every outbound call so we don't accidentally flood the upstream).
			$this->track_request();

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'wp_mcp_ai_upwork_request_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Upwork API request failed: %s', 'mcp-ai-wpoos-pro' ),
						$response->get_error_message()
					)
				);
			}

			$status = wp_remote_retrieve_response_code( $response );
			$body   = wp_remote_retrieve_body( $response );

			// Guard against oversized responses.
			if ( strlen( $body ) > self::MAX_RESPONSE_SIZE ) {
				return new WP_Error(
					'wp_mcp_ai_upwork_response_too_large',
					__( 'Upwork API response exceeded maximum allowed size.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( 401 === (int) $status ) {
				// Invalidate cached token so next call fetches a fresh one.
				$transient_key = 'wp_mcp_ai_upwork_at_' . md5( $this->connection_id );
				delete_transient( $transient_key );

				return new WP_Error(
					'wp_mcp_ai_upwork_unauthorized',
					__( 'Upwork API returned 401 Unauthorized. Please re-authorise the connection.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( 429 === (int) $status ) {
				// Upwork rate limit. Extract Retry-After if provided; otherwise
				// apply an escalating backoff so the caller pauses before retrying.
				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				$backoff     = $retry_after > 0 ? $retry_after : self::MIN_BACKOFF_SECONDS;

				// Record the backoff as a rate-limit signal so subsequent calls
				// from this connection block for the cooldown period.
				$this->set_rate_limit_cooldown( $backoff );

				return new WP_Error(
					'wp_mcp_ai_upwork_rate_limited',
					sprintf(
						/* translators: %d: retry-after seconds */
						__( 'Upwork API rate limit reached. Please wait %d seconds before retrying. Consider reducing call frequency or scheduling searches during off-peak hours.', 'mcp-ai-wpoos-pro' ),
						$backoff
					)
				);
			}

			if ( $status < 200 || $status >= 300 ) {
				return new WP_Error(
					'wp_mcp_ai_upwork_http_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Upwork API returned HTTP %d.', 'mcp-ai-wpoos-pro' ),
						$status
					)
				);
			}

			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				return new WP_Error(
					'wp_mcp_ai_upwork_parse_error',
					__( 'Could not parse Upwork API response.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Surface GraphQL-level errors.
			if ( ! empty( $data['errors'] ) ) {
				$messages = array();
				foreach ( $data['errors'] as $err ) {
					if ( isset( $err['message'] ) ) {
						$messages[] = $err['message'];
					}
				}
				return new WP_Error(
					'wp_mcp_ai_upwork_graphql_error',
					implode( '; ', $messages )
				);
			}

			return $data;
		}

		/**
		 * Test the connection by executing a minimal viewer query.
	 *
	 * @return array|WP_Error Test result array or WP_Error on failure.
		 */
		public function test_connection() {
			$result = $this->graphql( '{ viewer { id } }' );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'success' => true,
				'message' => __( 'Upwork connection is working.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// ------------------------------------------------------------------ //
		// Rate limiting (client-side throttle)                               //
		// ------------------------------------------------------------------ //

		/**
		 * Check whether the rate limit allows a new GraphQL request.
		 *
		 * Uses a rolling 60-second window with a configurable ceiling.
		 * A cooldown (set after a real 429) blocks all requests regardless
		 * of the window count.
		 *
		 * @since 2.12.0
		 *
		 * @return true|WP_Error True when allowed; WP_Error when blocked.
		 */
		private function check_rate_limit() {
			$key = 'wp_mcp_ai_upwork_rl_' . md5( $this->connection_id ?: 'default' );

			// ── Cooldown block (set by a prior 429 response) ──
			$cooldown = get_transient( $key . '_cooldown' );
			if ( $cooldown ) {
				$elapsed = time() - (int) $cooldown;
				$backoff = (int) get_transient( $key . '_cooldown_secs' );
				if ( $backoff < 1 ) {
					$backoff = self::MIN_BACKOFF_SECONDS;
				}
				$remaining = max( 0, $backoff - $elapsed );
				if ( $remaining > 0 ) {
					return new WP_Error(
						'wp_mcp_ai_upwork_rate_limit_cooldown',
						sprintf(
							/* translators: %d: seconds remaining */
							__( 'Upwork API is cooling down after a rate-limit response. Please wait %d seconds before making another request.', 'mcp-ai-wpoos-pro' ),
							$remaining
						)
					);
				}
				// Cooldown expired — clear it so normal rate limiting resumes.
				delete_transient( $key . '_cooldown' );
				delete_transient( $key . '_cooldown_secs' );
			}

			// ── Rolling window counter ──
			$count    = get_transient( $key );
			$max      = apply_filters( 'wp_mcp_ai_upwork_rate_limit', self::RATE_LIMIT_PER_MINUTE );

			if ( false === $count ) {
				return true;
			}

			if ( (int) $count >= (int) $max ) {
				return new WP_Error(
					'wp_mcp_ai_upwork_rate_limit_exceeded',
					sprintf(
						/* translators: %d: maximum requests per minute */
						__( 'Upwork API rate limit reached. You have made %d requests in the last 60 seconds. Please wait before retrying.', 'mcp-ai-wpoos-pro' ),
						$max
					)
				);
			}

			return true;
		}

		/**
		 * Track a request for rate limiting (rolling 60-second window).
		 *
		 * @since 2.12.0
		 */
		private function track_request() {
			$key  = 'wp_mcp_ai_upwork_rl_' . md5( $this->connection_id ?: 'default' );
			$count = get_transient( $key );

			if ( false === $count ) {
				$count = 0;
			}

			++$count;
			set_transient( $key, $count, MINUTE_IN_SECONDS );
		}

		/**
		 * Set a rate-limit cooldown after receiving a genuine 429.
		 *
		 * Blocks all subsequent requests for $seconds regardless of the
		 * rolling window counter.
		 *
		 * @since 2.12.0
		 *
		 * @param int $seconds Cooldown duration in seconds.
		 */
		private function set_rate_limit_cooldown( $seconds ) {
			$key      = 'wp_mcp_ai_upwork_rl_' . md5( $this->connection_id ?: 'default' );
			$cooldown = absint( $seconds );
			if ( $cooldown < 1 ) {
				$cooldown = self::MIN_BACKOFF_SECONDS;
			}

			set_transient( $key . '_cooldown', time(), $cooldown );
			set_transient( $key . '_cooldown_secs', $cooldown, $cooldown );
		}
	}
}
