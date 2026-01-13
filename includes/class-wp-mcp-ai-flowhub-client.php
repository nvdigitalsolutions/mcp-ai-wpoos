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
		const API_ENDPOINT  = 'https://api.flowhub.co';

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
					WP_MCP_AI_Logger::log_error(
						'Flowhub returned non-JSON error response.',
						array(
							'code'     => $code,
							'endpoint' => $endpoint,
							'body'     => $body,
						)
					);

					// Provide a clear error message based on HTTP status code.
					$error_message = sprintf(
						/* translators: %d: HTTP status code */
						__( 'Flowhub API returned HTTP %d error.', 'mcp-ai-wpoos' ),
						$code
					);

					return new WP_Error(
						'wp_mcp_ai_api_error',
						$error_message,
						array(
							'status' => $code,
							'body'   => $body,
						)
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

			// Add location_id if available (endpoint is "Non-Zero Inventory By Location").
			// FlowHub may require or filter by location_id for multi-location dispensaries.
			$location_id = $this->get_location_id();
			if ( ! empty( $location_id ) ) {
				$query_params['location_id'] = sanitize_text_field( $location_id );
			}

			// Use the non-zero inventory endpoint as per Flowhub API docs.
			return $this->make_request(
				'/v0/inventoryNonZero',
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
