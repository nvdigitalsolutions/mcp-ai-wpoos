<?php
/**
 * Flowhub Cannabis Dispensary API client wrapper.
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

if ( ! class_exists( 'WP_MCP_AI_Flowhub_Client' ) ) {
	/**
	 * Provides a wrapper around Flowhub API.
	 * Maintains separation of concerns: this class handles ONLY API communication.
	 * WordPress integration and capability checks are handled by tool classes.
	 *
	 * Flowhub API uses header-based authentication with clientId and key headers.
	 * See: https://flowhub.stoplight.io/docs/public-developer-portal/
	 */
	class WP_MCP_AI_Flowhub_Client {
		const API_ENDPOINT = 'https://api.flowhub.co';

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
		 * Retrieve the configured API Key (referred to as "key" header in Flowhub API).
		 *
		 * @param string|null $connection_id Optional connection ID to get credentials from.
		 * @return string
		 */
		public function get_key( $connection_id = null ) {
			// Use instance connection_id if not provided.
			if ( null === $connection_id ) {
				$connection_id = $this->connection_id;
			}

			// Try to get from connection first.
			if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
				if ( $connection && ! empty( $connection['api_key'] ) ) {
					return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
				}
			}

			// Fallback to settings.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['flowhub_api_key'] ) ? $settings['flowhub_api_key'] : '';
		}

		/**
		 * Legacy method for backwards compatibility.
		 *
		 * @param string|null $connection_id Optional connection ID to get credentials from.
		 * @return string
		 */
		public function get_api_key( $connection_id = null ) {
			return $this->get_key( $connection_id );
		}

		/**
		 * Retrieve the configured Client ID (referred to as "clientId" header in Flowhub API).
		 *
		 * @param string|null $connection_id Optional connection ID to get credentials from.
		 * @return string
		 */
		public function get_client_id( $connection_id = null ) {
			// Use instance connection_id if not provided.
			if ( null === $connection_id ) {
				$connection_id = $this->connection_id;
			}

			// Try to get from connection first.
			if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
				if ( $connection && ! empty( $connection['client_id'] ) ) {
					return $connection['client_id'];
				}
			}

			// Fallback to settings.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['flowhub_client_id'] ) ? $settings['flowhub_client_id'] : '';
		}

		/**
		 * Retrieve the configured Location ID.
		 *
		 * @param string|null $connection_id Optional connection ID to get credentials from.
		 * @return string
		 */
		public function get_location_id( $connection_id = null ) {
			// Use instance connection_id if not provided.
			if ( null === $connection_id ) {
				$connection_id = $this->connection_id;
			}

			// Try to get from connection first.
			if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
				if ( $connection && ! empty( $connection['location_id'] ) ) {
					return $connection['location_id'];
				}
			}

			// Fallback to settings.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['flowhub_location_id'] ) ? $settings['flowhub_location_id'] : '';
		}

		/**
		 * Get the API endpoint URL.
		 *
		 * @return string
		 */
		protected function get_api_endpoint() {
			return self::API_ENDPOINT;
		}

		/**
		 * Sanitize error response body for safe inclusion in error data.
		 * Truncates large bodies and extracts useful information from HTML.
		 *
		 * @param string $body Raw response body.
		 * @return string Sanitized and truncated body.
		 */
		protected function sanitize_error_body( $body ) {
			// Truncate very large bodies to prevent memory issues.
			$max_length = 500;
			if ( strlen( $body ) > $max_length ) {
				$body = substr( $body, 0, $max_length ) . '... [truncated]';
			}

			// If it looks like HTML, try to extract the error message.
			if ( preg_match( '/<title>(.*?)<\/title>/i', $body, $matches ) ) {
				$title = sanitize_text_field( $matches[1] );
				// Also extract h1 if present.
				if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/i', $body, $h1_matches ) ) {
					$h1 = sanitize_text_field( $h1_matches[1] );
					return sprintf( '%s: %s', $title, $h1 );
				}
				return $title;
			}

			// Otherwise, just strip tags and sanitize.
			return sanitize_text_field( wp_strip_all_tags( $body ) );
		}

		/**
		 * Make an authenticated API request to Flowhub.
		 * Uses header-based authentication with clientId and key headers.
		 *
		 * @param string $endpoint API endpoint path (e.g., '/v0/inventoryNonZero').
		 * @param string $method   HTTP method (GET, POST, PUT, DELETE).
		 * @param array  $data     Request data for POST/PUT requests.
		 * @param array  $options  Additional options (timeout, query params).
		 * @return array|WP_Error API response or error.
		 */
		public function make_request( $endpoint, $method = 'GET', $data = array(), $options = array() ) {
			$client_id = $this->get_client_id();
			$key       = $this->get_key();

			if ( empty( $client_id ) || empty( $key ) ) {
				WP_MCP_AI_Logger::log_error(
					'Flowhub credentials missing or empty.',
					array(
						'has_client_id'    => ! empty( $client_id ),
						'has_key'          => ! empty( $key ),
						'connection_id'    => $this->connection_id,
						'using_connection' => ! empty( $this->connection_id ),
					)
				);

				return new WP_Error(
					'wp_mcp_ai_missing_flowhub_config',
					__( 'Flowhub Client ID and API Key must be configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_flowhub_credentials' => __( 'Add Flowhub credentials in the NV oOS settings or Remote Sites.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			// Log credential retrieval for debugging (without exposing actual values).
			WP_MCP_AI_Logger::log_event(
				'flowhub_credentials_check',
				'Flowhub credentials retrieved.',
				array(
					'connection_id'    => $this->connection_id,
					'using_connection' => ! empty( $this->connection_id ),
					'client_id_length' => strlen( $client_id ),
					'key_length'       => strlen( $key ),
				)
			);

			$url = trailingslashit( $this->get_api_endpoint() ) . ltrim( $endpoint, '/' );

			// Add query parameters if provided.
			if ( isset( $options['query'] ) && is_array( $options['query'] ) ) {
				$url = add_query_arg( $options['query'], $url );
			}

			$request_args = array(
				'timeout' => isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 30,
				'method'  => strtoupper( $method ),
				'headers' => array(
					'clientId'     => $client_id,
					'key'          => $key,
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
			);

			if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) && ! empty( $data ) ) {
				$request_args['body'] = wp_json_encode( $data );
			}

			WP_MCP_AI_Logger::log_event(
				'flowhub_api_request',
				sprintf( 'Making %s request to Flowhub API.', $method ),
				array(
					'endpoint' => $endpoint,
					'full_url' => $url,
					'method'   => $method,
				)
			);

			$response = wp_remote_request( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'Flowhub API request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The Flowhub API request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'Flowhub', 'mcp-ai-wpoos' )
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			// Check HTTP status code first, before attempting to parse JSON.
			// This ensures we properly report HTTP errors (403, 500, etc.) even when
			// the response body is HTML or other non-JSON content (e.g., nginx error pages).
			if ( $code < 200 || $code >= 300 ) {
				// Try to decode JSON for error details, but don't fail if it's not JSON.
				$decoded = json_decode( $body, true );

				// If JSON parsing failed, use the raw body for logging.
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					// Truncate and sanitize body for safe inclusion in error data.
					// HTML error pages can be large and contain sensitive information.
					$sanitized_body = $this->sanitize_error_body( $body );

					WP_MCP_AI_Logger::log_error(
						'Flowhub returned non-JSON error response.',
						array(
							'code'         => $code,
							'endpoint'     => $endpoint,
							'body'         => $sanitized_body,
							'content_type' => wp_remote_retrieve_header( $response, 'content-type' ),
						)
					);

					// Provide a clear error message based on HTTP status code.
					$error_message = sprintf(
						/* translators: %d: HTTP status code */
						__( 'Flowhub API returned HTTP %d error.', 'mcp-ai-wpoos' ),
						$code
					);

					// Add specific guidance for common error codes.
					$error_data = array(
						'status'       => $code,
						'body'         => $sanitized_body,
						'content_type' => wp_remote_retrieve_header( $response, 'content-type' ),
					);

					// Add actionable error messages for specific HTTP codes.
					if ( 403 === $code ) {
						$error_data['actions'] = array(
							'check_credentials'  => __( 'Verify your Flowhub Client ID and API Key are correct.', 'mcp-ai-wpoos' ),
							'check_permissions'  => __( 'Ensure your Flowhub account has API access enabled.', 'mcp-ai-wpoos' ),
							'check_ip_whitelist' => __( 'Confirm your server IP address is whitelisted in Flowhub.', 'mcp-ai-wpoos' ),
							'contact_flowhub'    => __( 'Contact Flowhub support if the issue persists.', 'mcp-ai-wpoos' ),
						);
					} elseif ( 401 === $code ) {
						$error_data['actions'] = array(
							'check_credentials' => __( 'Your Flowhub credentials are invalid. Please update them.', 'mcp-ai-wpoos' ),
						);
					} elseif ( 429 === $code ) {
						$error_data['actions'] = array(
							'rate_limit' => __( 'Flowhub API rate limit exceeded. Please wait a few minutes and try again.', 'mcp-ai-wpoos' ),
						);
					} elseif ( $code >= 500 ) {
						$error_data['actions'] = array(
							'server_error' => __( 'Flowhub API is experiencing server issues. Please try again later.', 'mcp-ai-wpoos' ),
						);
					}

					return new WP_Error(
						'wp_mcp_ai_api_error',
						$error_message,
						$error_data
					);
				}

				// JSON was successfully parsed, extract error message.
				$error_message = isset( $decoded['message'] ) ? $decoded['message'] : __( 'Unexpected response from Flowhub.', 'mcp-ai-wpoos' );

				WP_MCP_AI_Logger::log_error(
					'Flowhub returned an error response.',
					array(
						'code'     => $code,
						'endpoint' => $endpoint,
						'body'     => $decoded,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_api_error',
					$error_message,
					array(
						'status' => $code,
						'body'   => $decoded,
					)
				);
			}

			// Success response: decode JSON and validate.
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode Flowhub API response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'Flowhub returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			WP_MCP_AI_Logger::log_event( 'flowhub_api_response', 'Flowhub API request completed successfully.' );

			// Flowhub API wraps responses in { "status": 200, "data": [...] } format.
			// Unwrap the data if present, otherwise return decoded response as-is.
			if ( array_key_exists( 'data', $decoded ) ) {
				return $decoded['data'];
			}

			return $decoded;
		}

		/**
		 * Retrieve inventory data.
		 *
		 * @param array $options Request options (limit, offset, filters).
		 * @return array|WP_Error Inventory data or error.
		 */
		public function get_inventory( $options = array() ) {
			$query_params = array();

			if ( isset( $options['limit'] ) ) {
				$query_params['limit'] = absint( $options['limit'] );
			}
			if ( isset( $options['offset'] ) ) {
				$query_params['offset'] = absint( $options['offset'] );
			}
			if ( isset( $options['room_id'] ) ) {
				$query_params['room_id'] = sanitize_text_field( $options['room_id'] );
			}

			// Build the endpoint with location_id in the path.
			// Flowhub API format: /v0/locations/{location_id}/inventoryNonZero.
			$location_id = $this->get_location_id();
			if ( empty( $location_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_location_id',
					__( 'Flowhub Location ID is required for inventory requests.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_location_id' => __( 'Add Flowhub Location ID in the NV oOS settings or Remote Sites.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$endpoint = sprintf( '/v0/locations/%s/inventoryNonZero', rawurlencode( sanitize_text_field( $location_id ) ) );

			// Use the location-specific non-zero inventory endpoint as per Flowhub API docs.
			return $this->make_request(
				$endpoint,
				'GET',
				array(),
				array( 'query' => $query_params )
			);
		}

		/**
		 * Retrieve orders/transactions.
		 *
		 * @param array $options Request options (limit, offset, status).
		 * @return array|WP_Error Orders data or error.
		 */
		public function get_orders( $options = array() ) {
			$query_params = array();

			if ( isset( $options['limit'] ) ) {
				$query_params['limit'] = absint( $options['limit'] );
			}
			if ( isset( $options['offset'] ) ) {
				$query_params['offset'] = absint( $options['offset'] );
			}
			if ( isset( $options['status'] ) ) {
				$query_params['status'] = sanitize_text_field( $options['status'] );
			}

			return $this->make_request(
				'/orders',
				'GET',
				array(),
				array( 'query' => $query_params )
			);
		}

		/**
		 * Create a new order.
		 *
		 * @param array $order_data Order data.
		 * @param array $options    Request options.
		 * @return array|WP_Error Created order or error.
		 */
		public function create_order( $order_data, $options = array() ) {
			return $this->make_request( '/orders', 'POST', $order_data, $options );
		}

		/**
		 * Retrieve customers.
		 *
		 * @param array $options Request options (limit, offset, search).
		 * @return array|WP_Error Customers data or error.
		 */
		public function get_customers( $options = array() ) {
			$query_params = array();

			if ( isset( $options['limit'] ) ) {
				$query_params['limit'] = absint( $options['limit'] );
			}
			if ( isset( $options['offset'] ) ) {
				$query_params['offset'] = absint( $options['offset'] );
			}
			if ( isset( $options['search'] ) ) {
				$query_params['search'] = sanitize_text_field( $options['search'] );
			}

			return $this->make_request(
				'/customers',
				'GET',
				array(),
				array( 'query' => $query_params )
			);
		}

		/**
		 * Create a new customer.
		 *
		 * @param array $customer_data Customer data.
		 * @param array $options       Request options.
		 * @return array|WP_Error Created customer or error.
		 */
		public function create_customer( $customer_data, $options = array() ) {
			return $this->make_request( '/customers', 'POST', $customer_data, $options );
		}

		/**
		 * Update an existing customer.
		 *
		 * @param string $customer_id   Customer ID.
		 * @param array  $customer_data Customer data to update.
		 * @param array  $options       Request options.
		 * @return array|WP_Error Updated customer or error.
		 */
		public function update_customer( $customer_id, $customer_data, $options = array() ) {
			return $this->make_request( '/customers/' . $customer_id, 'PUT', $customer_data, $options );
		}

		/**
		 * Retrieve products.
		 *
		 * @param array $options Request options (limit, offset, category).
		 * @return array|WP_Error Products data or error.
		 */
		public function get_products( $options = array() ) {
			$query_params = array();

			if ( isset( $options['limit'] ) ) {
				$query_params['limit'] = absint( $options['limit'] );
			}
			if ( isset( $options['offset'] ) ) {
				$query_params['offset'] = absint( $options['offset'] );
			}
			if ( isset( $options['category'] ) ) {
				$query_params['category'] = sanitize_text_field( $options['category'] );
			}

			return $this->make_request(
				'/products',
				'GET',
				array(),
				array( 'query' => $query_params )
			);
		}

		/**
		 * Create a new product.
		 *
		 * @param array $product_data Product data.
		 * @param array $options      Request options.
		 * @return array|WP_Error Created product or error.
		 */
		public function create_product( $product_data, $options = array() ) {
			return $this->make_request( '/products', 'POST', $product_data, $options );
		}

		/**
		 * Update an existing product.
		 *
		 * @param string $product_id   Product ID.
		 * @param array  $product_data Product data to update.
		 * @param array  $options      Request options.
		 * @return array|WP_Error Updated product or error.
		 */
		public function update_product( $product_id, $product_data, $options = array() ) {
			return $this->make_request( '/products/' . $product_id, 'PUT', $product_data, $options );
		}
	}
}
