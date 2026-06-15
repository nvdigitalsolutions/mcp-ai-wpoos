<?php
/**
 * DietPi App API Client
 *
 * Shared HTTP client for all DietPi-managed applications (Transmission, Jackett,
 * Sonarr, Radarr, Plex, Jellyfin).  Handles per-app authentication, error
 * mapping to WP_Error, and timeout enforcement.
 *
 * Transmission JSON-RPC specifics (CSRF 409 → Session-Id retry) are handled
 * internally — callers use the same `get`/`post`/`put`/`delete` surface.
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

if ( ! class_exists( 'WP_MCP_AI_DietPi_App_Client' ) ) {

	/**
	 * DietPi app API client.
	 *
	 * @since 1.3.0
	 */
	class WP_MCP_AI_DietPi_App_Client {

		/**
		 * Singleton instance.
		 *
		 * @since 1.3.0
		 * @var self|null
		 */
		private static $instance = null;

		/**
		 * Resolved toolkit settings.
		 *
		 * @since 1.3.0
		 * @var array
		 */
		private $settings = array();

		/**
		 * Cached Transmission session IDs (host → session_id).
		 *
		 * @since 1.3.0
		 * @var array
		 */
		private $transmission_sessions = array();

		/**
		 * Get singleton instance.
		 *
		 * @since 1.3.0
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
		 * Constructor.
		 *
		 * @since 1.3.0
		 */
		private function __construct() {
			if ( function_exists( 'wp_mcp_ai_dietpi_get_settings' ) ) {
				$this->settings = wp_mcp_ai_dietpi_get_settings();
			}
		}

		/**
		 * Prevent cloning.
		 */
		private function __clone() {}

		/**
		 * Prevent unserialization.
		 *
		 * @throws \Exception Always, to prevent unserialization.
		 */
		public function __wakeup() {
			throw new \Exception( 'Cannot unserialize singleton.' );
		}

		/**
		 * Get app configuration from settings.
		 *
		 * @since 1.3.0
		 *
		 * @param string $app_slug App slug.
		 * @return array|null App config or null if not found.
		 */
		public function for_app( $app_slug ) {
			$apps = isset( $this->settings['apps'] ) ? $this->settings['apps'] : array();
			return isset( $apps[ $app_slug ] ) ? $apps[ $app_slug ] : null;
		}

		/**
		 * Check if an app is configured and enabled.
		 *
		 * @since 1.3.0
		 *
		 * @param string $app_slug App slug.
		 * @return bool
		 */
		public function is_app_configured( $app_slug ) {
			if ( function_exists( 'wp_mcp_ai_dietpi_is_app_configured' ) ) {
				return wp_mcp_ai_dietpi_is_app_configured( $app_slug );
			}

			$app = $this->for_app( $app_slug );
			if ( null === $app || empty( $app['enabled'] ) ) {
				return false;
			}

			$url = isset( $app['url'] ) ? trim( $app['url'] ) : '';
			return '' !== $url;
		}

		/**
		 * Build HTTP headers for an app.
		 *
		 * @since 1.3.0
		 *
		 * @param string $app_slug App slug.
		 * @return array|WP_Error Headers array or WP_Error.
		 */
		private function build_headers( $app_slug ) {
			$app = $this->for_app( $app_slug );
			if ( null === $app ) {
				return new WP_Error(
					'wp_mcp_ai_dietpi_app_not_configured',
					sprintf(
						/* translators: %s: app slug */
						__( 'App "%s" is not configured in DietPi settings.', 'mcp-ai-wpoos-pro' ),
						$app_slug
					)
				);
			}

			$headers = array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			);

			switch ( $app_slug ) {
				case 'transmission':
					if ( isset( $app['username'] ) && '' !== $app['username'] ) {
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
						$headers['Authorization'] = 'Basic ' . base64_encode( $app['username'] . ':' . $app['password'] );
					}
					break;

				case 'jackett':
					// API key is passed as query param, not header.
					break;

				case 'sonarr':
				case 'radarr':
					if ( isset( $app['api_key'] ) && '' !== $app['api_key'] ) {
						$headers['X-Api-Key'] = $app['api_key'];
					}
					break;

				case 'plex':
					if ( isset( $app['token'] ) && '' !== $app['token'] ) {
						$headers['X-Plex-Token'] = $app['token'];
					}
					break;

				case 'jellyfin':
					if ( isset( $app['api_key'] ) && '' !== $app['api_key'] ) {
						$token                           = $app['api_key'];
						$headers['X-Emby-Authorization'] = sprintf(
							'MediaBrowser Client="NV oOS", Device="DietPi Toolkit", DeviceId="mcp-ai-wpoos", Version="1.0", Token="%s"',
							$token
						);
					}
					break;
			}

			return $headers;
		}

		/**
		 * Resolve the base URL for an app.
		 *
		 * @since 1.3.0
		 *
		 * @param string $app_slug App slug.
		 * @return string|WP_Error Base URL or WP_Error.
		 */
		public function resolve_app_url( $app_slug ) {
			$app = $this->for_app( $app_slug );
			if ( null === $app ) {
				return new WP_Error(
					'wp_mcp_ai_dietpi_app_not_configured',
					__( 'App configuration not found.', 'mcp-ai-wpoos-pro' )
				);
			}

			$url = isset( $app['url'] ) ? trim( $app['url'] ) : '';
			if ( '' === $url ) {
				// Auto-resolve from host + port if URL is empty.
				$host = isset( $this->settings['host'] ) ? trim( $this->settings['host'] ) : '';
				if ( '' === $host ) {
					return new WP_Error(
						'wp_mcp_ai_dietpi_no_host',
						__( 'DietPi host is not configured.', 'mcp-ai-wpoos-pro' )
					);
				}

				if ( class_exists( 'WP_MCP_AI_DietPi_Service_Catalogue' ) ) {
					$url = WP_MCP_AI_DietPi_Service_Catalogue::resolve_url( $app_slug, $host );
				}

				if ( null === $url || '' === $url ) {
					return new WP_Error(
						'wp_mcp_ai_dietpi_cannot_resolve_url',
						sprintf(
							/* translators: %s: app slug */
							__( 'Could not resolve URL for app: %s.', 'mcp-ai-wpoos-pro' ),
							$app_slug
						)
					);
				}
			}

			return rtrim( $url, '/' );
		}

		/**
		 * Perform a GET request.
		 *
		 * @since 1.3.0
		 *
		 * @param string $app_slug App slug.
		 * @param string $path     API path (e.g. '/api/v3/series').
		 * @param array  $query    Optional query parameters.
		 * @param int    $timeout  Timeout in seconds.
		 * @return array|WP_Error Decoded JSON body or WP_Error.
		 */
		public function get( $app_slug, $path, $query = array(), $timeout = 30 ) {
			return $this->request( 'GET', $app_slug, $path, $query, null, $timeout );
		}

		/**
		 * Perform a POST request.
		 *
		 * @since 1.3.0
		 *
		 * @param string $app_slug App slug.
		 * @param string $path     API path.
		 * @param array  $body     Request body (will be JSON-encoded).
		 * @param int    $timeout  Timeout in seconds.
		 * @return array|WP_Error Decoded JSON body or WP_Error.
		 */
		public function post( $app_slug, $path, $body = array(), $timeout = 30 ) {
			return $this->request( 'POST', $app_slug, $path, array(), $body, $timeout );
		}

		/**
		 * Perform a PUT request.
		 *
		 * @since 1.3.0
		 *
		 * @param string $app_slug App slug.
		 * @param string $path     API path.
		 * @param array  $body     Request body (will be JSON-encoded).
		 * @param int    $timeout  Timeout in seconds.
		 * @return array|WP_Error Decoded JSON body or WP_Error.
		 */
		public function put( $app_slug, $path, $body = array(), $timeout = 30 ) {
			return $this->request( 'PUT', $app_slug, $path, array(), $body, $timeout );
		}

		/**
		 * Perform a DELETE request.
		 *
		 * @since 1.3.0
		 *
		 * @param string $app_slug App slug.
		 * @param string $path     API path.
		 * @param int    $timeout  Timeout in seconds.
		 * @return array|WP_Error Decoded JSON body or WP_Error.
		 */
		public function delete( $app_slug, $path, $timeout = 30 ) {
			return $this->request( 'DELETE', $app_slug, $path, array(), null, $timeout );
		}

		/**
		 * Core HTTP request method.
		 *
		 * @since 1.3.0
		 *
		 * @param string     $method   HTTP method.
		 * @param string     $app_slug App slug.
		 * @param string     $path     API path.
		 * @param array      $query    Query parameters.
		 * @param array|null $body     Request body (JSON-encoded if non-null).
		 * @param int        $timeout  Timeout in seconds.
		 * @return array|WP_Error
		 */
		private function request( $method, $app_slug, $path, $query = array(), $body = null, $timeout = 30 ) {
			$base_url = $this->resolve_app_url( $app_slug );
			if ( is_wp_error( $base_url ) ) {
				return $base_url;
			}

			$url = $base_url . '/' . ltrim( $path, '/' );

			// Jackett: append API key as query param.
			if ( 'jackett' === $app_slug ) {
				$app = $this->for_app( 'jackett' );
				if ( isset( $app['api_key'] ) && '' !== $app['api_key'] ) {
					$query['apikey'] = $app['api_key'];
				}
			}

			if ( ! empty( $query ) ) {
				$url = add_query_arg( $query, $url );
			}

			$headers = $this->build_headers( $app_slug );
			if ( is_wp_error( $headers ) ) {
				return $headers;
			}

			$args = array(
				'method'  => $method,
				'headers' => $headers,
				'timeout' => $timeout,
			);

			if ( null !== $body ) {
				$args['body'] = wp_json_encode( $body );
			}

			// Handle Transmission JSON-RPC specially (CSRF session-id handshake).
			if ( 'transmission' === $app_slug ) {
				return $this->transmission_request( $method, $url, $args );
			}

			$response = wp_remote_request( $url, $args );

			return $this->handle_response( $response, $app_slug );
		}

		/**
		 * Handle Transmission JSON-RPC with CSRF session-id retry.
		 *
		 * @since 1.3.0
		 *
		 * @param string $method  HTTP method.
		 * @param string $url     Request URL.
		 * @param array  $args    Request args.
		 * @return array|WP_Error
		 */
		private function transmission_request( $method, $url, $args ) {
			// Use cached session ID if available.
			$host_key = $url;
			if ( isset( $this->transmission_sessions[ $host_key ] ) ) {
				$args['headers']['X-Transmission-Session-Id'] = $this->transmission_sessions[ $host_key ];
			}

			$response = wp_remote_request( $url, $args );

			// Handle 409 Conflict (CSRF token required).
			if ( is_array( $response ) && 409 === wp_remote_retrieve_response_code( $response ) ) {
				$session_id = wp_remote_retrieve_header( $response, 'X-Transmission-Session-Id' );
				if ( ! empty( $session_id ) ) {
					$this->transmission_sessions[ $host_key ]     = $session_id;
					$args['headers']['X-Transmission-Session-Id'] = $session_id;

					// Retry with the session ID.
					$response = wp_remote_request( $url, $args );
				}
			}

			return $this->handle_response( $response, 'transmission' );
		}

		/**
		 * Handle HTTP response, mapping errors to WP_Error.
		 *
		 * @since 1.3.0
		 *
		 * @param array|WP_Error $response wp_remote_request result.
		 * @param string         $app_slug App slug for context.
		 * @return array|WP_Error
		 */
		private function handle_response( $response, $app_slug ) {
			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'wp_mcp_ai_dietpi_http_error',
					sprintf(
						/* translators: 1: app name, 2: error message */
						__( 'HTTP error connecting to %1$s: %2$s', 'mcp-ai-wpoos-pro' ),
						$app_slug,
						$response->get_error_message()
					)
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( $code >= 400 ) {
				$decoded = json_decode( $body, true );
				$message = '';

				if ( is_array( $decoded ) ) {
					// Try Sonarr/Radarr error format.
					if ( isset( $decoded['message'] ) ) {
						$message = $decoded['message'];
					} elseif ( isset( $decoded['error'] ) ) {
						$message = is_string( $decoded['error'] ) ? $decoded['error'] : wp_json_encode( $decoded['error'] );
					} elseif ( isset( $decoded['result'] ) && is_string( $decoded['result'] ) ) {
						// Transmission error format.
						$message = $decoded['result'];
					}
				}

				if ( '' === $message ) {
					$message = sprintf(
						/* translators: %d: HTTP status code */
						__( 'HTTP %d error', 'mcp-ai-wpoos-pro' ),
						$code
					);
				}

				return new WP_Error(
					'wp_mcp_ai_dietpi_app_error_' . $code,
					sprintf(
						/* translators: 1: app name, 2: HTTP status, 3: error message */
						__( '%1$s returned %2$d: %3$s', 'mcp-ai-wpoos-pro' ),
						$app_slug,
						$code,
						$message
					)
				);
			}

			$decoded = json_decode( $body, true );
			if ( null === $decoded && '' !== trim( $body ) ) {
				return new WP_Error(
					'wp_mcp_ai_dietpi_invalid_json',
					sprintf(
						/* translators: %s: app name */
						__( '%s returned invalid JSON.', 'mcp-ai-wpoos-pro' ),
						$app_slug
					)
				);
			}

			return null !== $decoded ? $decoded : array();
		}

		/**
		 * Send a Transmission JSON-RPC request.
		 *
		 * Convenience wrapper for the Transmission JSON-RPC protocol.
		 *
		 * @since 1.3.0
		 *
		 * @param string $method     RPC method name (e.g. 'torrent-get').
		 * @param array  $arguments  RPC arguments.
		 * @param string $tag        Optional request tag (for response correlation).
		 * @return array|WP_Error
		 */
		public function transmission_rpc( $method, $arguments = array(), $tag = '' ) {
			if ( '' === $tag ) {
				$tag = wp_rand( 1, 999999 );
			}

			$body = array(
				'method'    => $method,
				'arguments' => $arguments,
				'tag'       => (int) $tag,
			);

			$result = $this->post( 'transmission', '/transmission/rpc', $body, 30 );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Transmission returns { arguments: ..., result: ... }.
			if ( isset( $result['result'] ) && 'success' === $result['result'] ) {
				return isset( $result['arguments'] ) ? $result['arguments'] : array();
			}

			$error_msg = isset( $result['result'] ) ? $result['result'] : __( 'Unknown Transmission error.', 'mcp-ai-wpoos-pro' );

			return new WP_Error(
				'wp_mcp_ai_transmission_rpc_error',
				$error_msg
			);
		}
	}
}
