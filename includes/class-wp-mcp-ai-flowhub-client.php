<?php
/**
 * Flowhub Cannabis Dispensary API client wrapper.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Flowhub_Client' ) ) {
	/**
	 * Provides a wrapper around Flowhub API.
	 * Maintains separation of concerns: this class handles ONLY API communication.
	 * WordPress integration and capability checks are handled by tool classes.
	 */
	class WP_MCP_AI_Flowhub_Client {
		const AUTH_ENDPOINT = 'https://flowhub.auth0.com/oauth/token';
		const API_ENDPOINT  = 'https://api.flowhub.co';

		/**
		 * Retrieve the configured API Key.
		 *
		 * @return string
		 */
		public function get_api_key() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['flowhub_api_key'] ) ? $settings['flowhub_api_key'] : '';
		}

		/**
		 * Retrieve the configured Client ID.
		 *
		 * @return string
		 */
		public function get_client_id() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['flowhub_client_id'] ) ? $settings['flowhub_client_id'] : '';
		}

		/**
		 * Retrieve the configured Client Secret.
		 *
		 * @return string
		 */
		public function get_client_secret() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['flowhub_client_secret'] ) ? $settings['flowhub_client_secret'] : '';
		}

		/**
		 * Retrieve the configured Location ID.
		 *
		 * @return string
		 */
		public function get_location_id() {
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
		 * Request an OAuth2 access token from Flowhub.
		 *
		 * @param array $options Optional timeout and other settings.
		 * @return string|WP_Error Access token string or error.
		 */
		protected function get_access_token( array $options = array() ) {
			$client_id     = $this->get_client_id();
			$client_secret = $this->get_client_secret();

			if ( empty( $client_id ) || empty( $client_secret ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_flowhub_credentials',
					__( 'Flowhub Client ID and Client Secret must be configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_flowhub_credentials' => __( 'Add Flowhub credentials in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$url = self::AUTH_ENDPOINT;

			$request_args = array(
				'timeout' => isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 30,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'grant_type'    => 'client_credentials',
						'audience'      => 'https://api.flowhub.co',
					)
				),
			);

			WP_MCP_AI_Logger::log_event(
				'flowhub_token_request',
				'Requesting access token from Flowhub.'
			);

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'Flowhub token request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The Flowhub authentication request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'Flowhub', 'mcp-ai-wpoos' )
				);
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode Flowhub token response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'Flowhub returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error_description'] ) ? $decoded['error_description'] : __( 'Unexpected response from Flowhub.', 'mcp-ai-wpoos' );

				WP_MCP_AI_Logger::log_error(
					'Flowhub returned an error response for token request.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_auth_error',
					$error_message,
					array(
						'status' => $code,
						'body'   => $decoded,
					)
				);
			}

			if ( ! isset( $decoded['access_token'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_token',
					__( 'No access token in Flowhub response.', 'mcp-ai-wpoos' ),
					array( 'body' => $decoded )
				);
			}

			WP_MCP_AI_Logger::log_event( 'flowhub_token_response', 'Flowhub access token obtained successfully.' );

			return $decoded['access_token'];
		}

		/**
		 * Make an authenticated API request to Flowhub.
		 *
		 * @param string $endpoint API endpoint path (e.g., '/inventory').
		 * @param string $method   HTTP method (GET, POST, PUT, DELETE).
		 * @param array  $data     Request data for POST/PUT requests.
		 * @param array  $options  Additional options (timeout, query params).
		 * @return array|WP_Error API response or error.
		 */
		public function make_request( $endpoint, $method = 'GET', $data = array(), $options = array() ) {
			$api_key     = $this->get_api_key();
			$location_id = $this->get_location_id();

			if ( empty( $api_key ) || empty( $location_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_flowhub_config',
					__( 'Flowhub API Key and Location ID must be configured.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Get access token.
			$access_token = $this->get_access_token( $options );

			if ( is_wp_error( $access_token ) ) {
				return $access_token;
			}

			$url = trailingslashit( $this->get_api_endpoint() ) . ltrim( $endpoint, '/' );

			// Add query parameters if provided.
			if ( isset( $options['query'] ) && is_array( $options['query'] ) ) {
				$url = add_query_arg( $options['query'], $url );
			}

			$request_args = array(
				'timeout' => isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 30,
				'method'  => strtoupper( $method ),
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'X-API-Key'     => $api_key,
					'X-Location-ID' => $location_id,
					'Content-Type'  => 'application/json',
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

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode Flowhub API response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'Flowhub returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
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

			WP_MCP_AI_Logger::log_event( 'flowhub_api_response', 'Flowhub API request completed successfully.' );

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

			return $this->make_request(
				'/inventory',
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
