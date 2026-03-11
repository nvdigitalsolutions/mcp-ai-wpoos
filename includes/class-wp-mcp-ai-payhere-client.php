<?php
/**
 * PayHere Payment Gateway API client wrapper.
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

if ( ! class_exists( 'WP_MCP_AI_PayHere_Client' ) ) {
	/**
	 * Provides a wrapper around PayHere Retrieval API.
	 * Maintains separation of concerns: this class handles ONLY API communication.
	 * WordPress integration and capability checks are handled by tool classes.
	 */
	class WP_MCP_AI_PayHere_Client {
		const TOKEN_ENDPOINT_SANDBOX = 'https://sandbox.payhere.lk/merchant/v1/oauth/token';
		const TOKEN_ENDPOINT_LIVE    = 'https://www.payhere.lk/merchant/v1/oauth/token';
		const API_ENDPOINT_SANDBOX   = 'https://sandbox.payhere.lk/merchant/v1/payments';
		const API_ENDPOINT_LIVE      = 'https://www.payhere.lk/merchant/v1/payments';

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
		 * Retrieve the configured App ID.
		 *
		 * @param string|null $connection_id Optional connection ID to get credentials from.
		 * @return string
		 */
		public function get_app_id( $connection_id = null ) {
			// Use instance connection_id if not provided.
			if ( null === $connection_id ) {
				$connection_id = $this->connection_id;
			}

			// Try connection first if provided.
			if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
				if ( $connection && ! empty( $connection['app_id'] ) ) {
					return $connection['app_id'];
				}
			}

			// Fallback to settings.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return isset( $settings['payhere_app_id'] ) ? $settings['payhere_app_id'] : '';
		}

		/**
		 * Retrieve the configured App Secret.
		 *
		 * @param string|null $connection_id Optional connection ID to get credentials from.
		 * @return string
		 */
		public function get_app_secret( $connection_id = null ) {
			// Use instance connection_id if not provided.
			if ( null === $connection_id ) {
				$connection_id = $this->connection_id;
			}

			// Try connection first if provided.
			if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
				if ( $connection && ! empty( $connection['app_secret'] ) ) {
					return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['app_secret'] );
				}
			}

			// Fallback to settings.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return isset( $settings['payhere_app_secret'] ) ? $settings['payhere_app_secret'] : '';
		}

		/**
		 * Check if sandbox mode is enabled.
		 *
		 * @param string|null $connection_id Optional connection ID to get settings from.
		 * @return bool
		 */
		public function is_sandbox_mode( $connection_id = null ) {
			// Use instance connection_id if not provided.
			if ( null === $connection_id ) {
				$connection_id = $this->connection_id;
			}

			// Try connection first if provided.
			if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
				if ( $connection && isset( $connection['sandbox_mode'] ) ) {
					return ! empty( $connection['sandbox_mode'] );
				}
			}

			// Fallback to settings.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return ! empty( $settings['payhere_sandbox_mode'] );
		}

		/**
		 * Get the token endpoint URL based on environment.
		 *
		 * @return string
		 */
		protected function get_token_endpoint() {
			return $this->is_sandbox_mode() ? self::TOKEN_ENDPOINT_SANDBOX : self::TOKEN_ENDPOINT_LIVE;
		}

		/**
		 * Get the API endpoint URL based on environment.
		 *
		 * @return string
		 */
		protected function get_api_endpoint() {
			return $this->is_sandbox_mode() ? self::API_ENDPOINT_SANDBOX : self::API_ENDPOINT_LIVE;
		}

		/**
		 * Request an OAuth2 access token from PayHere.
		 *
		 * @param array $options Optional timeout and other settings.
		 * @return string|WP_Error Access token string or error.
		 */
		protected function get_access_token( array $options = array() ) {
			$app_id     = $this->get_app_id();
			$app_secret = $this->get_app_secret();

			if ( empty( $app_id ) || empty( $app_secret ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_payhere_credentials',
					__( 'PayHere App ID and App Secret must be configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_payhere_credentials' => __( 'Add PayHere credentials in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			// Create Base64-encoded authorization header.
			$auth_string = $app_id . ':' . $app_secret;
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64_encode used to construct an HTTP Basic Auth header (RFC 7617), not for obfuscation.
			$auth_base64 = base64_encode( $auth_string );

			$url = $this->get_token_endpoint();

			$request_args = array(
				'timeout' => isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 30,
				'headers' => array(
					'Authorization' => 'Basic ' . $auth_base64,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'grant_type' => 'client_credentials',
				),
			);

			WP_MCP_AI_Logger::log_event(
				'payhere_token_request',
				'Requesting access token from PayHere.',
				array( 'sandbox' => $this->is_sandbox_mode() )
			);

			$response = wp_remote_post( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'PayHere token request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The PayHere authentication request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'PayHere', 'mcp-ai-wpoos' )
				);
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode PayHere token response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'PayHere returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['error_description'] ) ? $decoded['error_description'] : __( 'Unexpected response from PayHere.', 'mcp-ai-wpoos' );

				WP_MCP_AI_Logger::log_error(
					'PayHere returned an error response for token request.',
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
					__( 'No access token in PayHere response.', 'mcp-ai-wpoos' ),
					array( 'body' => $decoded )
				);
			}

			WP_MCP_AI_Logger::log_event( 'payhere_token_response', 'PayHere access token obtained successfully.' );

			return $decoded['access_token'];
		}

		/**
		 * Retrieve payment details for a specific order ID.
		 *
		 * @param string $order_id PayHere order ID.
		 * @param array  $options  Additional options (timeout).
		 * @return array|WP_Error Payment details or error.
		 */
		public function get_payment( $order_id, array $options = array() ) {
			$order_id = sanitize_text_field( $order_id );

			if ( empty( $order_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_order_id',
					__( 'An order ID must be supplied to retrieve payment details.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Get access token.
			$access_token = $this->get_access_token( $options );

			if ( is_wp_error( $access_token ) ) {
				return $access_token;
			}

			$url = trailingslashit( $this->get_api_endpoint() ) . $order_id;

			$request_args = array(
				'timeout' => isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			);

			WP_MCP_AI_Logger::log_event(
				'payhere_payment_request',
				'Retrieving payment details from PayHere.',
				array( 'order_id' => $order_id )
			);

			$response = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'PayHere payment retrieval request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The PayHere API request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'PayHere', 'mcp-ai-wpoos' )
				);
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode PayHere payment response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'PayHere returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				$error_message = isset( $decoded['msg'] ) ? $decoded['msg'] : __( 'Unexpected response from PayHere.', 'mcp-ai-wpoos' );

				WP_MCP_AI_Logger::log_error(
					'PayHere returned an error response for payment retrieval.',
					array(
						'code' => $code,
						'body' => $decoded,
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

			// Check PayHere API status field.
			if ( ! isset( $decoded['status'] ) || 1 !== $decoded['status'] ) {
				$error_message = isset( $decoded['msg'] ) ? $decoded['msg'] : __( 'PayHere API returned unsuccessful status.', 'mcp-ai-wpoos' );

				return new WP_Error(
					'wp_mcp_ai_payment_retrieval_failed',
					$error_message,
					array( 'body' => $decoded )
				);
			}

			WP_MCP_AI_Logger::log_event( 'payhere_payment_response', 'PayHere payment details retrieved successfully.' );

			return $this->normalize_payment_response( $decoded );
		}

		/**
		 * Normalize the payment response from PayHere API.
		 *
		 * @param array $response Raw API response.
		 * @return array Normalized payment data.
		 */
		protected function normalize_payment_response( $response ) {
			$result = array(
				'status'  => isset( $response['status'] ) ? $response['status'] : null,
				'message' => isset( $response['msg'] ) ? $response['msg'] : '',
			);

			if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
				$result['payments'] = array();

				foreach ( $response['data'] as $payment ) {
					$normalized_payment = array(
						'payment_id'  => isset( $payment['payment_id'] ) ? $payment['payment_id'] : null,
						'order_id'    => isset( $payment['order_id'] ) ? $payment['order_id'] : '',
						'date'        => isset( $payment['date'] ) ? $payment['date'] : '',
						'description' => isset( $payment['description'] ) ? $payment['description'] : '',
						'status'      => isset( $payment['status'] ) ? $payment['status'] : '',
						'currency'    => isset( $payment['currency'] ) ? $payment['currency'] : '',
						'amount'      => isset( $payment['amount'] ) ? floatval( $payment['amount'] ) : 0.0,
					);

					// Add customer details if available.
					if ( isset( $payment['customer'] ) && is_array( $payment['customer'] ) ) {
						$normalized_payment['customer'] = array(
							'first_name' => isset( $payment['customer']['first_name'] ) ? $payment['customer']['first_name'] : '',
							'last_name'  => isset( $payment['customer']['last_name'] ) ? $payment['customer']['last_name'] : '',
							'email'      => isset( $payment['customer']['email'] ) ? $payment['customer']['email'] : '',
							'phone'      => isset( $payment['customer']['phone'] ) ? $payment['customer']['phone'] : '',
						);

						// Add delivery details if available.
						if ( isset( $payment['customer']['delivery_details'] ) && is_array( $payment['customer']['delivery_details'] ) ) {
							$normalized_payment['customer']['delivery_details'] = $payment['customer']['delivery_details'];
						}
					}

					// Add amount details if available.
					if ( isset( $payment['amount_detail'] ) && is_array( $payment['amount_detail'] ) ) {
						$normalized_payment['amount_detail'] = array(
							'currency'      => isset( $payment['amount_detail']['currency'] ) ? $payment['amount_detail']['currency'] : '',
							'gross'         => isset( $payment['amount_detail']['gross'] ) ? floatval( $payment['amount_detail']['gross'] ) : 0.0,
							'fee'           => isset( $payment['amount_detail']['fee'] ) ? floatval( $payment['amount_detail']['fee'] ) : 0.0,
							'net'           => isset( $payment['amount_detail']['net'] ) ? floatval( $payment['amount_detail']['net'] ) : 0.0,
							'exchange_rate' => isset( $payment['amount_detail']['exchange_rate'] ) ? floatval( $payment['amount_detail']['exchange_rate'] ) : 1.0,
							'exchange_from' => isset( $payment['amount_detail']['exchange_from'] ) ? $payment['amount_detail']['exchange_from'] : '',
							'exchange_to'   => isset( $payment['amount_detail']['exchange_to'] ) ? $payment['amount_detail']['exchange_to'] : '',
						);
					}

					// Add payment method if available.
					if ( isset( $payment['payment_method'] ) && is_array( $payment['payment_method'] ) ) {
						$normalized_payment['payment_method'] = array(
							'method'             => isset( $payment['payment_method']['method'] ) ? $payment['payment_method']['method'] : '',
							'card_customer_name' => isset( $payment['payment_method']['card_customer_name'] ) ? $payment['payment_method']['card_customer_name'] : '',
							'card_no'            => isset( $payment['payment_method']['card_no'] ) ? $payment['payment_method']['card_no'] : '',
						);
					}

					// Add items if available.
					if ( isset( $payment['items'] ) ) {
						$normalized_payment['items'] = $payment['items'];
					}

					$result['payments'][] = $normalized_payment;
				}

				$result['count'] = count( $result['payments'] );
			}

			return $result;
		}
	}
}
