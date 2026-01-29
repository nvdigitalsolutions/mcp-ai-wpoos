<?php
/**
 * Firefly III Personal Finance Manager API client wrapper.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure required classes are loaded.
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
}

if ( ! class_exists( 'WP_MCP_AI_HTTP' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-http-helper.php';
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-http.php';
}

if ( ! class_exists( 'WP_MCP_AI_Firefly_Client' ) ) {
	/**
	 * Provides a wrapper around Firefly III API.
	 * Maintains separation of concerns: this class handles ONLY API communication.
	 * WordPress integration and capability checks are handled by tool classes.
	 *
	 * Firefly III API uses Personal Access Token authentication via Bearer token.
	 * See: https://api-docs.firefly-iii.org/
	 */
	class WP_MCP_AI_Firefly_Client {
		/**
		 * Connection ID for Remote Sites connections.
		 *
		 * @var string|null
		 */
		protected $connection_id = null;

		/**
		 * Constructor.
		 *
		 * @param string|null $connection_id Optional connection ID.
		 */
		public function __construct( $connection_id = null ) {
			$this->connection_id = $connection_id;
		}

		/**
		 * Retrieve the configured API URL (Firefly III instance URL).
		 *
		 * @param string|null $connection_id Optional connection ID to get credentials from.
		 * @return string
		 */
		public function get_api_url( $connection_id = null ) {
			// Use instance connection_id if not provided.
			if ( null === $connection_id ) {
				$connection_id = $this->connection_id;
			}

			// Try to get from connection first.
			if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
				if ( $connection && ! empty( $connection['api_url'] ) ) {
					return rtrim( $connection['api_url'], '/' );
				}
			}

			// Fallback to settings.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['firefly_api_url'] ) ? rtrim( $settings['firefly_api_url'], '/' ) : '';
		}

		/**
		 * Retrieve the configured Personal Access Token.
		 *
		 * @param string|null $connection_id Optional connection ID to get credentials from.
		 * @return string
		 */
		public function get_access_token( $connection_id = null ) {
			// Use instance connection_id if not provided.
			if ( null === $connection_id ) {
				$connection_id = $this->connection_id;
			}

			// Try to get from connection first.
			if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
				if ( $connection && ! empty( $connection['access_token'] ) ) {
					return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['access_token'] );
				}
			}

			// Fallback to settings.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['firefly_access_token'] ) ? $settings['firefly_access_token'] : '';
		}

		/**
		 * Sanitize error response body for safe inclusion in error data.
		 * Truncates large bodies and extracts useful information from HTML.
		 *
		 * @param string $body Response body.
		 * @return string Sanitized body excerpt.
		 */
		protected function sanitize_error_body( $body ) {
			if ( empty( $body ) ) {
				return '';
			}

			// If JSON, decode and return.
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) && JSON_ERROR_NONE === json_last_error() ) {
				return wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}

			// Extract text from HTML responses.
			if ( false !== stripos( $body, '<html' ) || false !== stripos( $body, '<!doctype' ) ) {
				$body = wp_strip_all_tags( $body );
			}

			// Truncate large error bodies.
			if ( strlen( $body ) > 1000 ) {
				$body = substr( $body, 0, 1000 ) . '... [truncated]';
			}

			return $body;
		}

		/**
		 * Make a request to the Firefly III API.
		 *
		 * @param string $endpoint API endpoint (e.g., '/v1/accounts').
		 * @param string $method   HTTP method (GET, POST, PUT, DELETE).
		 * @param array  $body     Request body data.
		 * @param array  $options  Additional request options (timeout, headers, etc.).
		 * @return array|WP_Error Response data or error.
		 */
		protected function request( $endpoint, $method = 'GET', $body = array(), $options = array() ) {
			$api_url       = $this->get_api_url( $this->connection_id );
			$access_token  = $this->get_access_token( $this->connection_id );

			if ( empty( $api_url ) ) {
				WP_MCP_AI_Logger::log( 'firefly_client', 'API URL not configured', 'error' );
				return new WP_Error(
					'wp_mcp_ai_firefly_missing_url',
					__( 'Firefly III API URL is not configured. Please configure it in the plugin settings.', 'mcp-ai-wpoos' )
				);
			}

			if ( empty( $access_token ) ) {
				WP_MCP_AI_Logger::log( 'firefly_client', 'Access token not configured', 'error' );
				return new WP_Error(
					'wp_mcp_ai_firefly_missing_token',
					__( 'Firefly III Personal Access Token is not configured. Please configure it in the plugin settings.', 'mcp-ai-wpoos' )
				);
			}

			$url = $api_url . $endpoint;

			$timeout = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 30;

			$args = array(
				'method'  => strtoupper( $method ),
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/vnd.api+json',
					'Content-Type'  => 'application/json',
				),
			);

			// Add custom headers if provided.
			if ( ! empty( $options['headers'] ) && is_array( $options['headers'] ) ) {
				$args['headers'] = array_merge( $args['headers'], $options['headers'] );
			}

			// Add request body for POST/PUT/PATCH requests.
			if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) && ! empty( $body ) ) {
				$args['body'] = wp_json_encode( $body );
			}

			WP_MCP_AI_Logger::log(
				'firefly_client',
				sprintf( 'Making %s request to %s', $method, $endpoint ),
				'info'
			);

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log(
					'firefly_client',
					'Request failed: ' . $response->get_error_message(),
					'error'
				);
				return $response;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body_raw    = wp_remote_retrieve_body( $response );

			WP_MCP_AI_Logger::log(
				'firefly_client',
				sprintf( 'Response status: %d', $status_code ),
				'info'
			);

			// Handle non-2xx responses.
			if ( $status_code < 200 || $status_code >= 300 ) {
				$error_data = array(
					'status' => $status_code,
					'body'   => $this->sanitize_error_body( $body_raw ),
				);

				WP_MCP_AI_Logger::log(
					'firefly_client',
					sprintf( 'API error (status %d): %s', $status_code, $error_data['body'] ),
					'error'
				);

				return new WP_Error(
					'wp_mcp_ai_firefly_api_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Firefly III API returned error (status %d). Please check your credentials and API URL.', 'mcp-ai-wpoos' ),
						$status_code
					),
					$error_data
				);
			}

			// Parse JSON response.
			$data = json_decode( $body_raw, true );

			if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log(
					'firefly_client',
					'Failed to parse JSON response: ' . json_last_error_msg(),
					'error'
				);
				return new WP_Error(
					'wp_mcp_ai_firefly_invalid_json',
					__( 'Failed to parse Firefly III API response.', 'mcp-ai-wpoos' ),
					array( 'body' => $this->sanitize_error_body( $body_raw ) )
				);
			}

			return $data;
		}

		/**
		 * Get accounts from Firefly III.
		 *
		 * @param array $options Query options (type, limit, page).
		 * @return array|WP_Error Response data or error.
		 */
		public function get_accounts( $options = array() ) {
			$query_params = array();

			if ( isset( $options['type'] ) ) {
				$query_params['type'] = sanitize_text_field( $options['type'] );
			}

			if ( isset( $options['limit'] ) ) {
				$query_params['limit'] = absint( $options['limit'] );
			}

			if ( isset( $options['page'] ) ) {
				$query_params['page'] = absint( $options['page'] );
			}

			$endpoint = '/api/v1/accounts';
			if ( ! empty( $query_params ) ) {
				$endpoint .= '?' . http_build_query( $query_params );
			}

			return $this->request( $endpoint, 'GET', array(), $options );
		}

		/**
		 * Get transactions from Firefly III.
		 *
		 * @param array $options Query options (start, end, type, limit, page).
		 * @return array|WP_Error Response data or error.
		 */
		public function get_transactions( $options = array() ) {
			$query_params = array();

			if ( isset( $options['start'] ) ) {
				$query_params['start'] = sanitize_text_field( $options['start'] );
			}

			if ( isset( $options['end'] ) ) {
				$query_params['end'] = sanitize_text_field( $options['end'] );
			}

			if ( isset( $options['type'] ) ) {
				$query_params['type'] = sanitize_text_field( $options['type'] );
			}

			if ( isset( $options['limit'] ) ) {
				$query_params['limit'] = absint( $options['limit'] );
			}

			if ( isset( $options['page'] ) ) {
				$query_params['page'] = absint( $options['page'] );
			}

			$endpoint = '/api/v1/transactions';
			if ( ! empty( $query_params ) ) {
				$endpoint .= '?' . http_build_query( $query_params );
			}

			return $this->request( $endpoint, 'GET', array(), $options );
		}

		/**
		 * Create a transaction in Firefly III.
		 *
		 * @param array $transaction_data Transaction data.
		 * @param array $options          Additional request options.
		 * @return array|WP_Error Response data or error.
		 */
		public function create_transaction( $transaction_data, $options = array() ) {
			$endpoint = '/api/v1/transactions';

			return $this->request( $endpoint, 'POST', $transaction_data, $options );
		}

		/**
		 * Get budgets from Firefly III.
		 *
		 * @param array $options Query options (start, end, limit, page).
		 * @return array|WP_Error Response data or error.
		 */
		public function get_budgets( $options = array() ) {
			$query_params = array();

			if ( isset( $options['start'] ) ) {
				$query_params['start'] = sanitize_text_field( $options['start'] );
			}

			if ( isset( $options['end'] ) ) {
				$query_params['end'] = sanitize_text_field( $options['end'] );
			}

			if ( isset( $options['limit'] ) ) {
				$query_params['limit'] = absint( $options['limit'] );
			}

			if ( isset( $options['page'] ) ) {
				$query_params['page'] = absint( $options['page'] );
			}

			$endpoint = '/api/v1/budgets';
			if ( ! empty( $query_params ) ) {
				$endpoint .= '?' . http_build_query( $query_params );
			}

			return $this->request( $endpoint, 'GET', array(), $options );
		}

		/**
		 * Get categories from Firefly III.
		 *
		 * @param array $options Query options (limit, page).
		 * @return array|WP_Error Response data or error.
		 */
		public function get_categories( $options = array() ) {
			$query_params = array();

			if ( isset( $options['limit'] ) ) {
				$query_params['limit'] = absint( $options['limit'] );
			}

			if ( isset( $options['page'] ) ) {
				$query_params['page'] = absint( $options['page'] );
			}

			$endpoint = '/api/v1/categories';
			if ( ! empty( $query_params ) ) {
				$endpoint .= '?' . http_build_query( $query_params );
			}

			return $this->request( $endpoint, 'GET', array(), $options );
		}

		/**
		 * Get bills from Firefly III.
		 *
		 * @param array $options Query options (start, end, limit, page).
		 * @return array|WP_Error Response data or error.
		 */
		public function get_bills( $options = array() ) {
			$query_params = array();

			if ( isset( $options['start'] ) ) {
				$query_params['start'] = sanitize_text_field( $options['start'] );
			}

			if ( isset( $options['end'] ) ) {
				$query_params['end'] = sanitize_text_field( $options['end'] );
			}

			if ( isset( $options['limit'] ) ) {
				$query_params['limit'] = absint( $options['limit'] );
			}

			if ( isset( $options['page'] ) ) {
				$query_params['page'] = absint( $options['page'] );
			}

			$endpoint = '/api/v1/bills';
			if ( ! empty( $query_params ) ) {
				$endpoint .= '?' . http_build_query( $query_params );
			}

			return $this->request( $endpoint, 'GET', array(), $options );
		}
	}
}
